<?php

declare(strict_types=1);

namespace App\Domain\Parcelamento;

use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;

/**
 * Uma parcela do plano de parcelamento (doc 03 §4.1).
 *
 * Value object imutável. O `valor` é DERIVADO do total da compra (nunca duplicado
 * no banco) e o `numero` representa a estrutura N/total — a "parcela vigente" é
 * calculada na exibição por {@see GeradorDeParcelas::parcelaVigente()}, jamais fixada.
 */
final class Parcela
{
    public function __construct(
        public readonly int $numero,
        public readonly int $total,
        public readonly CarbonImmutable $vencimento,
        public readonly Money $valor,
    ) {}

    /**
     * Rótulo de exibição "n/total" (ex.: "2/3"). Formatação só na borda.
     */
    public function rotulo(): string
    {
        return "{$this->numero}/{$this->total}";
    }
}
