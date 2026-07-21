<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Calendar\RelativeDate;
use App\Models\AuditLog;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Encerra uma recorrência (spec 10, revista pela spec 12). Recupera ESCOPADA por usuário
 * (findOrFail → 404 para item alheio) e, se ainda `ativo`, marca `cancelado` e zera o ponteiro
 * `proxima_em` (deixa de gerar ocorrências); registra auditoria.
 *
 * Cancelar vale para o FUTURO (R13): as ocorrências ainda em aberto de competências
 * posteriores à corrente viram `cancelado` — não se cobra o que foi cancelado antes de vencer.
 * As passadas ficam como estão: uma conta que já venceu (paga ou não) é história e continua no
 * extrato. Idempotente: uma recorrência já cancelada devolve `false` (nada a fazer).
 */
final class CancelarRecorrencia
{
    public function cancelar(int $id, int $userId, CarbonImmutable $agora): bool
    {
        $mesCorrente = $agora->setTimezone(RelativeDate::TIMEZONE)->format('Y-m');

        return DB::transaction(function () use ($id, $userId, $mesCorrente): bool {
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

            // Competências futuras ainda em aberto deixam de ser cobrança (o passado é história).
            RecurrenceOccurrence::query()
                ->where('recurrence_id', $recorrencia->id)
                ->where('user_id', $userId)
                ->where('competencia', '>', $mesCorrente)
                ->where('status_id', StatusPagamento::idFor(StatusPagamento::ABERTO))
                ->update(['status_id' => StatusPagamento::idFor(StatusPagamento::CANCELADO)]);

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
