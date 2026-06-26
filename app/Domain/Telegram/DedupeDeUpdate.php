<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Models\TelegramUpdate;

/**
 * Deduplicação por update_id (doc 06 §2/§3). Usa INSERT ... ON CONFLICT DO
 * NOTHING (insertOrIgnore): a unique constraint garante idempotência mesmo sob
 * concorrência, sem lançar (nem abortar transação). Em reentrega (update_id
 * repetido), retorna false — mantém a primeira mensagem.
 */
final class DedupeDeUpdate
{
    /**
     * @return bool true se é a primeira vez que este update_id aparece.
     */
    public function ehNovo(int $updateId): bool
    {
        return TelegramUpdate::insertOrIgnore(['update_id' => $updateId]) === 1;
    }
}
