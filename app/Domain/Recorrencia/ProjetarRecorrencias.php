<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Calendar\RelativeDate;
use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\Shared\OpaqueId;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use Carbon\CarbonImmutable;

/**
 * Camada de PREVISÃO de recorrências (spec 10b, revista pela spec 12) — projeção READ-ONLY
 * que, a partir do molde em `recurrences`, deriva as ocorrências de um mês que AINDA NÃO
 * FORAM GERADAS, para os quadros do dashboard e para o extrato. Não grava nada (regra 7):
 * só lê e calcula.
 *
 * FONTE ÚNICA POR COMPETÊNCIA (spec 12): a separação entre projeção e realidade deixou de ser
 * o ponteiro `proxima_em` e passou a ser um `NOT EXISTS` sobre `recurrence_occurrences`. Se a
 * competência já foi materializada, ela é servida por {@see ConsultarOcorrencias} e cai fora
 * daqui — sem depender de o ponteiro e a data do lançamento concordarem, que era exatamente a
 * origem da dupla contagem.
 *
 * Age do mês CORRENTE em diante (mês passado ⇒ vazio): retrato fechado só mostra o que
 * aconteceu. A competência é calculada por {@see CalcularOcorrencia} — em cartão ela é a da
 * FATURA, então o mês de origem projetado pode ser anterior ao mês exibido. Escopo ESTRITO por
 * `user_id`; valores em centavos (regra 5); "agora" injetado (regras 4 e 5).
 */
final class ProjetarRecorrencias
{
    public function __construct(
        private readonly CalcularOcorrencia $calcular = new CalcularOcorrencia,
    ) {}

    public function para(int $userId, string $mesAlvo, CarbonImmutable $agora): ResultadoProjecaoRecorrencias
    {
        $agoraSp = $agora->setTimezone(RelativeDate::TIMEZONE);

        // Mês passado é retrato fechado: só a ocorrência real conta (projetar ali inventaria
        // uma cobrança que o agendador nunca gerou).
        if ($mesAlvo < $agoraSp->format('Y-m')) {
            return $this->vazio($mesAlvo);
        }

        $recorrencias = Recurrence::query()
            ->where('user_id', $userId)
            ->where('status', Recurrence::STATUS_ATIVO)
            // Categoria/forma/cartão alimentam a linha e os filtros do extrato de mês futuro.
            ->with(['categoria', 'paymentMethod', 'card'])
            ->get();

        // Competências já materializadas deste usuário: o `NOT EXISTS` da fonte única. Uma
        // consulta só (o conjunto é pequeno) em vez de uma por recorrência.
        $materializadas = RecurrenceOccurrence::query()
            ->where('user_id', $userId)
            ->get(['recurrence_id', 'competencia'])
            ->map(fn (RecurrenceOccurrence $oc): string => $oc->recurrence_id.'|'.$oc->competencia)
            ->flip();

        $ocorrencias = [];
        $total = 0;

        foreach ($recorrencias as $recorrencia) {
            // Antes do começo da regra não há o que prever: `proxima_em` é o primeiro mês
            // ainda não gerado, então uma competência anterior a ele ou já é real (e cai no
            // NOT EXISTS abaixo) ou é anterior ao início da recorrência.
            if ($recorrencia->proxima_em === null || $recorrencia->proxima_em->format('Y-m') > $mesAlvo) {
                continue;
            }

            $calculada = $this->projetarCompetencia($recorrencia, $mesAlvo);

            if ($calculada === null || $materializadas->has($recorrencia->id.'|'.$mesAlvo)) {
                continue;
            }

            $cents = (int) $recorrencia->valor_cents;
            $total += $cents;

            $ocorrencias[] = [
                'descricao' => $recorrencia->descricao,
                'vencimento' => $calculada->vencimento->format('Y-m-d'),
                'cents' => $cents,
                'prevista' => true,
                'categoriaId' => $recorrencia->categoria_id,
                'categoria' => $recorrencia->categoria !== null
                    ? ['nome' => (string) $recorrencia->categoria->nome, 'cor' => $recorrencia->categoria->cor]
                    : null,
                'forma' => $recorrencia->paymentMethod?->tipo,
                'cartaoId' => $recorrencia->card_id,
                'cartaoDescricao' => $recorrencia->card?->descricao,
                'recorrente' => true,
                // Alvo da ação "marcar como paga" (spec 13, decisão 2026-07-21). A projeção não
                // existe no banco: o alvo é o MOLDE (id OPACO) mais a competência que esta linha
                // representa — o par que {@see MaterializarOcorrencia} transforma em ocorrência
                // real antes do pagamento. Cartão fica de fora: a fatura é quem quita (D3).
                'recorrenciaId' => OpaqueId::encode((int) $recorrencia->id),
                'competencia' => $mesAlvo,
                'pagavel' => $recorrencia->card_id === null,
            ];
        }

        usort($ocorrencias, fn (array $a, array $b): int => $a['vencimento'] <=> $b['vencimento']);

        return new ResultadoProjecaoRecorrencias(
            totalCents: $total,
            ocorrencias: $ocorrencias,
            trace: $this->trace($mesAlvo, count($ocorrencias)),
        );
    }

    /**
     * A ocorrência desta recorrência que CAI na competência-alvo, ou null se nenhuma cai.
     * Fora de cartão o mês de origem é a própria competência. Em cartão a competência é a da
     * fatura, que fica 1 ou 2 meses à frente da cobrança — então testa os meses de origem
     * candidatos para trás até achar o que aterrissa no mês pedido.
     */
    private function projetarCompetencia(Recurrence $recorrencia, string $mesAlvo): ?OcorrenciaCalculada
    {
        if ($recorrencia->card_id === null) {
            return $this->calcular->para($recorrencia, $mesAlvo);
        }

        $alvo = CarbonImmutable::createFromFormat('!Y-m-d', $mesAlvo.'-01', RelativeDate::TIMEZONE);

        // Uma fatura nunca vence mais de dois meses depois da compra (§4.2).
        for ($atras = 0; $atras <= 2; $atras++) {
            $calculada = $this->calcular->para($recorrencia, $alvo->subMonthsNoOverflow($atras)->format('Y-m'));

            if ($calculada->competencia === $mesAlvo) {
                return $calculada;
            }
        }

        return null;
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
