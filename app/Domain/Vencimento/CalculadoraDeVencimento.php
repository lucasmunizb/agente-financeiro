<?php

declare(strict_types=1);

namespace App\Domain\Vencimento;

use Carbon\CarbonImmutable;

/**
 * Cálculo determinístico do vencimento (doc 03 §4.2).
 *
 * - Fora de cartão (PIX/débito/dinheiro): o vencimento é a própria data da
 *   compra/parcela.
 * - Em cartão: respeita SEMPRE o vencimento do cartão, pelo ciclo de fatura
 *   completo — a compra entra na fatura aberta no momento (fecha no dia de
 *   fechamento) e vence no dia de vencimento do cartão.
 *
 * O valor retornado é o "primeiro vencimento" (parcela 1) que alimenta o
 * {@see \App\Domain\Parcelamento\GeradorDeParcelas}. As datas seguem o fuso base
 * America/Sao_Paulo; espera-se a entrada já localizada.
 */
final class CalculadoraDeVencimento
{
    /**
     * Vencimento de cobrança fora de cartão: a própria data, no início do dia.
     */
    public static function foraDeCartao(CarbonImmutable $dataParcela): CarbonImmutable
    {
        return $dataParcela->startOfDay();
    }

    /**
     * Vencimento da 1ª parcela de uma compra em cartão, pelo ciclo de fatura.
     *
     * @param  CarbonImmutable  $dataCompra  data da compra.
     * @param  int  $diaFechamento  dia de fechamento da fatura (1..31).
     * @param  int  $diaVencimento  dia de vencimento da fatura (1..31).
     */
    public static function cartao(CarbonImmutable $dataCompra, int $diaFechamento, int $diaVencimento): CarbonImmutable
    {
        $compra = $dataCompra->startOfDay();

        // Fatura em que a compra cai: compra até o fechamento (clamp em meses
        // curtos) fecha neste mês; depois do fechamento, na fatura seguinte.
        $fechamentoEfetivo = min($diaFechamento, $compra->daysInMonth);
        $mesFechamento = $compra->day <= $fechamentoEfetivo
            ? $compra->startOfMonth()
            : $compra->startOfMonth()->addMonthNoOverflow();

        // Vencimento: no mesmo mês do fechamento se o dia de vencimento não for
        // anterior ao de fechamento; caso contrário, no mês seguinte.
        $mesVencimento = $diaVencimento >= $diaFechamento
            ? $mesFechamento
            : $mesFechamento->addMonthNoOverflow();

        $dia = min($diaVencimento, $mesVencimento->daysInMonth);

        return $mesVencimento->day($dia)->startOfDay();
    }
}
