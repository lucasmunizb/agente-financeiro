<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use RuntimeException;

/**
 * Edição recusada por haver parcela já finalizada (paga/parcial), o que faria
 * perder histórico de pagamento ao regenerar as parcelas.
 */
final class EdicaoBloqueadaException extends RuntimeException
{
    public static function parcelaPaga(): self
    {
        return new self('Não é possível editar: há parcela já paga.');
    }

    public static function cancelado(): self
    {
        return new self('Não é possível editar um lançamento cancelado.');
    }
}
