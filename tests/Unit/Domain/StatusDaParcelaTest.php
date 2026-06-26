<?php

use App\Domain\Gasto\StatusDaParcela;
use App\Models\StatusPagamento;
use Carbon\CarbonImmutable;

/*
 * Status inicial de uma parcela derivado pela data (decisão do usuário, §4.4):
 * vencimento futuro → agendado; vence hoje → aberto; já venceu → vencido.
 * Determinístico: o "hoje" é sempre injetado.
 */

function emSP(string $data): CarbonImmutable
{
    return CarbonImmutable::parse($data, 'America/Sao_Paulo');
}

it('agenda parcela com vencimento futuro', function () {
    expect(StatusDaParcela::para(emSP('2026-08-10'), emSP('2026-06-25')))
        ->toBe(StatusPagamento::AGENDADO);
});

it('marca como aberto a parcela que vence hoje', function () {
    expect(StatusDaParcela::para(emSP('2026-06-25'), emSP('2026-06-25')))
        ->toBe(StatusPagamento::ABERTO);
});

it('marca como vencido a parcela cujo vencimento já passou', function () {
    expect(StatusDaParcela::para(emSP('2026-05-10'), emSP('2026-06-25')))
        ->toBe(StatusPagamento::VENCIDO);
});

it('ignora a hora ao comparar (compara só a data)', function () {
    expect(StatusDaParcela::para(emSP('2026-06-25 23:00'), emSP('2026-06-25 01:00')))
        ->toBe(StatusPagamento::ABERTO);
});
