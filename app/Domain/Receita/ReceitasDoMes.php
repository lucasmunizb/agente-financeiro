<?php

declare(strict_types=1);

namespace App\Domain\Receita;

use App\Domain\Shared\PeriodoMensal;
use App\Models\Income;

/**
 * Soma determinística das receitas recebidas no mês (doc 03 §4.5).
 *
 * Escopo estrito por usuário; conta apenas o que foi recebido dentro do mês civil.
 * Building block do "disponível do mês" — a fiação completa do disponível é da
 * camada de consulta (etapa posterior).
 */
final class ReceitasDoMes
{
    public function para(int $userId, string $mes): int
    {
        $periodo = PeriodoMensal::fromString($mes);

        return (int) Income::query()
            ->where('user_id', $userId)
            ->whereBetween('data', [$periodo->inicio->toDateString(), $periodo->fim->toDateString()])
            ->sum('valor_cents');
    }
}
