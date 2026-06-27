<?php

declare(strict_types=1);

use App\Domain\IA\Custo\CalculadoraDeCustoIA;

/*
 * Custo estimado de uma chamada de IA (doc 02 §3.6). Determinístico: tokens × tabela de
 * preços (centavos por 1.000.000 de tokens) → centavos inteiros (regra inviolável 5).
 * Calculadora pura — recebe a tabela; sem dependência de framework/config.
 */

/** Tabela de exemplo: centavos por 1M tokens. */
function tabelaCusto(): array
{
    return [
        'anthropic' => [
            'claude-teste' => ['entrada' => 300, 'saida' => 1500],
        ],
    ];
}

it('estima o custo em centavos: tokens × preço por milhão', function () {
    $calc = new CalculadoraDeCustoIA(tabelaCusto());

    // 1M entrada (300) + 1M saída (1500) = 1800 centavos
    expect($calc->centavos('anthropic', 'claude-teste', 1_000_000, 1_000_000))->toBe(1800);
});

it('é proporcional a frações de milhão de tokens', function () {
    $calc = new CalculadoraDeCustoIA(tabelaCusto());

    // 500k entrada = 150 ; 200k saída = 300 → 450
    expect($calc->centavos('anthropic', 'claude-teste', 500_000, 200_000))->toBe(450);
});

it('arredonda para o centavo mais próximo', function () {
    $calc = new CalculadoraDeCustoIA(tabelaCusto());

    // 1234 entrada × 300 / 1e6 = 0,3702 → 0 centavo ; saída 0 → total 0
    expect($calc->centavos('anthropic', 'claude-teste', 1234, 0))->toBe(0);
    // 2000 saída × 1500 / 1e6 = 3,0 → 3 centavos
    expect($calc->centavos('anthropic', 'claude-teste', 0, 2000))->toBe(3);
});

it('devolve 0 quando provedor/modelo não está na tabela (não inventa custo)', function () {
    $calc = new CalculadoraDeCustoIA(tabelaCusto());

    expect($calc->centavos('desconhecido', 'modelo-x', 1_000_000, 1_000_000))->toBe(0);
    expect($calc->centavos('anthropic', 'modelo-fora-da-tabela', 1_000_000, 0))->toBe(0);
});

it('sempre devolve um inteiro', function () {
    $calc = new CalculadoraDeCustoIA(tabelaCusto());

    expect($calc->centavos('anthropic', 'claude-teste', 333_333, 777_777))->toBeInt();
});
