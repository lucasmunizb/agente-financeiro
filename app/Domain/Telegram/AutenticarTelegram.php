<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Models\TelegramLink;
use App\Models\User;

/**
 * Autenticação do Telegram (doc 06 §1). Middleware em TODA mensagem: resolve o
 * telegram_user_id para o usuário do vínculo ativo. Sem vínculo ativo, nenhuma
 * mensagem é processada.
 */
final class AutenticarTelegram
{
    /**
     * Usuário do vínculo ativo, ou null se não houver.
     */
    public function resolver(int $telegramUserId): ?User
    {
        $link = TelegramLink::where('telegram_user_id', $telegramUserId)
            ->where('status', TelegramLink::ATIVO)
            ->first();

        return $link?->user;
    }

    /**
     * Usuário do vínculo ativo; lança se não houver (uso no middleware).
     */
    public function usuario(int $telegramUserId): User
    {
        return $this->resolver($telegramUserId) ?? throw VinculoInexistenteException::semVinculo();
    }
}
