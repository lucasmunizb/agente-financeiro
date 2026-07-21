<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Calendar\RelativeDate;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Motor do comando agendado (spec 12) — substitui o antigo `MaterializarRecorrencias`.
 *
 * Para cada recorrência `ativo` cujo ponteiro já chegou, gera TODAS as competências faltantes
 * até o mês corrente (recupera um agendador parado, R5) e NUNCA escreve em
 * `transactions`/`installments`: a conta fixa de um mês é a {@see RecurrenceOccurrence} dele.
 * Também não enfileira confirmação (D1) — a regra 7 foi honrada uma vez, no cadastro do molde.
 *
 * `proxima_em` é o 1º dia do primeiro MÊS DE ORIGEM ainda não gerado (o mês em que o dia do
 * molde cai). Fora de cartão ele coincide com a competência; em cartão a competência é a da
 * fatura e pode ficar meses à frente ({@see CalcularOcorrencia}) — por isso o ponteiro segue o
 * mês de origem, e a anti-duplicação real é a UNIQUE `(recurrence_id, competencia)`.
 *
 * Concorrência: cada recorrência é reprocessada SOB LOCK dentro da transação (o scheduler pode
 * rodar em mais de um worker). Idempotência não depende disso — a unique parcial é a garantia
 * final, e um conflito é simplesmente ignorado. "Hoje" é injetado (regras 4 e 5).
 */
final class GerarOcorrencias
{
    /** Trava de sanidade: um ponteiro corrompido não pode virar um laço infinito. */
    private const MAX_COMPETENCIAS = 240;

    public function __construct(
        private readonly CalcularOcorrencia $calcular = new CalcularOcorrencia,
        private readonly LiquidarOcorrenciasDeCartao $liquidar = new LiquidarOcorrenciasDeCartao,
    ) {}

    /** @return int quantas ocorrências nasceram nesta execução */
    public function paraTodos(CarbonImmutable $hoje): int
    {
        $mesCorrente = $hoje->setTimezone(RelativeDate::TIMEZONE)->startOfMonth();

        $recorrencias = Recurrence::query()
            ->where('status', Recurrence::STATUS_ATIVO)
            ->whereNotNull('proxima_em')
            ->whereDate('proxima_em', '<=', $mesCorrente->toDateString())
            ->pluck('id');

        $geradas = 0;

        foreach ($recorrencias as $id) {
            $geradas += $this->paraRecorrencia($id, $hoje);
        }

        return $geradas;
    }

    /**
     * Gera as competências faltantes de UMA recorrência, do ponteiro até o mês corrente
     * (inclusive), e avança o ponteiro para o mês seguinte. Re-lê a linha SOB LOCK e
     * re-verifica status/ponteiro: outra execução pode ter passado na frente.
     */
    public function paraRecorrencia(int $recorrenciaId, CarbonImmutable $hoje): int
    {
        $mesCorrente = $hoje->setTimezone(RelativeDate::TIMEZONE)->startOfMonth();

        return DB::transaction(function () use ($recorrenciaId, $mesCorrente, $hoje): int {
            /** @var Recurrence|null $rec */
            $rec = Recurrence::query()
                ->whereKey($recorrenciaId)
                ->where('status', Recurrence::STATUS_ATIVO)
                ->whereNotNull('proxima_em')
                ->with('card')
                ->lockForUpdate()
                ->first();

            if ($rec === null || $rec->proxima_em->startOfMonth()->greaterThan($mesCorrente)) {
                return 0;
            }

            $cursor = $rec->proxima_em->startOfMonth();
            $geradas = 0;
            $voltas = 0;

            while ($cursor->lessThanOrEqualTo($mesCorrente) && $voltas++ < self::MAX_COMPETENCIAS) {
                if ($this->paraMes($rec, $cursor->format('Y-m'), $hoje) !== null) {
                    $geradas++;
                }

                $cursor = $cursor->addMonthNoOverflow();
            }

            $rec->update(['proxima_em' => $cursor->toDateString()]);

            return $geradas;
        });
    }

    /**
     * Gera a ocorrência de UM mês de origem, com o snapshot do molde. Devolve `null` quando a
     * competência já tinha ocorrência (idempotência — a unique parcial é quem decide, então
     * duas execuções simultâneas convergem). Cartão cuja cobrança já passou nasce `pago` (D3),
     * pela mesma regra do agendador ({@see LiquidarOcorrenciasDeCartao}).
     */
    public function paraMes(Recurrence $rec, string $mesOrigem, CarbonImmutable $hoje): ?RecurrenceOccurrence
    {
        $calculada = $this->calcular->para($rec, $mesOrigem);

        $jaExiste = RecurrenceOccurrence::query()
            ->where('recurrence_id', $rec->id)
            ->where('competencia', $calculada->competencia)
            ->exists();

        if ($jaExiste) {
            return null;
        }

        try {
            $ocorrencia = RecurrenceOccurrence::create([
                'user_id' => $rec->user_id,
                'recurrence_id' => $rec->id,
                'competencia' => $calculada->competencia,
                // Snapshot do molde: editar a recorrência depois não reescreve o passado.
                'descricao' => $rec->descricao,
                'valor_cents' => $rec->valor_cents,
                'data_cobranca' => $calculada->dataCobranca->toDateString(),
                'vencimento' => $calculada->vencimento->toDateString(),
                'payment_method_id' => $rec->payment_method_id,
                'card_id' => $rec->card_id,
                'categoria_id' => $rec->categoria_id,
                'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
                'data_pagamento' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Corrida perdida: a outra execução já gerou esta competência. Nada a fazer.
            return null;
        }

        $this->liquidar->paraOcorrencia($ocorrencia, $hoje);

        return $ocorrencia->refresh();
    }
}
