<?php

declare(strict_types=1);

use App\Domain\Calendar\RelativeDate;
use Carbon\CarbonImmutable;

/*
 * Motor financeiro — resolução determinística de datas relativas.
 * Fuso base: America/Sao_Paulo (estratégia de testes, item 5).
 * "now" é sempre injetado para tornar o teste determinístico.
 */

function nowSP(string $iso): CarbonImmutable
{
    return CarbonImmutable::parse($iso, 'America/Sao_Paulo');
}

it('resolve hoje no fuso de São Paulo', function () {
    expect(RelativeDate::resolve('hoje', nowSP('2026-06-24 10:00'))->toDateString())
        ->toBe('2026-06-24');
});

it('resolve ontem (-1 dia)', function () {
    expect(RelativeDate::resolve('ontem', nowSP('2026-06-24 10:00'))->toDateString())
        ->toBe('2026-06-23');
});

it('resolve amanhã (+1 dia), com e sem acento', function () {
    expect(RelativeDate::resolve('amanhã', nowSP('2026-06-24 10:00'))->toDateString())
        ->toBe('2026-06-25')
        ->and(RelativeDate::resolve('amanha', nowSP('2026-06-24 10:00'))->toDateString())
        ->toBe('2026-06-25');
});

it('respeita o fuso de São Paulo quando o "now" cruza a meia-noite UTC', function () {
    // 2026-06-25 02:00 UTC == 2026-06-24 23:00 em São Paulo (UTC-3)
    $now = CarbonImmutable::parse('2026-06-25 02:00', 'UTC');

    expect(RelativeDate::resolve('hoje', $now)->toDateString())->toBe('2026-06-24');
});

it('"mês que vem" cai no dia 5 do próximo mês por padrão', function () {
    expect(RelativeDate::resolve('mês que vem', nowSP('2026-06-24'))->toDateString())
        ->toBe('2026-07-05');
});

it('"mês que vem" usa o dia configurado quando informado', function () {
    expect(RelativeDate::resolve('mês que vem', nowSP('2026-06-24'), diaPadrao: 10)->toDateString())
        ->toBe('2026-07-10');
});

it('"mês que vem" faz clamp para o último dia do mês mais curto', function () {
    // dia configurado 31, mas fevereiro/2026 só tem 28 dias
    expect(RelativeDate::resolve('mês que vem', nowSP('2026-01-15'), diaPadrao: 31)->toDateString())
        ->toBe('2026-02-28');
});

it('"mês que vem" vira o ano em dezembro', function () {
    expect(RelativeDate::resolve('mês que vem', nowSP('2026-12-20'))->toDateString())
        ->toBe('2027-01-05');
});

it('rejeita termo desconhecido', function () {
    RelativeDate::resolve('semana que vem', nowSP('2026-06-24'));
})->throws(InvalidArgumentException::class);
