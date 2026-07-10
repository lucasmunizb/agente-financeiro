<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Calendar\RelativeDate;
use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Models\Recurrence;
use Carbon\CarbonImmutable;

/**
 * Camada de PREVISÃO de recorrências (spec 10b) — projeção READ-ONLY que, a partir do molde em
 * `recurrences`, deriva as ocorrências de um mês FUTURO para a visão de mês futuro do dashboard.
 *
 * Coexiste com a materialização just-in-time (spec 10 §10): NÃO grava lançamento nem enfileira
 * confirmação (regra 7) — só lê e calcula. Age APENAS em meses estritamente futuros
 * (`mesAlvo > mesCorrente`, comparação de YYYY-MM no fuso SP a partir do "agora" injetado):
 * o mês corrente é servido pela fila just-in-time e os passados pelos lançamentos reais, então
 * uma ocorrência nunca é contada duas vezes. "Agora" é INJETADO (determinismo, regra 4/5).
 *
 * Projeta uma ocorrência por recorrência `ativo` que já COMEÇOU até o mês-alvo
 * (`proxima_em <= fim do mês-alvo`), com o `dia` resolvido/clampado NAQUELE mês via
 * {@see OcorrenciaMensal}. Escopo ESTRITO por `user_id`. Valores em centavos (regra 5).
 */
final class ProjetarRecorrencias
{
    public function para(int $userId, string $mesAlvo, CarbonImmutable $agora): ResultadoProjecaoRecorrencias
    {
        $agoraSp = $agora->setTimezone(RelativeDate::TIMEZONE);
        $inicioMesAlvo = CarbonImmutable::createFromFormat('!Y-m-d', $mesAlvo.'-01', RelativeDate::TIMEZONE);

        // Só meses estritamente futuros (anti-dupla-contagem): mês corrente/passado ⇒ vazio.
        if ($mesAlvo <= $agoraSp->format('Y-m')) {
            return $this->vazio($mesAlvo);
        }

        $recorrencias = Recurrence::query()
            ->where('user_id', $userId)
            ->where('status', Recurrence::STATUS_ATIVO)
            ->whereNotNull('proxima_em')
            ->whereDate('proxima_em', '<=', $inicioMesAlvo->endOfMonth()->toDateString())
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
