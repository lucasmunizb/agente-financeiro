<?php

use App\Domain\Orcamento\Orcamento;
use App\Domain\Orcamento\ResultadoOrcamento;

/*
 * Orçamento mensal geral (doc 08 §6 / doc 04) — avaliação determinística.
 * Compara o consumido contra o limite; sem disparo de alerta (decisão de escopo).
 */

it('calcula restante e não estoura quando consumo < limite', function () {
    $resultado = Orcamento::avaliar(limiteCents: 300000, consumidoCents: 120000);

    expect($resultado)->toBeInstanceOf(ResultadoOrcamento::class)
        ->and($resultado->limite->cents())->toBe(300000)
        ->and($resultado->consumido->cents())->toBe(120000)
        ->and($resultado->restante->cents())->toBe(180000)
        ->and($resultado->estourou)->toBeFalse()
        ->and($resultado->percentual())->toBe(40.0);
});

it('estoura quando consumo > limite (restante negativo)', function () {
    $resultado = Orcamento::avaliar(limiteCents: 100000, consumidoCents: 130000);

    expect($resultado->restante->cents())->toBe(-30000)
        ->and($resultado->estourou)->toBeTrue()
        ->and($resultado->percentual())->toBe(130.0);
});

it('não estoura quando consumo é exatamente o limite', function () {
    $resultado = Orcamento::avaliar(limiteCents: 100000, consumidoCents: 100000);

    expect($resultado->estourou)->toBeFalse()
        ->and($resultado->restante->cents())->toBe(0)
        ->and($resultado->percentual())->toBe(100.0);
});

it('com limite zero não estoura sem consumo e percentual é 0', function () {
    $resultado = Orcamento::avaliar(limiteCents: 0, consumidoCents: 0);

    expect($resultado->estourou)->toBeFalse()
        ->and($resultado->percentual())->toBe(0.0);
});

it('com limite zero e consumo positivo estoura', function () {
    $resultado = Orcamento::avaliar(limiteCents: 0, consumidoCents: 5000);

    expect($resultado->estourou)->toBeTrue()
        ->and($resultado->restante->cents())->toBe(-5000)
        ->and($resultado->percentual())->toBe(0.0);
});
