<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Models\AuditLog;
use App\Models\Recurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Encerra uma recorrência (spec 10). Recupera ESCOPADA por usuário (findOrFail → 404 para
 * item alheio) e, se ainda `ativo`, marca `cancelado` e zera o ponteiro `proxima_em` (deixa
 * de materializar); registra auditoria. Idempotente: uma recorrência já cancelada devolve
 * `false` (nada a fazer). Também é chamada pela cascata "rejeitar → cancela" (C7).
 */
final class CancelarRecorrencia
{
    public function cancelar(int $id, int $userId, CarbonImmutable $agora): bool
    {
        return DB::transaction(function () use ($id, $userId): bool {
            /** @var Recurrence $recorrencia */
            $recorrencia = Recurrence::where('user_id', $userId)
                ->lockForUpdate()
                ->findOrFail($id);

            if ($recorrencia->status !== Recurrence::STATUS_ATIVO) {
                return false;
            }

            $recorrencia->update([
                'status' => Recurrence::STATUS_CANCELADO,
                'proxima_em' => null,
            ]);

            AuditLog::create([
                'user_id' => $userId,
                'entidade' => 'recurrence',
                'entidade_id' => $recorrencia->id,
                'acao' => AuditLog::ACAO_CANCELAR,
                'antes' => ['status' => Recurrence::STATUS_ATIVO],
                'depois' => ['status' => Recurrence::STATUS_CANCELADO],
                'origem' => 'recorrencia',
            ]);

            return true;
        });
    }
}
