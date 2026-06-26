<?php

declare(strict_types=1);

namespace App\Domain\Orcamento;

/**
 * Consumo do mês: total e quebra por categoria (doc 08 §6).
 *
 * Value object imutável. `porCategoria` mapeia categoria_id => centavos; gastos
 * sem categoria ficam sob a chave especial {@see self::SEM_CATEGORIA}.
 */
final class ConsumoMensal
{
    /** Chave do balde de gastos sem categoria. */
    public const SEM_CATEGORIA = 0;

    /**
     * @param  array<int, int>  $porCategoria  categoria_id (ou SEM_CATEGORIA) => centavos
     */
    public function __construct(
        public readonly int $totalCents,
        public readonly array $porCategoria,
    ) {
    }

    public function semCategoriaCents(): int
    {
        return $this->porCategoria[self::SEM_CATEGORIA] ?? 0;
    }
}
