<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Calendar\RelativeDate;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\PendingConfirmation;
use Carbon\CarbonImmutable;

/**
 * Ponte "fila ↔ extrato" (spec 10, decisão: fila e extrato COEXISTEM). Lê as ocorrências de
 * recorrência que estão na FILA (confirmação pendente `pendente`, origem `recorrencia`) vencendo
 * no mês pedido e as devolve como ocorrências para o extrato/dashboard — status de exibição
 * derivado por DATA ("previsto" se ainda não venceu, "atraso" se o dia passou) e o id OPACO do
 * pendente para o botão "marcar como pago" ({@see PagarRecorrenciaPendente}).
 *
 * Read-only: não grava nada (regra 7). Escopo ESTRITO por usuário. "Agora" injetado (regra 4/5).
 * Complementa {@see ProjetarRecorrencias} (molde de mês FUTURO): aqui é o mês corrente/atrasado,
 * onde a ocorrência já foi materializada na fila pelo agendador mas ainda não foi confirmada.
 */
final class ProjetarRecorrenciasPendentes
{
    /**
     * @return list<array<string, mixed>>  ocorrências ordenadas por vencimento asc
     */
    public function para(int $userId, string $mesAlvo, CarbonImmutable $agora): array
    {
        $inicio = CarbonImmutable::createFromFormat('!Y-m-d', $mesAlvo.'-01', RelativeDate::TIMEZONE);
        $fim = $inicio->endOfMonth();
        $hojeData = $agora->setTimezone(RelativeDate::TIMEZONE)->startOfDay();

        $pendentes = PendingConfirmation::query()
            ->where('user_id', $userId)
            ->where('status', PendingConfirmation::STATUS_PENDENTE)
            ->where('origem', PendingConfirmation::ORIGEM_RECORRENCIA)
            ->where(fn ($q) => $q->whereNull('expira_em')->orWhere('expira_em', '>', $agora))
            ->get()
            ->filter(function (PendingConfirmation $p) use ($inicio, $fim): bool {
                $data = (string) ($p->payload['dataCompra'] ?? '');

                return $data >= $inicio->toDateString() && $data <= $fim->toDateString();
            });

        if ($pendentes->isEmpty()) {
            return [];
        }

        // Resolve nomes/cores de categoria e o tipo da forma em lote (escopo por usuário).
        $categorias = Category::query()
            ->where('user_id', $userId)
            ->whereIn('id', $pendentes->pluck('payload.categoriaId')->filter()->unique()->all())
            ->get()
            ->keyBy('id');
        $formas = PaymentMethod::query()
            ->whereIn('id', $pendentes->pluck('payload.paymentMethodId')->filter()->unique()->all())
            ->pluck('tipo', 'id');

        $ocorrencias = [];

        foreach ($pendentes as $p) {
            $venc = CarbonImmutable::createFromFormat('!Y-m-d', (string) $p->payload['dataCompra'], RelativeDate::TIMEZONE);
            $catId = isset($p->payload['categoriaId']) ? (int) $p->payload['categoriaId'] : null;
            $cat = $catId !== null ? $categorias->get($catId) : null;
            $formaId = isset($p->payload['paymentMethodId']) ? (int) $p->payload['paymentMethodId'] : null;

            $ocorrencias[] = [
                'descricao' => (string) $p->payload['descricao'],
                'vencimento' => $venc->toDateString(),
                'cents' => (int) $p->payload['valorTotalCents'],
                'categoriaId' => $catId,
                'categoria' => $cat !== null ? ['nome' => (string) $cat->nome, 'cor' => $cat->cor] : null,
                'forma' => $formaId !== null ? ($formas[$formaId] ?? null) : null,
                // Status de exibição por data: venceu e não pago → atraso; senão → previsto.
                'status' => $venc->startOfDay()->lessThan($hojeData) ? 'atraso' : 'previsto',
                // Id OPACO do pendente (nunca o id real na URL) — alvo do "marcar como pago".
                'pendenteId' => $p->opaqueId(),
            ];
        }

        usort($ocorrencias, static fn (array $a, array $b): int => $a['vencimento'] <=> $b['vencimento']);

        return $ocorrencias;
    }
}
