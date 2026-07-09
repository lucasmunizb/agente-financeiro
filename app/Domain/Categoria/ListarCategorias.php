<?php

declare(strict_types=1);

namespace App\Domain\Categoria;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Lista as categorias ATIVAS (não arquivadas) de um usuário para a grade §7.12, com a contagem
 * de uso JÁ calculada (a UI nunca calcula — regra 4). Leitura determinística, escopo ESTRITO por
 * `user_id`: a contagem só considera lançamentos do próprio usuário. Carrega palavras-chave e
 * apelidos (eager) para preencher o formulário de edição. Ordena por nome (id como desempate).
 */
final class ListarCategorias
{
    /**
     * @return Collection<int, array{categoria: Category, usos: int}>
     */
    public function para(int $userId): Collection
    {
        // Contagem de uso: uma query agregada (categoria_id => total), escopada por usuário.
        $usos = Transaction::query()
            ->where('user_id', $userId)
            ->whereNotNull('categoria_id')
            ->groupBy('categoria_id')
            ->selectRaw('categoria_id, count(*) as total')
            ->pluck('total', 'categoria_id');

        return Category::query()
            ->where('user_id', $userId)
            ->where('arquivada', false)
            ->with(['keywords', 'aliases'])
            ->orderBy('nome')
            ->orderBy('id')
            ->get()
            ->map(fn (Category $c): array => [
                'categoria' => $c,
                'usos' => (int) ($usos[$c->id] ?? 0),
            ]);
    }
}
