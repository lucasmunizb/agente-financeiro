<?php

declare(strict_types=1);

namespace App\Domain\Cartao;

use App\Models\AuditLog;
use App\Models\Card;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Remove um cartão por CANCELAMENTO LÓGICO (spec FE §7.13). Soft delete: a linha e o histórico
 * ficam (LGPD — auditoria preservada), o cartão só some da listagem e libera a identidade
 * (índice parcial WHERE deleted_at IS NULL). Recupera ESCOPADO por usuário (findOrFail → 404
 * para item alheio) e audita antes de apagar. Os lançamentos já feitos no cartão permanecem.
 */
final class ExcluirCartao
{
    public function excluir(int $cardId, int $userId, CarbonImmutable $agora): void
    {
        DB::transaction(function () use ($cardId, $userId): void {
            /** @var Card $card */
            $card = Card::where('user_id', $userId)->lockForUpdate()->findOrFail($cardId);

            AuditLog::create([
                'user_id' => $userId,
                'entidade' => 'card',
                'entidade_id' => $card->id,
                'acao' => AuditLog::ACAO_EXCLUIR,
                'antes' => [
                    'descricao' => $card->descricao,
                    'final_4' => $card->final_4,
                ],
                'depois' => null,
                'origem' => 'manual',
            ]);

            $card->delete(); // soft delete (SoftDeletes)
        });
    }
}
