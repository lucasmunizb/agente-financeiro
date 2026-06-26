<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Normalização determinística de texto para comparação (doc 07 §2 / doc 08 §1).
 *
 * Baixa a caixa, remove acentos com um mapa fixo (sem depender de locale/ICU) e
 * trata qualquer pontuação/símbolo como separador, colapsando espaços. Garante o
 * mesmo resultado em qualquer ambiente. Usado pela detecção de duplicidade e pelo
 * lookup de categoria (ex.: "Uber *trip 91" => "uber trip 91").
 */
final class Normalizador
{
    /** @var array<string, string> */
    private const ACENTOS = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ];

    public static function texto(string $texto): string
    {
        $texto = mb_strtolower($texto);
        $texto = strtr($texto, self::ACENTOS);

        // Pontuação e símbolos viram separador; espaços são colapsados.
        $texto = (string) preg_replace('/[^a-z0-9]+/', ' ', $texto);

        return trim($texto);
    }
}
