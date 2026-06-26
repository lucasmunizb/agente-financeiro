<?php

use App\Domain\Parcelamento\GeradorDeParcelas;
use App\Domain\Parcelamento\Parcela;
use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;

/*
 * Motor de parcelamento (doc 03 §4.1 e §4.2) — determinístico, sem DB.
 * Regras invioláveis: dinheiro em centavos; valor por parcela DERIVADO do total
 * (Money::allocate), nunca duplicado; a parcela vigente é SEMPRE calculada na
 * exibição, nunca fixada.
 */

function venc(string $data): CarbonImmutable
{
    return CarbonImmutable::parse($data, 'America/Sao_Paulo')->startOfDay();
}

/* -------- Geração: a partir da 1/N, gerar todas as N parcelas futuras -------- */

it('gera exatamente N parcelas a partir da 1/N', function () {
    $parcelas = GeradorDeParcelas::gerar(120000, 3, venc('2026-01-10'));

    expect($parcelas)->toHaveCount(3)
        ->and($parcelas[0])->toBeInstanceOf(Parcela::class);
});

it('numera as parcelas sequencialmente com o total correto', function () {
    $parcelas = GeradorDeParcelas::gerar(120000, 3, venc('2026-01-10'));

    expect(array_map(fn (Parcela $p) => $p->numero, $parcelas))->toBe([1, 2, 3])
        ->and(array_map(fn (Parcela $p) => $p->total, $parcelas))->toBe([3, 3, 3]);
});

it('deriva o valor de cada parcela do total via allocate (divisão exata)', function () {
    $parcelas = GeradorDeParcelas::gerar(120000, 3, venc('2026-01-10'));

    expect(array_map(fn (Parcela $p) => $p->valor->cents(), $parcelas))
        ->toBe([40000, 40000, 40000]);
});

it('distribui o resto nas primeiras parcelas sem perder centavos', function () {
    $parcelas = GeradorDeParcelas::gerar(100, 3, venc('2026-01-10'));

    $valores = array_map(fn (Parcela $p) => $p->valor->cents(), $parcelas);

    expect($valores)->toBe([34, 33, 33])
        ->and(array_sum($valores))->toBe(100);
});

it('a soma das parcelas sempre reconstrói o total', function () {
    foreach ([120000, 100000, 99999, 1, 7, 250075] as $total) {
        foreach ([1, 2, 3, 4, 6, 12] as $n) {
            $soma = array_sum(array_map(
                fn (Parcela $p) => $p->valor->cents(),
                GeradorDeParcelas::gerar($total, $n, venc('2026-01-10'))
            ));
            expect($soma)->toBe($total);
        }
    }
});

/* -------- Vencimentos: +1 mês por parcela a partir do primeiro -------- */

it('avança o vencimento em +1 mês a cada parcela', function () {
    $parcelas = GeradorDeParcelas::gerar(90000, 3, venc('2026-01-10'));

    expect(array_map(fn (Parcela $p) => $p->vencimento->toDateString(), $parcelas))
        ->toBe(['2026-01-10', '2026-02-10', '2026-03-10']);
});

it('faz clamp para o último dia de meses mais curtos, sem drift', function () {
    // 31/01 → 28/02 → 31/03: cada parcela é calculada a partir do primeiro
    // vencimento (i meses), nunca encadeada (evita drift para o dia 28).
    $parcelas = GeradorDeParcelas::gerar(90000, 3, venc('2026-01-31'));

    expect(array_map(fn (Parcela $p) => $p->vencimento->toDateString(), $parcelas))
        ->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

it('vira o ano corretamente', function () {
    $parcelas = GeradorDeParcelas::gerar(60000, 3, venc('2026-11-15'));

    expect(array_map(fn (Parcela $p) => $p->vencimento->toDateString(), $parcelas))
        ->toBe(['2026-11-15', '2026-12-15', '2027-01-15']);
});

it('gera uma única parcela quando N = 1', function () {
    $parcelas = GeradorDeParcelas::gerar(5000, 1, venc('2026-06-20'));

    expect($parcelas)->toHaveCount(1)
        ->and($parcelas[0]->numero)->toBe(1)
        ->and($parcelas[0]->total)->toBe(1)
        ->and($parcelas[0]->valor->cents())->toBe(5000)
        ->and($parcelas[0]->vencimento->toDateString())->toBe('2026-06-20');
});

it('rejeita número de parcelas menor que 1', function () {
    GeradorDeParcelas::gerar(5000, 0, venc('2026-06-20'));
})->throws(InvalidArgumentException::class);

/* -------- Parcela vigente: SEMPRE calculada, nunca fixada -------- */

it('calcula a parcela vigente no mês do primeiro vencimento como 1', function () {
    $vigente = GeradorDeParcelas::parcelaVigente(venc('2026-01-10'), 6, venc('2026-01-25'));

    expect($vigente)->toBe(1);
});

it('avança a parcela vigente +1 a cada mês', function () {
    $primeiro = venc('2026-01-10');

    expect(GeradorDeParcelas::parcelaVigente($primeiro, 6, venc('2026-02-01')))->toBe(2)
        ->and(GeradorDeParcelas::parcelaVigente($primeiro, 6, venc('2026-04-10')))->toBe(4);
});

it('faz clamp da parcela vigente no total quando a referência passa do fim', function () {
    $vigente = GeradorDeParcelas::parcelaVigente(venc('2026-01-10'), 3, venc('2026-12-10'));

    expect($vigente)->toBe(3);
});

it('faz clamp da parcela vigente em 1 quando a referência é anterior ao primeiro', function () {
    $vigente = GeradorDeParcelas::parcelaVigente(venc('2026-05-10'), 4, venc('2026-01-10'));

    expect($vigente)->toBe(1);
});
