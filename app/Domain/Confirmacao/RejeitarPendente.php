<?php

declare(strict_types=1);

namespace App\Domain\Confirmacao;

use App\Models\AuditLog;
use App\Models\PendingConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Não" da fila (FE §7.9): descarta um pendente sem gravar lançamento algum. Recupera
 * ESCOPADO por usuário (findOrFail → 404 para item alheio/inexistente) e, se ainda pendente,
 * marca `rejeitado` (mantém a linha para auditoria/histórico — nada é apagado) e registra a
 * trilha. Devolve false quando já estava resolvido (nada a fazer).
 *
 * A cascata "rejeitar → cancela a recorrência" (spec 10, C7) foi REMOVIDA pela spec 12 (D1):
 * recorrência não passa mais pela fila — ela gera a ocorrência do mês direto, e a regra 7 é
 * honrada uma única vez, no cadastro do molde.
 */
final class RejeitarPendente
{
    public function rejeitar(int $id, int $userId, CarbonImmutable $agora): bool
    {
        return DB::transaction(function () use ($id, $userId, $agora): bool {
            /** @var PendingConfirmation $pendente */
            $pendente = PendingConfirmation::where('user_id', $userId)
                ->lockForUpdate()
                ->findOrFail($id);

            if ($pendente->status !== PendingConfirmation::STATUS_PENDENTE) {
                return false;
            }

            $pendente->update([
                'status' => PendingConfirmation::STATUS_REJEITADO,
                // timestamptz: converter a UTC antes de gravar, senão o instante corrompe.
                'resolvido_em' => $agora->setTimezone('UTC'),
            ]);

            AuditLog::create([
                'user_id' => $userId,
                'entidade' => 'pending_confirmation',
                'entidade_id' => $pendente->id,
                'acao' => AuditLog::ACAO_REJEITAR,
                'antes' => ['status' => PendingConfirmation::STATUS_PENDENTE],
                'depois' => ['status' => PendingConfirmation::STATUS_REJEITADO],
                'origem' => $pendente->origem,
            ]);

            return true;
        });
    }
}
