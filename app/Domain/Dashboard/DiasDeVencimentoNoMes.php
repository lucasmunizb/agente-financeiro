<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Shared\PeriodoMensal;
use App\Models\Installment;
use App\Models\StatusPagamento;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dias do mês que têm vencimento de parcela (ticks da "régua do mês", spec FE §4.5).
 *
 * Read-only e determinístico: varre as parcelas do usuário vencendo na competência e
 * devolve os NÚMEROS de dia (1..31) únicos e ordenados — a régua só desenha os ticks,
 * não calcula dinheiro (regra 4). Escopo ESTRITO por usuário. Exclui os status que não
 * são conta viva (§4.4: pendente_revisao, cancelado, estornado) — um vencimento
 * cancelado não vira tick.
 */
final class DiasDeVencimentoNoMes
{
    /**
     * @return list<int> dias (1..31) com vencimento, únicos e em ordem crescente
     */
    public function para(int $userId, string $mes): array
    {
        $periodo = PeriodoMensal::fromString($mes);

        $excluidos = StatusPagamento::query()
            ->whereIn('codigo', StatusPagamento::EXCLUIDOS)
            ->pluck('id')
            ->all();

        return Installment::query()
            ->whereBetween('vencimento', [$periodo->inicio->toDateString(), $periodo->fim->toDateString()])
            ->whereNotIn('status_id', $excluidos)
            ->whereHas('transaction', fn (Builder $q) => $q->where('user_id', $userId))
            ->get()
            ->map(fn (Installment $parcela): int => (int) $parcela->vencimento->day)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
