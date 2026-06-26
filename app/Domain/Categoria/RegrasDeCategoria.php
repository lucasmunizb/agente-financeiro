<?php

declare(strict_types=1);

namespace App\Domain\Categoria;

/**
 * Conjunto de regras de classificação de um usuário (doc 08 §1).
 *
 * Estrutura imutável consumida pelo {@see ClassificadorDeCategoria}. A montagem a
 * partir do banco (escopo por usuário) é responsabilidade do {@see LookupDeCategoria}.
 *
 * @phpstan-type KeywordRegra array{palavra: string, categoria_id: int}
 */
final class RegrasDeCategoria
{
    /**
     * @param  array<string, int>  $aliases        alias normalizado => categoria_id
     * @param  array<int, array{palavra: string, categoria_id: int}>  $keywords  palavra normalizada => categoria_id
     */
    public function __construct(
        public readonly array $aliases,
        public readonly array $keywords,
    ) {
    }
}
