<?php

declare(strict_types=1);

namespace App\Domain\Categoria\Concerns;

use App\Domain\Shared\Normalizador;
use App\Models\Category;
use App\Models\MerchantAlias;

/**
 * Sincroniza as regras do lookup (doc 08 §1/§2) de uma categoria a partir das listas cruas
 * digitadas na tela. Palavras-chave e apelidos são gravados NORMALIZADOS (caixa/acentos/espaços,
 * via {@see Normalizador}) e sem duplicatas — a mesma forma que o classificador e o aprendizado
 * por correção usam, para nunca criar duas regras para o mesmo termo. Idempotente por termo.
 *
 * - Palavras-chave: escopo é a categoria (unique category_id+palavra) → substitui o conjunto.
 * - Apelidos: escopo é o usuário (unique user_id+alias) → re-aponta o alias para esta categoria
 *   (updateOrCreate) e remove os que saíram da lista desta categoria, sem tocar nos de outras.
 */
trait SincronizaRegras
{
    /**
     * @param  list<string>  $palavras
     */
    protected function sincronizarPalavras(Category $categoria, array $palavras): void
    {
        $normalizadas = $this->normalizarLista($palavras);

        $categoria->keywords()->delete();
        foreach ($normalizadas as $palavra) {
            $categoria->keywords()->create(['palavra_chave' => $palavra]);
        }
    }

    /**
     * @param  list<string>  $apelidos
     */
    protected function sincronizarApelidos(Category $categoria, array $apelidos): void
    {
        $desejados = $this->normalizarLista($apelidos);

        // Remove os apelidos DESTA categoria que saíram da lista (não mexe nos de outras).
        MerchantAlias::query()
            ->where('user_id', $categoria->user_id)
            ->where('category_id', $categoria->id)
            ->whereNotIn('alias', $desejados)
            ->delete();

        foreach ($desejados as $alias) {
            MerchantAlias::updateOrCreate(
                ['user_id' => $categoria->user_id, 'alias' => $alias],
                ['category_id' => $categoria->id],
            );
        }
    }

    /**
     * @param  list<string>  $termos
     * @return list<string>
     */
    private function normalizarLista(array $termos): array
    {
        return collect($termos)
            ->map(fn (string $t): string => Normalizador::texto($t))
            ->filter(fn (string $t): bool => $t !== '')
            ->unique()
            ->values()
            ->all();
    }
}
