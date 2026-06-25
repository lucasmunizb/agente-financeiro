<?php

declare(strict_types=1);

namespace App\Domain\Calendar;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Resolução determinística de datas relativas no fuso base America/Sao_Paulo.
 *
 * O "agora" é sempre injetado (nunca lido do relógio global) para manter o
 * cálculo testável e determinístico — a IA jamais resolve datas por conta própria.
 */
final class RelativeDate
{
    public const TIMEZONE = 'America/Sao_Paulo';

    /**
     * Dia padrão usado quando "mês que vem" não tem dia configurado.
     */
    public const DIA_PADRAO = 5;

    /**
     * @param  string  $term  termo relativo: hoje, ontem, amanhã, "mês que vem".
     * @param  CarbonImmutable  $now  instante de referência (qualquer fuso).
     * @param  int|null  $diaPadrao  dia-do-mês para "mês que vem" (ex.: vencimento); null → dia 5.
     *
     * @throws InvalidArgumentException quando o termo é desconhecido.
     */
    public static function resolve(string $term, CarbonImmutable $now, ?int $diaPadrao = null): CarbonImmutable
    {
        $hoje = $now->setTimezone(self::TIMEZONE)->startOfDay();

        $normalized = self::normalize($term);

        return match ($normalized) {
            'hoje' => $hoje,
            'ontem' => $hoje->subDay(),
            'amanha' => $hoje->addDay(),
            'mes que vem' => self::mesQueVem($hoje, $diaPadrao ?? self::DIA_PADRAO),
            default => throw new InvalidArgumentException("Termo de data relativa desconhecido: \"{$term}\"."),
        };
    }

    private static function mesQueVem(CarbonImmutable $hoje, int $dia): CarbonImmutable
    {
        $proximoMes = $hoje->startOfMonth()->addMonthNoOverflow();
        $diaEfetivo = min($dia, $proximoMes->daysInMonth);

        return $proximoMes->day($diaEfetivo)->startOfDay();
    }

    private static function normalize(string $term): string
    {
        $lower = mb_strtolower(trim($term));

        // Remove acentos (amanhã → amanha, mês → mes) de forma determinística.
        $map = ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c'];

        return strtr($lower, $map);
    }
}
