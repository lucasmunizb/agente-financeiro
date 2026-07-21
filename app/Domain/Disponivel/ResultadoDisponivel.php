<?php

declare(strict_types=1);

namespace App\Domain\Disponivel;

use App\Domain\Shared\Money;

/**
 * Resultado do cálculo do "disponível do mês" (doc 03 §4.5).
 *
 * Value object imutável com as duas visões: o disponível do mês corrente e o
 * total de cobranças já previstas para o mês seguinte.
 */
final class ResultadoDisponivel
{
    public function __construct(
        public readonly Money $disponivel,
        public readonly Money $previstoProximoMes,
    ) {}
}
