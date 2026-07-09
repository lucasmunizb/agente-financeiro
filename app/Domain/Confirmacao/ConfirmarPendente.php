<?php

declare(strict_types=1);

namespace App\Domain\Confirmacao;

use App\Domain\Gasto\RegistrarGastoManual;
use App\Models\AuditLog;
use App\Models\PendingConfirmation;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Materializa o "sim" da fila (FE §7.9 / regra 7). Recupera o pendente ESCOPADO por usuário
 * (findOrFail → 404 para item alheio/inexistente — não vaza existência) e, se ainda confirmável
 * (status `pendente` e não expirado), REUSA {@see RegistrarGastoManual::confirmar()} para gravar
 * o lançamento — sem recalcular nada (regra 4). Resolve o pendente na MESMA transação (uso
 * único: um segundo "sim" encontra status != pendente e devolve null — idempotente). Liga o
 * `transaction_id` para rastreio e registra auditoria.
 */
final class ConfirmarPendente
{
    public function __construct(
        private readonly RegistrarGastoManual $registrar = new RegistrarGastoManual,
    ) {}

    public function confirmar(int $id, int $userId, CarbonImmutable $agora): ?Transaction
    {
        return DB::transaction(function () use ($id, $userId, $agora): ?Transaction {
            /** @var PendingConfirmation $pendente */
            $pendente = PendingConfirmation::where('user_id', $userId)
                ->lockForUpdate()
                ->findOrFail($id);

            if (! $this->confirmavel($pendente, $agora)) {
                return null;
            }

            $dados = PayloadDoGasto::paraDados($pendente->payload, $userId);
            $transaction = $this->registrar->confirmar($dados, $agora);

            $pendente->update([
                'status' => PendingConfirmation::STATUS_CONFIRMADO,
                'transaction_id' => $transaction->id,
                'resolvido_em' => $agora,
            ]);

            AuditLog::create([
                'user_id' => $userId,
                'entidade' => 'pending_confirmation',
                'entidade_id' => $pendente->id,
                'acao' => AuditLog::ACAO_CONFIRMAR,
                'antes' => ['status' => PendingConfirmation::STATUS_PENDENTE],
                'depois' => ['status' => PendingConfirmation::STATUS_CONFIRMADO, 'transaction_id' => $transaction->id],
                'origem' => $pendente->origem,
            ]);

            return $transaction;
        });
    }

    private function confirmavel(PendingConfirmation $pendente, CarbonImmutable $agora): bool
    {
        if ($pendente->status !== PendingConfirmation::STATUS_PENDENTE) {
            return false;
        }

        return $pendente->expira_em === null || $pendente->expira_em->greaterThan($agora);
    }
}
