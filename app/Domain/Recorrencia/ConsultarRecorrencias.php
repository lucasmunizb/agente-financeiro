<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Models\Recurrence;
use Illuminate\Support\Collection;

/**
 * Lista as recorrências ATIVAS de um usuário para a tela de gerenciamento (spec 10). Leitura
 * determinística, escopo ESTRITO por `user_id` (nunca vaza de terceiros). Ordena por dia-do-mês
 * (e id como desempate estável). As canceladas ficam de fora — a tela mostra o que ainda gera.
 */
final class ConsultarRecorrencias
{
    /** @return Collection<int, Recurrence> */
    public function para(int $userId): Collection
    {
        return Recurrence::query()
            ->where('user_id', $userId)
            ->where('status', Recurrence::STATUS_ATIVO)
            ->orderBy('dia')
            ->orderBy('id')
            ->get();
    }
}
