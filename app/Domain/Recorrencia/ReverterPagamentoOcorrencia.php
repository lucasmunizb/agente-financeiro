<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Gasto\PagamentoNaoPermitidoException;
use App\Models\AuditLog;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use Illuminate\Support\Facades\DB;

/**
 * Desfaz a MARCAÇÃO de pagamento de uma ocorrência de recorrência (decisão do usuário
 * 2026-07-21) — inverso de {@see PagarOcorrencia}.
 *
 * Devolve a ocorrência para `aberto` e apaga a `data_pagamento`; nada mais existe para
 * desfazer, porque pagar uma ocorrência nunca materializou lançamento algum (spec 12).
 *
 * Exclusivo de FORA DE CARTÃO (R10), pelo mesmo motivo do pagamento: a cobrança em cartão
 * liquida sozinha na data de cobrança ({@see LiquidarOcorrenciasDeCartao}, D3), então
 * reabri-la aqui só produziria um vaivém — o agendador a marcaria paga de novo. Cancelada
 * também não reabre (§4.4): não é cobrança. Escopo ESTRITO por usuário (404 para ocorrência
 * alheia) e idempotente — desmarcar o que não está pago devolve `null`, sem auditar.
 */
final class ReverterPagamentoOcorrencia
{
    private const ORIGEM = 'recorrencia';

    public function reverter(int $ocorrenciaId, int $userId): ?RecurrenceOccurrence
    {
        return DB::transaction(function () use ($ocorrenciaId, $userId): ?RecurrenceOccurrence {
            /** @var RecurrenceOccurrence $ocorrencia */
            $ocorrencia = RecurrenceOccurrence::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->findOrFail($ocorrenciaId);

            if ($ocorrencia->ehCartao()) {
                throw PagamentoNaoPermitidoException::ehCartao();
            }

            // Só o que está PAGO se desfaz: `aberto` já é o destino e `cancelado` não reabre.
            if ($ocorrencia->status_id !== StatusPagamento::idFor(StatusPagamento::PAGO)) {
                return null;
            }

            $aberto = StatusPagamento::idFor(StatusPagamento::ABERTO);
            $antes = [
                'status_id' => $ocorrencia->status_id,
                'data_pagamento' => $ocorrencia->data_pagamento?->toIso8601String(),
            ];

            $ocorrencia->update([
                'status_id' => $aberto,
                'data_pagamento' => null,
            ]);

            AuditLog::create([
                'user_id' => $userId,
                'entidade' => 'recurrence_occurrence',
                'entidade_id' => $ocorrencia->id,
                'acao' => AuditLog::ACAO_DESMARCAR_PAGAMENTO,
                'antes' => $antes,
                'depois' => ['status_id' => $aberto, 'data_pagamento' => null],
                'origem' => self::ORIGEM,
            ]);

            return $ocorrencia->refresh();
        });
    }
}
