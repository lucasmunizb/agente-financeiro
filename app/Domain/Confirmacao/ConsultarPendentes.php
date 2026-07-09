<?php

declare(strict_types=1);

namespace App\Domain\Confirmacao;

use App\Models\PendingConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lista os pendentes ativos de um usuário (FE §7.9): status `pendente` e ainda não expirados
 * (TTL opcional). Ordena por chegada (mais antigos primeiro). Escopo ESTRITO por usuário —
 * nunca vaza fila de terceiros. Read-only: só devolve os itens; a formatação é frontend.
 *
 * @return Collection<int, PendingConfirmation>
 */
final class ConsultarPendentes
{
    /** @return Collection<int, PendingConfirmation> */
    public function para(int $userId, CarbonImmutable $agora): Collection
    {
        return PendingConfirmation::query()
            ->where('user_id', $userId)
            ->where('status', PendingConfirmation::STATUS_PENDENTE)
            ->where(function ($q) use ($agora) {
                $q->whereNull('expira_em')->orWhere('expira_em', '>', $agora);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
