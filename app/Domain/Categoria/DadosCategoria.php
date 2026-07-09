<?php

declare(strict_types=1);

namespace App\Domain\Categoria;

/**
 * Dados de entrada para criar/editar uma categoria (spec FE §7.12). DTO imutável, já
 * validado/traduzido na borda (Form Request). `palavrasChave` e `apelidos` são as regras do
 * lookup determinístico (doc 08 §1/§2) — o domínio as grava NORMALIZADAS e sem duplicatas.
 * `cor` (#RRGGBB) e `icone` saem de uma paleta fixa ({@see PaletaDeCategoria}); ambos opcionais.
 */
final class DadosCategoria
{
    /**
     * @param  list<string>  $palavrasChave
     * @param  list<string>  $apelidos
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $nome,
        public readonly ?string $cor,
        public readonly ?string $icone,
        public readonly array $palavrasChave = [],
        public readonly array $apelidos = [],
    ) {}
}
