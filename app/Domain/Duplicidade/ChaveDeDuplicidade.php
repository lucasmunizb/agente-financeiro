<?php

declare(strict_types=1);

namespace App\Domain\Duplicidade;

use Carbon\CarbonImmutable;

/**
 * Chave determinística de duplicidade de um lançamento (doc 07 §2).
 *
 * Critério: valor + descrição + data + quantidade de parcelas.
 * A PARCELA ATUAL NUNCA entra no critério — por isso esta chave sequer a aceita
 * (ela é sempre calculada na exibição). A descrição é normalizada (caixa, acentos
 * e espaços) para robustez de comparação.
 */
final class ChaveDeDuplicidade
{
    private function __construct(
        public readonly int $valorCents,
        public readonly string $descricao,
        public readonly string $data,
        public readonly int $parcelas,
    ) {
    }

    public static function de(int $valorCents, string $descricao, CarbonImmutable $data, int $parcelas): self
    {
        return new self(
            valorCents: $valorCents,
            descricao: self::normalizarDescricao($descricao),
            data: $data->toDateString(),
            parcelas: $parcelas,
        );
    }

    /**
     * Identificador canônico da chave; igualdade é comparação de hash.
     */
    public function hash(): string
    {
        return sprintf('%d|%s|%s|%d', $this->valorCents, $this->descricao, $this->data, $this->parcelas);
    }

    public function igualA(self $outra): bool
    {
        return $this->hash() === $outra->hash();
    }

    private static function normalizarDescricao(string $descricao): string
    {
        // Colapsa espaços e remove os das pontas.
        $texto = trim((string) preg_replace('/\s+/', ' ', $descricao));

        $texto = mb_strtolower($texto);

        // Remove acentos de forma determinística (mesmo padrão do RelativeDate).
        $map = ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c'];

        return strtr($texto, $map);
    }
}
