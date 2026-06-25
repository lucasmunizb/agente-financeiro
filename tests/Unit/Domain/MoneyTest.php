<?php

use App\Domain\Shared\Money;

/*
 * Motor financeiro — value object Money.
 * Regras invioláveis: dinheiro em centavos inteiros (BIGINT), nunca float;
 * formatação pt-BR só na borda.
 */

it('guarda e devolve centavos inteiros', function () {
    expect(Money::fromCents(3500)->cents())->toBe(3500);
});

it('aceita valores negativos para estornos/reembolsos', function () {
    expect(Money::fromCents(-500)->cents())->toBe(-500);
});

it('cria a partir de reais inteiros', function () {
    expect(Money::fromReais(35)->cents())->toBe(3500);
});

/* -------- Parsing humano (pt-BR): vírgula = decimal, ponto = milhar -------- */

it('interpreta "35 conto" como 3500 centavos', function () {
    expect(Money::fromHuman('35 conto')->cents())->toBe(3500);
});

it('interpreta "35,50" como 3550 centavos', function () {
    expect(Money::fromHuman('35,50')->cents())->toBe(3550);
});

it('interpreta "1.234,56" como 123456 centavos', function () {
    expect(Money::fromHuman('1.234,56')->cents())->toBe(123456);
});

it('interpreta "R$ 10" como 1000 centavos', function () {
    expect(Money::fromHuman('R$ 10')->cents())->toBe(1000);
});

it('interpreta inteiro sem decimal como reais', function () {
    expect(Money::fromHuman('1000')->cents())->toBe(100000);
});

it('completa um único dígito decimal (vírgula) corretamente', function () {
    expect(Money::fromHuman('35,5')->cents())->toBe(3550);
});

it('rejeita texto sem nenhum número', function () {
    Money::fromHuman('sem valor');
})->throws(InvalidArgumentException::class);

/* -------- Formatação pt-BR (somente na borda) -------- */

it('formata centavos em pt-BR', function () {
    expect(Money::fromCents(123456)->formatBRL())->toBe('R$ 1.234,56');
});

it('formata valor cheio sem centavos', function () {
    expect(Money::fromCents(3500)->formatBRL())->toBe('R$ 35,00');
});

it('formata zero', function () {
    expect(Money::fromCents(0)->formatBRL())->toBe('R$ 0,00');
});

it('formata negativo com sinal antes do símbolo', function () {
    expect(Money::fromCents(-500)->formatBRL())->toBe('-R$ 5,00');
});

/* -------- Aritmética determinística (imutável) -------- */

it('soma sem mutar o original', function () {
    $a = Money::fromCents(1000);
    $b = $a->plus(Money::fromCents(250));
    expect($b->cents())->toBe(1250)
        ->and($a->cents())->toBe(1000);
});

it('subtrai', function () {
    expect(Money::fromCents(1000)->minus(Money::fromCents(250))->cents())->toBe(750);
});

it('multiplica por um inteiro', function () {
    expect(Money::fromCents(1000)->times(3)->cents())->toBe(3000);
});

it('compara igualdade por centavos', function () {
    expect(Money::fromCents(1000)->equals(Money::fromCents(1000)))->toBeTrue()
        ->and(Money::fromCents(1000)->equals(Money::fromCents(999)))->toBeFalse();
});

/* -------- allocate: distribui total em N parcelas sem perder centavos -------- */

it('distribui 100 centavos em 3 parcelas sem perder centavos', function () {
    $partes = Money::fromCents(100)->allocate(3);

    expect($partes)->toHaveCount(3)
        ->and(array_map(fn (Money $m) => $m->cents(), $partes))->toBe([34, 33, 33]);

    $soma = array_sum(array_map(fn (Money $m) => $m->cents(), $partes));
    expect($soma)->toBe(100);
});

it('distribui valor exato igualmente', function () {
    $partes = Money::fromCents(9000)->allocate(3);
    expect(array_map(fn (Money $m) => $m->cents(), $partes))->toBe([3000, 3000, 3000]);
});

it('a soma das parcelas sempre reconstrói o total', function () {
    foreach ([12000, 10000, 99999, 1, 7] as $total) {
        foreach ([1, 2, 3, 4, 6, 12] as $n) {
            $soma = array_sum(array_map(
                fn (Money $m) => $m->cents(),
                Money::fromCents($total)->allocate($n)
            ));
            expect($soma)->toBe($total);
        }
    }
});

it('exige pelo menos uma parcela no allocate', function () {
    Money::fromCents(100)->allocate(0);
})->throws(InvalidArgumentException::class);
