<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use Carbon\CarbonImmutable;

/**
 * Resolução determinística da próxima ocorrência mensal de um `dia`-do-mês. Com **clamp** ao
 * fim do mês (ex.: dia 31 em fevereiro → 28/29). "A partir de" é inclusivo: se o dia cai no
 * mesmo dia da referência, é ele; se já passou, rola para o mês seguinte. Trabalha no
 * CALENDÁRIO da própria data injetada (sem conversão de fuso — datas de calendário não têm
 * hora); o caller que recebe um instante normaliza para America/Sao_Paulo antes de chamar.
 * Nenhum relógio global é lido (regra 4/5, determinismo).
 */
final class OcorrenciaMensal
{
    public static function aPartirDe(int $dia, CarbonImmutable $data): CarbonImmutable
    {
        $ref = $data->startOfDay();

        $candidato = self::noMes($dia, $ref);
        if ($candidato->greaterThanOrEqualTo($ref)) {
            return $candidato;
        }

        return self::noMes($dia, $ref->startOfMonth()->addMonthNoOverflow());
    }

    /** O `dia` dentro do mês de referência, clampado ao último dia existente. */
    private static function noMes(int $dia, CarbonImmutable $ref): CarbonImmutable
    {
        $mes = $ref->startOfMonth();

        return $mes->day(min($dia, $mes->daysInMonth));
    }
}
