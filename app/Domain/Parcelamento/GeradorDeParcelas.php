<?php

declare(strict_types=1);

namespace App\Domain\Parcelamento;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;

/**
 * Motor determinístico de parcelamento (doc 03 §4.1 e §4.2).
 *
 * Regras:
 * - Na 1/N, gera todas as N parcelas futuras.
 * - O valor de cada parcela é DERIVADO do total via {@see Money::allocate()} — o
 *   resto é distribuído nas primeiras parcelas, sem perder centavos. Nunca se
 *   persiste o valor por parcela.
 * - O vencimento de cada parcela é o primeiro vencimento + (i) meses, calculado
 *   SEMPRE a partir do primeiro (evita drift), com clamp para o último dia de
 *   meses mais curtos (31/01 → 28/02).
 * - A parcela vigente é SEMPRE calculada ({@see self::parcelaVigente()}), nunca fixada.
 *
 * As datas são tratadas no fuso base America/Sao_Paulo; espera-se que os
 * vencimentos de entrada já estejam localizados (ver {@see RelativeDate}).
 */
final class GeradorDeParcelas
{
    /**
     * Gera as parcelas de uma compra parcelada.
     *
     * @param  int  $totalCents  valor total da compra, em centavos.
     * @param  int  $parcelas  quantidade de parcelas (N >= 1).
     * @param  CarbonImmutable  $primeiroVencimento  vencimento da 1ª parcela.
     * @return array<int, Parcela>
     *
     * @throws \InvalidArgumentException quando N < 1 (via Money::allocate).
     */
    public static function gerar(int $totalCents, int $parcelas, CarbonImmutable $primeiroVencimento): array
    {
        $valores = Money::fromCents($totalCents)->allocate($parcelas);
        $base = $primeiroVencimento->startOfDay();

        $resultado = [];
        foreach ($valores as $i => $valor) {
            $resultado[] = new Parcela(
                numero: $i + 1,
                total: $parcelas,
                vencimento: $base->addMonthsNoOverflow($i),
                valor: $valor,
            );
        }

        return $resultado;
    }

    /**
     * Calcula a parcela vigente para uma data de referência — NUNCA é persistida.
     *
     * Conta os meses decorridos desde o primeiro vencimento (+1) e faz clamp em
     * [1, total]: referência anterior ao primeiro → 1; posterior à última → total.
     */
    public static function parcelaVigente(CarbonImmutable $primeiroVencimento, int $parcelas, CarbonImmutable $referencia): int
    {
        $mesesDecorridos = ($referencia->year - $primeiroVencimento->year) * 12
            + ($referencia->month - $primeiroVencimento->month);

        $numero = $mesesDecorridos + 1;

        return max(1, min($parcelas, $numero));
    }
}
