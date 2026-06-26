<?php

declare(strict_types=1);

namespace App\Domain\Categoria;

use App\Domain\Shared\Normalizador;

/**
 * Classificador determinístico de categoria (doc 08 §1).
 *
 * Puro: recebe a descrição e as {@see RegrasDeCategoria} já carregadas e devolve a
 * categoria_id (ou null). A IA nunca participa.
 *
 * Precedência: alias de estabelecimento > palavra-chave > nenhum. Em empate de
 * tipo, vence a regra mais longa (mais específica). Comparação por conteúdo, sobre
 * texto normalizado (caixa, acentos e espaços).
 */
final class ClassificadorDeCategoria
{
    public static function classificar(string $descricao, RegrasDeCategoria $regras): ?int
    {
        $texto = Normalizador::texto($descricao);

        return self::casarAlias($texto, $regras->aliases)
            ?? self::casarKeyword($texto, $regras->keywords);
    }

    /**
     * @param  array<string, int>  $aliases
     */
    private static function casarAlias(string $texto, array $aliases): ?int
    {
        $melhorCategoria = null;
        $melhorTamanho = -1;

        foreach ($aliases as $alias => $categoriaId) {
            $alvo = Normalizador::texto((string) $alias);
            if ($alvo !== '' && self::contem($texto, $alvo) && mb_strlen($alvo) > $melhorTamanho) {
                $melhorCategoria = $categoriaId;
                $melhorTamanho = mb_strlen($alvo);
            }
        }

        return $melhorCategoria;
    }

    /**
     * @param  array<int, array{palavra: string, categoria_id: int}>  $keywords
     */
    private static function casarKeyword(string $texto, array $keywords): ?int
    {
        $melhorCategoria = null;
        $melhorTamanho = -1;

        foreach ($keywords as $regra) {
            $alvo = Normalizador::texto($regra['palavra']);
            if ($alvo !== '' && self::contem($texto, $alvo) && mb_strlen($alvo) > $melhorTamanho) {
                $melhorCategoria = $regra['categoria_id'];
                $melhorTamanho = mb_strlen($alvo);
            }
        }

        return $melhorCategoria;
    }

    private static function contem(string $texto, string $alvo): bool
    {
        return str_contains($texto, $alvo);
    }
}
