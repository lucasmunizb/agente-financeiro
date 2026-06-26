<?php

use App\Models\Account;
use App\Models\Card;
use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * transactions — lançamento financeiro (doc 04 / doc 03 §4.6).
 * Dinheiro em centavos (valor_total_cents BIGINT); origem auditável; isolado por
 * usuário; soft delete (LGPD). O valor por parcela NÃO é armazenado (derivado).
 */

uses(RefreshDatabase::class);

it('pertence a usuário, forma de pagamento e status', function () {
    $tx = Transaction::factory()->create();

    expect($tx->user)->toBeInstanceOf(User::class)
        ->and($tx->paymentMethod)->toBeInstanceOf(PaymentMethod::class)
        ->and($tx->status)->toBeInstanceOf(StatusPagamento::class);
});

it('opcionalmente referencia cartão e conta', function () {
    $card = Card::factory()->create();
    $account = Account::factory()->create();

    $noCartao = Transaction::factory()->create(['card_id' => $card->id]);
    $naConta = Transaction::factory()->create(['account_id' => $account->id]);
    $semVinculo = Transaction::factory()->create();

    expect($noCartao->card)->toBeInstanceOf(Card::class)
        ->and($naConta->account)->toBeInstanceOf(Account::class)
        ->and($semVinculo->card)->toBeNull()
        ->and($semVinculo->account)->toBeNull();
});

it('trata valor_total_cents como inteiro e data_compra como data', function () {
    $tx = Transaction::factory()->create([
        'valor_total_cents' => 123456,
        'data_compra' => '2026-06-10',
    ]);

    expect($tx->fresh()->valor_total_cents)->toBe(123456)
        ->and($tx->fresh()->data_compra)->toBeInstanceOf(CarbonImmutable::class)
        ->and($tx->fresh()->data_compra->toDateString())->toBe('2026-06-10');
});

it('guarda a origem do lançamento', function () {
    $tx = Transaction::factory()->create(['origem' => 'manual']);

    expect($tx->fresh()->origem)->toBe('manual');
});

it('assume BRL como moeda padrão', function () {
    $tx = Transaction::factory()->create();

    expect($tx->fresh()->moeda)->toBe('BRL');
});

it('faz soft delete (exclusão lógica)', function () {
    $tx = Transaction::factory()->create();

    $tx->delete();

    expect(Transaction::find($tx->id))->toBeNull()
        ->and(Transaction::withTrashed()->find($tx->id))->not->toBeNull();
});

it('tem muitas parcelas', function () {
    $tx = Transaction::factory()->create();
    Installment::factory()->for($tx)->create(['numero' => 1, 'total' => 2]);
    Installment::factory()->for($tx)->create(['numero' => 2, 'total' => 2]);

    expect($tx->installments)->toHaveCount(2);
});
