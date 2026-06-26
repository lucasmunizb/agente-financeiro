<?php

use App\Domain\Disponivel\DisponivelDoMes;
use App\Domain\Disponivel\ResultadoDisponivel;
use App\Domain\Vencimento\CalculadoraDeVencimento;
use Carbon\CarbonImmutable;

/*
 * Cálculo do "disponível do mês" (doc 03 §4.5) — determinístico, sem DB.
 * Fórmula: Receitas recebidas no mês − gastos em cartão com vencimento no mês
 *          − gastos fora de cartão (PIX/débito) do mês.
 * O gasto em cartão é atribuído ao MÊS DE VENCIMENTO (§4.2), inclusive fatura
 * ainda aberta cujo vencimento cai no mês. Há duas visões: disponível do mês
 * corrente e previsto do próximo mês.
 */

/* -------- Fórmula oficial sobre totais já consultados -------- */

it('aplica a fórmula oficial do disponível do mês', function () {
    // receitas 4.000,00 ; cartão vencendo no mês 1.200,00 ; fora de cartão 200,00
    $resultado = DisponivelDoMes::calcular(
        receitasCents: 400000,
        cartaoVencendoNoMesCents: 120000,
        foraDeCartaoCents: 20000,
    );

    expect($resultado)->toBeInstanceOf(ResultadoDisponivel::class)
        ->and($resultado->disponivel->cents())->toBe(260000);
});

it('permite disponível negativo quando os gastos superam as receitas', function () {
    $resultado = DisponivelDoMes::calcular(
        receitasCents: 100000,
        cartaoVencendoNoMesCents: 120000,
        foraDeCartaoCents: 30000,
    );

    expect($resultado->disponivel->cents())->toBe(-50000);
});

it('expõe o previsto do próximo mês (somatório das cobranças do mês seguinte)', function () {
    $resultado = DisponivelDoMes::calcular(
        receitasCents: 400000,
        cartaoVencendoNoMesCents: 120000,
        foraDeCartaoCents: 20000,
        cobrancasProximoMesCents: 30000,
    );

    expect($resultado->previstoProximoMes->cents())->toBe(30000);
});

it('previsto do próximo mês é zero por padrão', function () {
    $resultado = DisponivelDoMes::calcular(
        receitasCents: 400000,
        cartaoVencendoNoMesCents: 0,
        foraDeCartaoCents: 0,
    );

    expect($resultado->previstoProximoMes->cents())->toBe(0);
});

/* -------- Composição: atribuição por mês de vencimento (cenário do usuário) -------- */

it('atribui o gasto em cartão ao mês de vencimento: fatura aberta deste mês entra; pós-fechamento vai para o próximo', function () {
    // Cartão fecha dia 2, vence dia 10. Mês corrente = junho/2026.
    $diaFechamento = 2;
    $diaVencimento = 10;
    $mesCorrente = '2026-06';
    $mesSeguinte = '2026-07';

    // Cada gasto: [valorCents, dataCompra]
    $gastosCartao = [
        [50000, '2026-06-01'], // fatura aberta (fecha 02/jun), vence 10/jun → ENTRA em junho
        [70000, '2026-05-15'], // após fechamento de maio → fatura fecha 02/jun, vence 10/jun → junho (boleto do mês)
        [30000, '2026-06-05'], // após fechamento de junho → fatura fecha 02/jul, vence 10/jul → PRÓXIMO mês
    ];

    $cartaoNoMes = 0;
    $cartaoProximoMes = 0;
    foreach ($gastosCartao as [$valor, $data]) {
        $venc = CalculadoraDeVencimento::cartao(
            CarbonImmutable::parse($data, 'America/Sao_Paulo'),
            $diaFechamento,
            $diaVencimento,
        );

        if ($venc->format('Y-m') === $mesCorrente) {
            $cartaoNoMes += $valor;
        } elseif ($venc->format('Y-m') === $mesSeguinte) {
            $cartaoProximoMes += $valor;
        }
    }

    expect($cartaoNoMes)->toBe(120000)   // 50000 + 70000
        ->and($cartaoProximoMes)->toBe(30000);

    $resultado = DisponivelDoMes::calcular(
        receitasCents: 400000,
        cartaoVencendoNoMesCents: $cartaoNoMes,
        foraDeCartaoCents: 20000,
        cobrancasProximoMesCents: $cartaoProximoMes,
    );

    expect($resultado->disponivel->cents())->toBe(260000)        // 4000 - 1200 - 200
        ->and($resultado->previstoProximoMes->cents())->toBe(30000);
});
