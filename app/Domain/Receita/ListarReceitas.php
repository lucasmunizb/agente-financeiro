<?php

declare(strict_types=1);

namespace App\Domain\Receita;

use App\Domain\Shared\PeriodoMensal;
use App\Models\Income;
use Illuminate\Support\Collection;

/**
 * Lista as receitas de um usuário num mês, com filtro opcional por tipo (spec FE §7.10). Leitura
 * determinística, escopo ESTRITO por `user_id`; mais recentes primeiro (data desc, id como
 * desempate). Soft delete já filtrado pelo model. A SOMA do mês fica no {@see ReceitasDoMes}.
 */
final class ListarReceitas
{
    /** @return Collection<int, Income> */
    public function para(int $userId, string $mes, ?string $tipo): Collection
    {
        $periodo = PeriodoMensal::fromString($mes);

        return Income::query()
            ->where('user_id', $userId)
            ->whereBetween('data', [$periodo->inicio->toDateString(), $periodo->fim->toDateString()])
            ->when($tipo !== null, fn ($q) => $q->where('tipo', $tipo))
            ->orderByDesc('data')
            ->orderByDesc('id')
            ->get();
    }
}
