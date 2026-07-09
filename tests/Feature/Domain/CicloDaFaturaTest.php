<?php

declare(strict_types=1);

use App\Domain\FaturaCartao\CicloDaFatura;
use Carbon\CarbonImmutable;

/*
 * Ciclo da fatura de um cartão numa competência (spec FE §7.13). Derivação DETERMINÍSTICA e
 * consistente com a regra documentada (doc 03 §4.2 / CalculadoraDeVencimento): competência = mês
 * de VENCIMENTO; fecha = fechamento que antecede esse vencimento; aberta enquanto "hoje" não
 * passou do fechamento. Puro, sem tocar o banco; "hoje" injetado (regra 4/5).
 */

it('deriva fecha no mês anterior quando o vencimento é antes do fechamento (fecha 28 · vence 5)', function () {
    // Reproduz o exemplo da spec: competência agosto (vence 05/08), fecha 28/07.
    $ciclo = CicloDaFatura::paraCompetencia(28, 5, '2026-08', CarbonImmutable::parse('2026-07-09', 'America/Sao_Paulo'));

    expect($ciclo->fecha->format('Y-m-d'))->toBe('2026-07-28')
        ->and($ciclo->vence->format('Y-m-d'))->toBe('2026-08-05')
        ->and($ciclo->aberta)->toBeTrue(); // hoje 09/07 ≤ fecha 28/07
});

it('deriva fecha no mesmo mês quando o vencimento é depois do fechamento (fecha 5 · vence 15)', function () {
    $ciclo = CicloDaFatura::paraCompetencia(5, 15, '2026-08', CarbonImmutable::parse('2026-07-09', 'America/Sao_Paulo'));

    expect($ciclo->fecha->format('Y-m-d'))->toBe('2026-08-05')
        ->and($ciclo->vence->format('Y-m-d'))->toBe('2026-08-15');
});

it('marca fechada quando hoje já passou do fechamento', function () {
    $ciclo = CicloDaFatura::paraCompetencia(28, 5, '2026-08', CarbonImmutable::parse('2026-07-29', 'America/Sao_Paulo'));

    expect($ciclo->aberta)->toBeFalse(); // hoje 29/07 > fecha 28/07
});

it('clampa o dia ao fim do mês (dia 31 em fevereiro)', function () {
    // fecha 31 · vence 31, competência fevereiro: vencimento ≥ fechamento → mesmo mês; ambos
    // clampados ao último dia (28).
    $ciclo = CicloDaFatura::paraCompetencia(31, 31, '2026-02', CarbonImmutable::parse('2026-02-01', 'America/Sao_Paulo'));

    expect($ciclo->fecha->format('Y-m-d'))->toBe('2026-02-28')
        ->and($ciclo->vence->format('Y-m-d'))->toBe('2026-02-28');
});
