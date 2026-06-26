<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use RuntimeException;

/**
 * Token de vínculo inválido: inexistente, expirado ou já consumido (doc 06 §1).
 */
final class TokenInvalidoException extends RuntimeException
{
    public static function invalido(): self
    {
        return new self('Token de vínculo inválido, expirado ou já utilizado.');
    }
}
