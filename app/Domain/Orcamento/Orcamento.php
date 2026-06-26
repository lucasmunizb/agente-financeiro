<?php

declare(strict_types=1);

namespace App\Domain\Orcamento;

use App\Domain\Shared\Money;

/**
 * Avaliação determinística do orçamento mensal geral (doc 08 §6 / doc 04).
 *
 * Puro: recebe limite e consumido (em centavos) e devolve o {@see ResultadoOrcamento}.
 * Estoura quando o consumo passa do limite (igualar o limite NÃO estoura). Sem IA.
 */
final class Orcamento
{
    public static function avaliar(int $limiteCents, int $consumidoCents): ResultadoOrcamento
    {
        $limite = Money::fromCents($limiteCents);
        $consumido = Money::fromCents($consumidoCents);

        return new ResultadoOrcamento(
            limite: $limite,
            consumido: $consumido,
            restante: $limite->minus($consumido),
            estourou: $consumidoCents > $limiteCents,
        );
    }
}
