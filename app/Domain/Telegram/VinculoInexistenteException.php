<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use RuntimeException;

/**
 * Mensagem recebida de um telegram_user_id sem vínculo ativo (doc 06 §1):
 * nenhum comando é processado sem usuário identificado.
 */
final class VinculoInexistenteException extends RuntimeException
{
    public static function semVinculo(): self
    {
        return new self('Nenhum vínculo ativo para este usuário do Telegram.');
    }
}
