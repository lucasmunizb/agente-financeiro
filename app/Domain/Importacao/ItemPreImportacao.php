<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

/**
 * Um item da pré-importação (spec 07 §6): o lançamento extraído + a marcação de
 * duplicidade (valor+descrição+data+nº parcelas, nunca a parcela atual) + a categoria
 * sugerida de forma determinística (lookup). Inerte até a confirmação (regra 7).
 */
final class ItemPreImportacao
{
    public function __construct(
        public readonly LancamentoExtraido $lancamento,
        public readonly bool $duplicado,
        public readonly ?int $categoriaIdSugerida = null,
    ) {}
}
