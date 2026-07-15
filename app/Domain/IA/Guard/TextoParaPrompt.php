<?php

declare(strict_types=1);

namespace App\Domain\IA\Guard;

/**
 * Sanitiza texto livre do usuário (descrições, nomes de categoria/cartão) antes da
 * concatenação em payload textual de prompt (auditoria P2-5, seguranca-ia). O payload
 * é orientado a linhas: uma descrição com "\n" fabricaria linhas/instruções novas
 * (injeção de 2ª ordem — hoje texto do usuário; amanhã, de merchant via PDF); um texto
 * gigante degrada o guard e o contexto (DoS). Determinístico, sem IA.
 */
final class TextoParaPrompt
{
    private const TAMANHO_MAXIMO = 120;

    public static function sanitizar(string $texto, int $max = self::TAMANHO_MAXIMO): string
    {
        // Controle (inclui \r\n\t) vira espaço; espaços consecutivos colapsam.
        $plano = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texto) ?? '';
        $plano = trim(preg_replace('/\s+/u', ' ', $plano) ?? '');

        if (mb_strlen($plano) > $max) {
            $plano = rtrim(mb_substr($plano, 0, $max)).'…';
        }

        return $plano;
    }
}
