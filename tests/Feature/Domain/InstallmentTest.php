<?php

use App\Domain\Shared\Money;
use App\Models\Installment;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * installments — parcelas (doc 04 / doc 03 §4.1).
 * Estrutura N/total + vencimento + status. SEM coluna de valor: o valor de cada
 * parcela é DERIVADO do valor_total_cents da transaction (Money::allocate).
 * A parcela vigente é calculada na exibição, nunca fixada.
 */

uses(RefreshDatabase::class);

it('pertence a uma transaction e tem status', function () {
    $parcela = Installment::factory()->create();

    expect($parcela->transaction)->toBeInstanceOf(Transaction::class)
        ->and($parcela->status)->toBeInstanceOf(StatusPagamento::class);
});

it('trata numero/total como inteiros e vencimento como data', function () {
    $parcela = Installment::factory()->create([
        'numero' => 2,
        'total' => 3,
        'vencimento' => '2026-07-10',
    ]);

    expect($parcela->fresh())
        ->numero->toBe(2)
        ->total->toBe(3)
        ->and($parcela->fresh()->vencimento)->toBeInstanceOf(CarbonImmutable::class)
        ->and($parcela->fresh()->vencimento->toDateString())->toBe('2026-07-10');
});

it('deriva o valor da parcela do total da transaction via allocate', function () {
    // 100000 em 3 → [33334, 33333, 33333] (resto na primeira)
    $tx = Transaction::factory()->create(['valor_total_cents' => 100000]);
    $p1 = Installment::factory()->for($tx)->create(['numero' => 1, 'total' => 3]);
    $p2 = Installment::factory()->for($tx)->create(['numero' => 2, 'total' => 3]);
    $p3 = Installment::factory()->for($tx)->create(['numero' => 3, 'total' => 3]);

    expect($p1->valor())->toBeInstanceOf(Money::class)
        ->and($p1->valor()->cents())->toBe(33334)
        ->and($p2->valor()->cents())->toBe(33333)
        ->and($p3->valor()->cents())->toBe(33333)
        ->and($p1->valor()->cents() + $p2->valor()->cents() + $p3->valor()->cents())->toBe(100000);
});

it('não permite numero de parcela duplicado na mesma transaction', function () {
    $tx = Transaction::factory()->create();
    Installment::factory()->for($tx)->create(['numero' => 1, 'total' => 2]);

    expect(fn () => Installment::factory()->for($tx)->create(['numero' => 1, 'total' => 2]))
        ->toThrow(QueryException::class);
});

it('é removida em cascata quando a transaction é apagada de fato', function () {
    $tx = Transaction::factory()->create();
    $parcela = Installment::factory()->for($tx)->create(['numero' => 1, 'total' => 1]);

    $tx->forceDelete();

    expect(Installment::find($parcela->id))->toBeNull();
});
