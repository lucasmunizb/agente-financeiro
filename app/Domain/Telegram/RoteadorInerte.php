<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Models\User;

/**
 * Implementação default do roteador: não faz nada. Mantém o webhook funcional e
 * idempotente enquanto o roteamento de comandos e as mensagens do bot não foram
 * implementados (etapas posteriores do roadmap, Bloco 3/4).
 */
final class RoteadorInerte implements RoteadorDeMensagem
{
    public function autenticado(User $user, array $update): void
    {
        // no-op até o roteamento de comandos (TODO Bloco 3).
    }

    public function naoVinculado(int $telegramUserId, array $update): void
    {
        // no-op até o fluxo de vínculo via bot (frontend / etapa posterior).
    }
}
