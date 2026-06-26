<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use App\Domain\Shared\Money;

/**
 * Pré-visualização de um gasto manual antes da confirmação (regra inviolável 7:
 * confirmação antes de persistir). Mostra o que SERÁ gravado e se há duplicidade.
 */
final class PreviaGastoManual
{
    /**
     * @param  array<int, ParcelaPrevia>  $parcelas
     */
    public function __construct(
        public readonly string $descricao,
        public readonly Money $valorTotal,
        public readonly string $origem,
        public readonly bool $ehDuplicado,
        public readonly array $parcelas,
    ) {
    }
}
