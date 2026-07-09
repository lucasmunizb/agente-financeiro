<?php

declare(strict_types=1);

namespace App\Domain\FaturaCartao;

use App\Domain\Calendar\RelativeDate;
use Carbon\CarbonImmutable;

/**
 * Datas e status do ciclo de uma fatura numa competência (spec FE §7.13). Derivação
 * DETERMINÍSTICA e consistente com a regra documentada (doc 03 §4.2 / {@see
 * \App\Domain\Vencimento\CalculadoraDeVencimento}): a competência é o mês de VENCIMENTO da
 * fatura (mesma atribuição por mês de vencimento da §4.5). Deste vencimento derivamos, ao
 * contrário, o mês de FECHAMENTO:
 *
 * - vence ≥ fecha (mesmo dia-do-mês): fatura fecha e vence no mesmo mês;
 * - vence < fecha: a fatura fechou no mês ANTERIOR ao vencimento.
 *
 * `aberta` = "hoje" ainda não passou do fechamento (a fatura segue acumulando). Datas no fuso
 * base; "hoje" injetado (nunca lido do relógio global — regra 4/5). Só apresentação — não
 * materializa fatura (isso é a proposta pendente da spec 09).
 */
final class CicloDaFatura
{
    public function __construct(
        public readonly CarbonImmutable $fecha,
        public readonly CarbonImmutable $vence,
        public readonly bool $aberta,
    ) {}

    public static function paraCompetencia(int $diaFechamento, int $diaVencimento, string $competencia, CarbonImmutable $hoje): self
    {
        $mesVencimento = CarbonImmutable::createFromFormat('!Y-m-d', $competencia.'-01', RelativeDate::TIMEZONE);
        $vence = self::noMes($mesVencimento, $diaVencimento);

        // Inverso da regra forward (§4.2): se o vencimento é anterior ao fechamento, a fatura
        // fechou no mês anterior; senão, no mesmo mês do vencimento.
        $mesFechamento = $diaVencimento >= $diaFechamento
            ? $mesVencimento
            : $mesVencimento->subMonthNoOverflow();
        $fecha = self::noMes($mesFechamento, $diaFechamento);

        $aberta = $hoje->setTimezone(RelativeDate::TIMEZONE)->startOfDay()->lessThanOrEqualTo($fecha);

        return new self($fecha, $vence, $aberta);
    }

    /** O dia-do-mês dado, clampado ao último dia existente do mês. */
    private static function noMes(CarbonImmutable $mes, int $dia): CarbonImmutable
    {
        return $mes->day(min($dia, $mes->daysInMonth))->startOfDay();
    }
}
