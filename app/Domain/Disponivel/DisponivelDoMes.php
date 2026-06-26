<?php

declare(strict_types=1);

namespace App\Domain\Disponivel;

use App\Domain\Shared\Money;

/**
 * Cálculo determinístico do "disponível do mês" (doc 03 §4.5).
 *
 * Fórmula oficial:
 *   Disponível = Receitas recebidas no mês
 *              − Gastos em cartão com vencimento no mês
 *              − Gastos fora de cartão do mês (PIX/débito).
 *
 * O componente de cartão é atribuído pelo MÊS DE VENCIMENTO de cada gasto
 * (calculado em §4.2 por {@see \App\Domain\Vencimento\CalculadoraDeVencimento}),
 * independente de a fatura já ter fechado — uma fatura aberta cujo vencimento cai
 * no mês corrente já entra. Gastos com vencimento no mês seguinte não entram no
 * mês corrente; compõem o "previsto do próximo mês". Cada gasto pertence a um
 * único mês de vencimento (sem dupla contagem).
 *
 * Este calculador é puro: recebe os totais já consultados (em centavos) e aplica
 * a fórmula. A varredura que soma os gastos por mês de vencimento direto do banco
 * é responsabilidade da camada de consulta (Bloco 2/3).
 */
final class DisponivelDoMes
{
    /**
     * @param  int  $receitasCents  receitas recebidas no mês.
     * @param  int  $cartaoVencendoNoMesCents  gastos em cartão com vencimento no mês.
     * @param  int  $foraDeCartaoCents  gastos fora de cartão (PIX/débito) do mês.
     * @param  int  $cobrancasProximoMesCents  cobranças com vencimento no mês seguinte.
     */
    public static function calcular(
        int $receitasCents,
        int $cartaoVencendoNoMesCents,
        int $foraDeCartaoCents,
        int $cobrancasProximoMesCents = 0,
    ): ResultadoDisponivel {
        $disponivel = Money::fromCents($receitasCents)
            ->minus(Money::fromCents($cartaoVencendoNoMesCents))
            ->minus(Money::fromCents($foraDeCartaoCents));

        return new ResultadoDisponivel(
            disponivel: $disponivel,
            previstoProximoMes: Money::fromCents($cobrancasProximoMesCents),
        );
    }
}
