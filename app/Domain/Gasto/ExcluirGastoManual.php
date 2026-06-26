<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use App\Models\AuditLog;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Exclusão de gasto manual (F2 — CRUD). Exclusão LÓGICA (soft delete, LGPD,
 * doc 04): a linha sai das consultas normais mas é mantida no banco, preservando
 * a trilha de auditoria. Registra auditoria de exclusão.
 */
final class ExcluirGastoManual
{
    private const ORIGEM = 'manual';

    public function confirmar(int $transactionId, int $userId): void
    {
        $transaction = Transaction::where('user_id', $userId)->findOrFail($transactionId);

        DB::transaction(function () use ($transaction, $userId) {
            $transaction->delete();

            AuditLog::create([
                'user_id' => $userId,
                'entidade' => 'transaction',
                'entidade_id' => $transaction->id,
                'acao' => AuditLog::ACAO_EXCLUIR,
                'antes' => ['descricao' => $transaction->descricao, 'valor_total_cents' => $transaction->valor_total_cents],
                'depois' => null,
                'origem' => self::ORIGEM,
            ]);
        });
    }
}
