<?php

declare(strict_types=1);

namespace App\Domain\IA\Guard;

/**
 * Parser determinístico de números por extenso em pt-BR ("mil e quinhentos" → 1500).
 * Usado pelo {@see GuardPosGeracao} para que valores/datas escritos por extenso pela IA
 * não escapem da validação contra o payload calculado (barreira 4, doc 02 §3.3).
 * Não é IA: tabela fixa de palavras + acumulação aditiva com multiplicadores.
 */
final class NumeroPorExtenso
{
    /** @var array<string, int> */
    private const UNIDADES = [
        'zero' => 0, 'um' => 1, 'uma' => 1, 'primeiro' => 1, 'dois' => 2, 'duas' => 2,
        'tres' => 3, 'quatro' => 4, 'cinco' => 5, 'seis' => 6, 'sete' => 7, 'oito' => 8,
        'nove' => 9, 'dez' => 10, 'onze' => 11, 'doze' => 12, 'treze' => 13,
        'catorze' => 14, 'quatorze' => 14, 'quinze' => 15, 'dezesseis' => 16,
        'dezessete' => 17, 'dezoito' => 18, 'dezenove' => 19,
    ];

    /** @var array<string, int> */
    private const DEZENAS = [
        'vinte' => 20, 'trinta' => 30, 'quarenta' => 40, 'cinquenta' => 50,
        'sessenta' => 60, 'setenta' => 70, 'oitenta' => 80, 'noventa' => 90,
    ];

    /** @var array<string, int> */
    private const CENTENAS = [
        'cem' => 100, 'cento' => 100, 'duzentos' => 200, 'duzentas' => 200,
        'trezentos' => 300, 'trezentas' => 300, 'quatrocentos' => 400, 'quatrocentas' => 400,
        'quinhentos' => 500, 'quinhentas' => 500, 'seiscentos' => 600, 'seiscentas' => 600,
        'setecentos' => 700, 'setecentas' => 700, 'oitocentos' => 800, 'oitocentas' => 800,
        'novecentos' => 900, 'novecentas' => 900,
    ];

    /**
     * Tenta interpretar o trecho como um número por extenso. Qualquer palavra fora do
     * vocabulário numérico devolve null (o trecho não é um número). "e" é conectivo.
     */
    public static function tentarParse(string $texto): ?int
    {
        $palavras = preg_split('/\s+/', trim(self::normalizar($texto)), flags: PREG_SPLIT_NO_EMPTY);

        if ($palavras === false || $palavras === []) {
            return null;
        }

        $total = 0;
        $atual = 0;
        $temNumero = false;

        foreach ($palavras as $palavra) {
            if ($palavra === 'e') {
                continue;
            }

            if (isset(self::UNIDADES[$palavra])) {
                $atual += self::UNIDADES[$palavra];
            } elseif (isset(self::DEZENAS[$palavra])) {
                $atual += self::DEZENAS[$palavra];
            } elseif (isset(self::CENTENAS[$palavra])) {
                $atual += self::CENTENAS[$palavra];
            } elseif ($palavra === 'mil') {
                $atual = ($atual === 0 ? 1 : $atual) * 1_000;
                $total += $atual;
                $atual = 0;
            } elseif (in_array($palavra, ['milhao', 'milhoes'], strict: true)) {
                $atual = ($atual === 0 ? 1 : $atual) * 1_000_000;
                $total += $atual;
                $atual = 0;
            } else {
                return null;
            }

            $temNumero = true;
        }

        return $temNumero ? $total + $atual : null;
    }

    /** Minúsculas e sem acento — "três"/"tres", "milhão"/"milhao" convergem. */
    public static function normalizar(string $texto): string
    {
        $minusculo = mb_strtolower($texto, 'UTF-8');

        return strtr($minusculo, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
    }
}
