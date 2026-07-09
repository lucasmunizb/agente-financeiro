<?php

declare(strict_types=1);

namespace App\Domain\Cartao;

use App\Models\Card;
use Illuminate\Support\Collection;

/**
 * Lista os cartões (ativos) de um usuário para a tela §7.13. Leitura determinística, escopo
 * ESTRITO por `user_id` (nunca vaza de terceiros); soft delete já filtrado pelo model. Ordena
 * por descrição (e id como desempate estável).
 */
final class ListarCartoes
{
    /** @return Collection<int, Card> */
    public function para(int $userId): Collection
    {
        return Card::query()
            ->where('user_id', $userId)
            ->orderBy('descricao')
            ->orderBy('id')
            ->get();
    }
}
