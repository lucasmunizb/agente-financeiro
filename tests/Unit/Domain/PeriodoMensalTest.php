<?php

use App\Domain\Shared\PeriodoMensal;
use Carbon\CarbonImmutable;

/*
 * PeriodoMensal — janela [início, fim] de um mês civil no fuso base (America/Sao_Paulo).
 * Parseia "YYYY-MM"; usado para somar receitas e gastos do mês.
 */

it('resolve início e fim do mês a partir de YYYY-MM', function () {
    $periodo = PeriodoMensal::fromString('2026-02');

    expect($periodo->mes)->toBe('2026-02')
        ->and($periodo->inicio->toDateString())->toBe('2026-02-01')
        ->and($periodo->fim->toDateString())->toBe('2026-02-28');
});

it('trata corretamente meses de 31 dias e viradas de ano', function () {
    expect(PeriodoMensal::fromString('2026-01')->fim->toDateString())->toBe('2026-01-31')
        ->and(PeriodoMensal::fromString('2026-12')->fim->toDateString())->toBe('2026-12-31');
});

it('rejeita formato inválido', function () {
    expect(fn () => PeriodoMensal::fromString('2026/02'))
        ->toThrow(InvalidArgumentException::class);
});

it('indica se uma data pertence ao mês', function () {
    $periodo = PeriodoMensal::fromString('2026-02');

    expect($periodo->contem(CarbonImmutable::parse('2026-02-15')))->toBeTrue()
        ->and($periodo->contem(CarbonImmutable::parse('2026-03-01')))->toBeFalse();
});
