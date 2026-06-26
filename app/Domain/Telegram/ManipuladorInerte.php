<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Models\User;

/**
 * Manipulador default: não faz nada. Mantém o roteamento completo e testável
 * enquanto a execução dos comandos (extração via IA / confirmação no Bloco 4) e as
 * mensagens do bot (frontend) não foram implementadas. Cada intenção recebe este
 * inerte até ganhar seu manipulador concreto.
 */
final class ManipuladorInerte implements ManipuladorDeComando
{
    public function manipular(User $user, ComandoRecebido $comando): void
    {
        // no-op até a execução do comando (Bloco 4 / frontend).
    }
}
