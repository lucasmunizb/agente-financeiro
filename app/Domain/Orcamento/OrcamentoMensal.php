<?php

declare(strict_types=1);

namespace App\Domain\Orcamento;

use App\Models\Budget;

/**
 * Orçamento mensal geral avaliado contra o consumo do mês (doc 08 §6).
 *
 * Carrega o limite geral (budgets com categoria_id NULL) do par (usuário, mês) e o
 * consumo via {@see ConsumoDoMes}, delegando a avaliação determinística a
 * {@see Orcamento}. Sem orçamento definido, o limite é zero. Sem disparo de alerta.
 */
final class OrcamentoMensal
{
    public function __construct(private readonly ConsumoDoMes $consumoDoMes) {}

    public function para(int $userId, string $mes): ResultadoOrcamento
    {
        $limiteCents = (int) Budget::query()
            ->where('user_id', $userId)
            ->where('mes', $mes)
            ->whereNull('categoria_id')
            ->value('limite_cents');

        $consumo = $this->consumoDoMes->para($userId, $mes);

        return Orcamento::avaliar($limiteCents, $consumo->totalCents);
    }
}
