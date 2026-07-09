<?php

declare(strict_types=1);

use App\Domain\Shared\OpaqueId;
use App\Models\Card;
use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Borda web de "marcar parcela como paga" (FE §7.8 / decisão do usuário 2026-07-08).
 * POST server-rendered da tela de detalhe: valida a data, delega ao domínio já
 * testado ({@see App\Domain\Gasto\RegistrarPagamentoParcela}) e volta ao detalhe.
 * Id da parcela SEMPRE por token opaco (regra dos ids criptografados); escopo por
 * usuário (404 para parcela alheia); cartão é recusado.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function parcelaPixEmAberto(User $user): Installment
{
    $tx = Transaction::factory()->for($user)->create([
        'valor_total_cents' => 30000,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'card_id' => null,
    ]);

    return Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => '2026-06-20',
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
}

it('exige login', function () {
    $parcela = parcelaPixEmAberto(User::factory()->create());

    $this->post(route('lancamentos.parcela.pagar', OpaqueId::encode($parcela->id)), [
        'data_pagamento' => '2026-06-20',
    ])->assertRedirect(route('login'));
});

it('marca a parcela como paga e grava a data, voltando ao detalhe', function () {
    $user = User::factory()->create();
    $parcela = parcelaPixEmAberto($user);

    // O token opaco é não-determinístico (IV aleatório): compara pelo id decodificado, não string.
    $resposta = $this->actingAs($user)
        ->post(route('lancamentos.parcela.pagar', OpaqueId::encode($parcela->id)), [
            'data_pagamento' => '2026-06-20',
        ]);

    $resposta->assertRedirect();
    preg_match('#/lancamentos/([A-Za-z0-9_-]+)$#', $resposta->headers->get('Location'), $m);
    expect(OpaqueId::decode($m[1]))->toBe($parcela->transaction_id);

    $parcela->refresh();
    expect($parcela->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO))
        ->and($parcela->data_pagamento->toDateString())->toBe('2026-06-20');
});

it('exige a data de pagamento', function () {
    $user = User::factory()->create();
    $parcela = parcelaPixEmAberto($user);

    $this->actingAs($user)
        ->post(route('lancamentos.parcela.pagar', OpaqueId::encode($parcela->id)), [])
        ->assertSessionHasErrors('data_pagamento');

    expect($parcela->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO));
});

it('recusa marcar como paga uma parcela de cartão', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    $tx = Transaction::factory()->for($user)->create([
        'card_id' => $card->id,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::CREDITO),
    ]);
    $parcela = Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);

    $this->actingAs($user)
        ->post(route('lancamentos.parcela.pagar', OpaqueId::encode($parcela->id)), [
            'data_pagamento' => '2026-06-20',
        ])
        ->assertSessionHasErrors();

    expect($parcela->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO));
});

it('devolve 404 para parcela de outro usuário', function () {
    $user = User::factory()->create();
    $alheia = parcelaPixEmAberto(User::factory()->create());

    $this->actingAs($user)
        ->post(route('lancamentos.parcela.pagar', OpaqueId::encode($alheia->id)), [
            'data_pagamento' => '2026-06-20',
        ])
        ->assertNotFound();
});

it('recusa (404) o id REAL no path — só token criptografado paga', function () {
    $user = User::factory()->create();
    $parcela = parcelaPixEmAberto($user);

    $this->actingAs($user)
        ->post("/lancamentos/parcela/{$parcela->id}/pagar", ['data_pagamento' => '2026-06-20'])
        ->assertNotFound();
});
