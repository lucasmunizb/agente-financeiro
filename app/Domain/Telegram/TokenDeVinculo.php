<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use Carbon\CarbonImmutable;

/**
 * Token de vínculo em claro (doc 06 §1), entregue à web para o usuário enviar ao
 * bot. Só existe em memória: no banco guarda-se apenas o hash. Imutável.
 */
final class TokenDeVinculo
{
    public function __construct(
        public readonly string $token,
        public readonly CarbonImmutable $expiraEm,
    ) {
    }
}
