<?php

declare(strict_types=1);

namespace App\Domain\Orcamento;

use App\Domain\Shared\Money;

/**
 * Resultado da avaliação do orçamento mensal geral (doc 08 §6).
 *
 * Value object imutável: limite, consumido e restante (em centavos) e se o
 * consumo estourou o limite. O percentual é razão (não é dinheiro) — exposto só
 * para apresentação/alerta futuro; sem disparo no MVP (decisão de escopo).
 */
final class ResultadoOrcamento
{
    public function __construct(
        public readonly Money $limite,
        public readonly Money $consumido,
        public readonly Money $restante,
        public readonly bool $estourou,
    ) {}

    public function percentual(): float
    {
        if ($this->limite->cents() <= 0) {
            return 0.0;
        }

        return $this->consumido->cents() / $this->limite->cents() * 100;
    }
}
