<?php

declare(strict_types=1);

namespace App\Domain\Categoria;

use App\Models\CategoryKeyword;
use App\Models\MerchantAlias;

/**
 * Lookup de categoria com base no banco (doc 08 §1).
 *
 * Carrega as regras do usuário (aliases + palavras-chave de categorias ativas, não
 * arquivadas) e delega ao {@see ClassificadorDeCategoria}. Escopo estrito por
 * usuário: regras de terceiros nunca participam. Determinístico — sem IA.
 */
final class LookupDeCategoria
{
    public function para(int $userId, string $descricao): ?int
    {
        return ClassificadorDeCategoria::classificar($descricao, $this->regras($userId));
    }

    private function regras(int $userId): RegrasDeCategoria
    {
        $aliases = MerchantAlias::query()
            ->where('user_id', $userId)
            ->whereHas('category', fn ($q) => $q->where('arquivada', false))
            ->pluck('category_id', 'alias')
            ->map(fn ($id) => (int) $id)
            ->all();

        $keywords = CategoryKeyword::query()
            ->whereHas('category', fn ($q) => $q->where('user_id', $userId)->where('arquivada', false))
            ->get(['palavra_chave', 'category_id'])
            ->map(fn (CategoryKeyword $k) => [
                'palavra' => $k->palavra_chave,
                'categoria_id' => (int) $k->category_id,
            ])
            ->all();

        return new RegrasDeCategoria(aliases: $aliases, keywords: $keywords);
    }
}
