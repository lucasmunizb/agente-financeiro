<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Models\User;

/**
 * Ponto de extensão por intenção (doc 06 §2). O roteador classifica a mensagem e
 * entrega o ComandoRecebido ao manipulador registrado para aquela intenção. A
 * execução de cada comando — extração via IA + confirmação (Bloco 4) e as mensagens
 * formatadas do bot (frontend) — é implementada nas etapas posteriores; por ora os
 * manipuladores são inertes.
 */
interface ManipuladorDeComando
{
    public function manipular(User $user, ComandoRecebido $comando): void;
}
