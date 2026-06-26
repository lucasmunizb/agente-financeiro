<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use App\Models\AuditLog;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Cancelamento de gasto manual (F2 — CRUD). Marca a transaction como
 * 'cancelado' e cancela as parcelas ainda não finalizadas, MANTENDO a linha
 * (histórico preservado, doc 03 §4.4). Parcelas já pagas/parciais/estornadas
 * são preservadas. Registra auditoria.
 */
final class CancelarGastoManual
{
    private const ORIGEM = 'manual';

    /** Status finais que não devem ser sobrescritos pelo cancelamento. */
    private const PRESERVAR = [
        StatusPagamento::PAGO,
        StatusPagamento::PAGO_PARCIAL,
        StatusPagamento::ESTORNADO,
    ];

    public function confirmar(int $transactionId, int $userId): Transaction
    {
        $transaction = Transaction::where('user_id', $userId)->findOrFail($transactionId);
        $cancelado = StatusPagamento::idFor(StatusPagamento::CANCELADO);
        $preservar = StatusPagamento::whereIn('codigo', self::PRESERVAR)->pluck('id')->all();
        $antes = ['status_id' => $transaction->status_id];

        return DB::transaction(function () use ($transaction, $userId, $cancelado, $preservar, $antes) {
            $transaction->update(['status_id' => $cancelado]);

            $transaction->installments()
                ->whereNotIn('status_id', $preservar)
                ->update(['status_id' => $cancelado]);

            AuditLog::create([
                'user_id' => $userId,
                'entidade' => 'transaction',
                'entidade_id' => $transaction->id,
                'acao' => AuditLog::ACAO_CANCELAR,
                'antes' => $antes,
                'depois' => ['status_id' => $cancelado],
                'origem' => self::ORIGEM,
            ]);

            return $transaction->load('installments');
        });
    }
}
