<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Calendar\RelativeDate;
use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Models\Recurrence;
use Carbon\CarbonImmutable;

/**
 * Camada de PREVISÃO de recorrências (spec 10b) — projeção READ-ONLY que, a partir do molde em
 * `recurrences`, deriva as ocorrências AINDA NÃO MATERIALIZADAS de um mês, para os quadros do
 * dashboard e para o extrato.
 *
 * Coexiste com a materialização just-in-time (spec 10 §10): NÃO grava lançamento nem enfileira
 * confirmação (regra 7) — só lê e calcula. Age do mês CORRENTE em diante (mês passado ⇒ vazio,
 * comparação de YYYY-MM no fuso SP a partir do "agora" injetado, determinismo/regra 4/5).
 *
 * Projeta uma ocorrência por recorrência `ativo` cujo ponteiro ainda não passou do mês-alvo
 * (`proxima_em <= fim do mês-alvo`), com o `dia` resolvido/clampado NAQUELE mês via
 * {@see OcorrenciaMensal}. Escopo ESTRITO por `user_id`. Valores em centavos (regra 5).
 *
 * ANTI-DUPLA-CONTAGEM: o filtro por `proxima_em` é o que separa esta projeção da fila e dos
 * lançamentos reais — {@see MaterializarRecorrencias} AVANÇA o ponteiro no mesmo instante (e na
 * mesma transação) em que enfileira a ocorrência. Logo, uma ocorrência já materializada tem o
 * ponteiro além do mês e cai fora desta query: ela é servida pela fila
 * ({@see ProjetarRecorrenciasPendentes}) até ser confirmada, e pela parcela real depois disso.
 * As três fontes são disjuntas por construção — não por um guard de calendário.
 */
final class ProjetarRecorrencias
{
    public function para(int $userId, string $mesAlvo, CarbonImmutable $agora): ResultadoProjecaoRecorrencias
    {
        $agoraSp = $agora->setTimezone(RelativeDate::TIMEZONE);
        $inicioMesAlvo = CarbonImmutable::createFromFormat('!Y-m-d', $mesAlvo.'-01', RelativeDate::TIMEZONE);

        // Mês passado é retrato fechado: só lançamento real conta (e um ponteiro atrasado ali só
        // existe se o agendador falhou — projetar seria inventar um gasto que ninguém confirmou).
        if ($mesAlvo < $agoraSp->format('Y-m')) {
            return $this->vazio($mesAlvo);
        }

        $recorrencias = Recurrence::query()
            ->where('user_id', $userId)
            ->where('status', Recurrence::STATUS_ATIVO)
            ->whereNotNull('proxima_em')
            ->whereDate('proxima_em', '<=', $inicioMesAlvo->endOfMonth()->toDateString())
            // Categoria/forma alimentam a linha e os filtros do extrato de mês futuro (F10).
            ->with(['categoria', 'paymentMethod'])
            ->get();

        $ocorrencias = [];
        $total = 0;

        foreach ($recorrencias as $recorrencia) {
            $vencimento = OcorrenciaMensal::aPartirDe($recorrencia->dia, $inicioMesAlvo);
            $cents = (int) $recorrencia->valor_cents;
            $total += $cents;

            $ocorrencias[] = [
                'descricao' => $recorrencia->descricao,
                'vencimento' => $vencimento->format('Y-m-d'),
                'cents' => $cents,
                'prevista' => true,
                // Enriquecimento para o extrato (o dashboard ignora estas chaves extras).
                'categoriaId' => $recorrencia->categoria_id,
                'categoria' => $recorrencia->categoria !== null
                    ? ['nome' => (string) $recorrencia->categoria->nome, 'cor' => $recorrencia->categoria->cor]
                    : null,
                'forma' => $recorrencia->paymentMethod?->tipo,
            ];
        }

        usort($ocorrencias, fn (array $a, array $b): int => $a['vencimento'] <=> $b['vencimento']);

        return new ResultadoProjecaoRecorrencias(
            totalCents: $total,
            ocorrencias: $ocorrencias,
            trace: $this->trace($mesAlvo, count($ocorrencias)),
        );
    }

    private function vazio(string $mesAlvo): ResultadoProjecaoRecorrencias
    {
        return new ResultadoProjecaoRecorrencias(0, [], $this->trace($mesAlvo, 0));
    }

    private function trace(string $mesAlvo, int $registros): TraceDaConsulta
    {
        return new TraceDaConsulta(
            ferramenta: 'projetar_recorrencias',
            filtros: ['mes' => $mesAlvo],
            registros: $registros,
        );
    }
}
