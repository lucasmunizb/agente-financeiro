<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use RuntimeException;

/**
 * Pagamento por parcela recusado por ser um lançamento EM CARTÃO. Cobrança de
 * cartão é quitada pelo pagamento da fatura (doc 03 §4.2/§4.3), não parcela a
 * parcela — logo "marcar como pago" só vale fora de cartão.
 */
final class PagamentoNaoPermitidoException extends RuntimeException
{
    public static function ehCartao(): self
    {
        return new self('Lançamento em cartão é quitado pela fatura — não é possível marcar a parcela como paga aqui.');
    }
}
