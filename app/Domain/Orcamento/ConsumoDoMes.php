<?php

declare(strict_types=1);

namespace App\Domain\Orcamento;

use App\Domain\Shared\PeriodoMensal;
use App\Models\Installment;
use App\Models\StatusPagamento;

/**
 * Consumo do mês: total gasto e quebra por categoria (doc 08 §6).
 *
 * Base = parcelas vencendo no mês — mesma base do "disponível" (§4.5), de modo que
 * cada gasto pertence a um único mês de vencimento. O valor da parcela é DERIVADO
 * do total (nunca persistido). Exclui status que não entram no cálculo
 * (pendente_revisao, cancelado, estornado — §4.4). Escopo estrito por usuário.
 */
final class ConsumoDoMes
{
    public function para(int $userId, string $mes): ConsumoMensal
    {
        $periodo = PeriodoMensal::fromString($mes);

        $excluidos = StatusPagamento::query()
            ->whereIn('codigo', StatusPagamento::EXCLUIDOS)
            ->pluck('id')
            ->all();

        $parcelas = Installment::query()
            ->whereBetween('vencimento', [$periodo->inicio->toDateString(), $periodo->fim->toDateString()])
            ->whereNotIn('status_id', $excluidos)
            ->whereHas('transaction', fn ($q) => $q->where('user_id', $userId))
            ->with('transaction')
            ->get();

        $porCategoria = [];
        $total = 0;

        foreach ($parcelas as $parcela) {
            $cents = $parcela->valor()->cents();
            $categoria = $parcela->transaction->categoria_id ?? ConsumoMensal::SEM_CATEGORIA;

            $porCategoria[$categoria] = ($porCategoria[$categoria] ?? 0) + $cents;
            $total += $cents;
        }

        return new ConsumoMensal(totalCents: $total, porCategoria: $porCategoria);
    }
}
