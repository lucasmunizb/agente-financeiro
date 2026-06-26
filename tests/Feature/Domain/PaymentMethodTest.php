<?php

use App\Models\PaymentMethod;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * payment_methods — tabela de referência (doc 04 / doc 03 §4.6).
 * Conjunto fixo: credito, debito, pix, dinheiro. Lançamentos referenciam por FK,
 * nunca por string solta. Sem user_id (referência global).
 */

uses(RefreshDatabase::class);

it('seed cria exatamente os quatro tipos de forma de pagamento', function () {
    $this->seed(PaymentMethodSeeder::class);

    expect(PaymentMethod::pluck('tipo')->sort()->values()->all())
        ->toBe(['credito', 'debito', 'dinheiro', 'pix']);
});

it('é idempotente: rodar o seed duas vezes não duplica', function () {
    $this->seed(PaymentMethodSeeder::class);
    $this->seed(PaymentMethodSeeder::class);

    expect(PaymentMethod::count())->toBe(4);
});

it('não permite tipo duplicado (unique)', function () {
    PaymentMethod::create(['tipo' => 'pix']);

    expect(fn () => PaymentMethod::create(['tipo' => 'pix']))
        ->toThrow(QueryException::class);
});

it('resolve o id pelo tipo', function () {
    $this->seed(PaymentMethodSeeder::class);

    $id = PaymentMethod::idFor('credito');

    expect($id)->toBe(PaymentMethod::where('tipo', 'credito')->value('id'));
});
