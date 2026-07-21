<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Gasto\PagamentoNaoPermitidoException;
use App\Models\AuditLog;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Marcar como paga" uma ocorrência de recorrência (spec 12) — substitui o antigo
 * `PagarRecorrenciaPendente`, que compunha fila + lançamento. Agora a ocorrência JÁ existe:
 * pagar é só mudar o status dela, sem materializar nada.
 *
 * Exclusivo de FORA DE CARTÃO (R10): cobrança de cartão liquida sozinha pela data de cobrança
 * ({@see LiquidarOcorrenciasDeCartao}, D3) — oferecer o botão ali seria pagar duas vezes a
 * mesma conta. Escopo ESTRITO por usuário (404 para ocorrência alheia, R12) e idempotente: um
 * segundo clique acha a ocorrência já paga e devolve `null`, sem tocar na `data_pagamento`
 * original. Registra auditoria. "Agora" é injetado (regras 4 e 5).
 */
final class PagarOcorrencia
{
    private const ORIGEM = 'recorrencia';

    public function pagar(int $ocorrenciaId, int $userId, CarbonImmutable $agora): ?RecurrenceOccurrence
    {
        return DB::transaction(function () use ($ocorrenciaId, $userId, $agora): ?RecurrenceOccurrence {
            /** @var RecurrenceOccurrence $ocorrencia */
            $ocorrencia = RecurrenceOccurrence::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->findOrFail($ocorrenciaId);

            if ($ocorrencia->ehCartao()) {
                throw PagamentoNaoPermitidoException::ehCartao();
            }

            $pago = StatusPagamento::idFor(StatusPagamento::PAGO);
            $cancelado = StatusPagamento::idFor(StatusPagamento::CANCELADO);

            // Já paga (idempotência) ou cancelada: nada a fazer — não reabre nem repaga.
            if ($ocorrencia->status_id === $pago || $ocorrencia->status_id === $cancelado) {
                return null;
            }

            $antes = ['status_id' => $ocorrencia->status_id, 'data_pagamento' => null];

            $ocorrencia->update([
                'status_id' => $pago,
                // timestamptz: converte para UTC antes de gravar (o driver descarta o offset).
                'data_pagamento' => $agora->setTimezone('UTC'),
            ]);

            AuditLog::create([
                'user_id' => $userId,
                'entidade' => 'recurrence_occurrence',
                'entidade_id' => $ocorrencia->id,
                'acao' => AuditLog::ACAO_PAGAR,
                'antes' => $antes,
                'depois' => ['status_id' => $pago, 'data_pagamento' => $agora->toIso8601String()],
                'origem' => self::ORIGEM,
            ]);

            return $ocorrencia->refresh();
        });
    }
}
