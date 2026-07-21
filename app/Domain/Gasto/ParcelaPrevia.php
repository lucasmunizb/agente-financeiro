<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;

/**
 * Uma parcela na pré-visualização do cadastro: estrutura N/total + vencimento +
 * valor derivado + status derivado pela data. Não persistida até a confirmação.
 */
final class ParcelaPrevia
{
    public function __construct(
        public readonly int $numero,
        public readonly int $total,
        public readonly CarbonImmutable $vencimento,
        public readonly Money $valor,
        public readonly string $statusCodigo,
    ) {}
}
