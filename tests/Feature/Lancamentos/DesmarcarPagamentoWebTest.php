<?php

declare(strict_types=1);

use App\Domain\Shared\OpaqueId;
use App\Models\Card;
use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Borda web de DESMARCAR pagamento (decisão do usuário 2026-07-21): o clique errado no
 * "marcar como pago" precisa ter conserto pela interface. Dois alvos, um por tipo de linha
 * do extrato — parcela de lançamento e ocorrência de recorrência —, cada um delegando ao
 * domínio já testado ({@see App\Domain\Gasto\ReverterPagamentoParcela} e
 * {@see App\Domain\Recorrencia\ReverterPagamentoOcorrencia}).
 *
 * Invariantes da borda: id SEMPRE por token opaco (id em claro ⇒ 404), escopo por usuário
 * (404 para item alheio), cartão recusado com erro na sessão (nunca 500).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function parcelaPixPaga(User $user): Installment
{
    $tx = Transaction::factory()->for($user)->create([
        'valor_total_cents' => 30000,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'card_id' => null,
        'status_id' => StatusPagamento::idFor(StatusPagamento::PAGO),
    ]);

    return Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => '2026-06-20',
        'status_id' => StatusPagamento::idFor(StatusPagamento::PAGO),
        'data_pagamento' => '2026-06-20',
    ]);
}

function ocorrenciaPixPaga(User $user, array $over = []): RecurrenceOccurrence
{
    $rec = Recurrence::factory()->create([
        'user_id' => $user->id,
        'payment_method_id' => $over['payment_method_id'] ?? PaymentMethod::idFor(PaymentMethod::PIX),
        'card_id' => $over['card_id'] ?? null,
        'proxima_em' => null,
    ]);

    return RecurrenceOccurrence::factory()->create([
        'user_id' => $user->id,
        'recurrence_id' => $rec->id,
        'competencia' => '2026-07',
        'descricao' => 'Aluguel',
        'valor_cents' => 150000,
        'data_cobranca' => '2026-07-05',
        'vencimento' => '2026-07-05',
        'payment_method_id' => $rec->payment_method_id,
        'card_id' => $rec->card_id,
        'status_id' => StatusPagamento::idFor(StatusPagamento::PAGO),
        'data_pagamento' => '2026-07-06 12:00:00',
    ]);
}

// ── Parcela ────────────────────────────────────────────────────────────────────

it('exige login para desmarcar parcela', function () {
    $parcela = parcelaPixPaga(User::factory()->create());

    $this->post(route('lancamentos.parcela.desmarcar', OpaqueId::encode($parcela->id)))
        ->assertRedirect(route('login'));
});

it('desmarca a parcela paga e limpa a data de pagamento', function () {
    $user = User::factory()->create();
    $parcela = parcelaPixPaga($user);

    $this->actingAs($user)
        ->post(route('lancamentos.parcela.desmarcar', OpaqueId::encode($parcela->id)))
        ->assertRedirect();

    $parcela->refresh();
    expect($parcela->status_id)->not->toBe(StatusPagamento::idFor(StatusPagamento::PAGO))
        ->and($parcela->data_pagamento)->toBeNull();
});

it('recusa desmarcar parcela de cartão sem estourar erro', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    $tx = Transaction::factory()->for($user)->create([
        'card_id' => $card->id,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::CREDITO),
    ]);
    $parcela = Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'status_id' => StatusPagamento::idFor(StatusPagamento::PAGO),
    ]);

    $this->actingAs($user)
        ->post(route('lancamentos.parcela.desmarcar', OpaqueId::encode($parcela->id)))
        ->assertSessionHasErrors();

    expect($parcela->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));
});

it('devolve 404 ao desmarcar parcela de outro usuário', function () {
    $user = User::factory()->create();
    $alheia = parcelaPixPaga(User::factory()->create());

    $this->actingAs($user)
        ->post(route('lancamentos.parcela.desmarcar', OpaqueId::encode($alheia->id)))
        ->assertNotFound();

    expect($alheia->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));
});

it('recusa (404) o id REAL da parcela no path', function () {
    $user = User::factory()->create();
    $parcela = parcelaPixPaga($user);

    $this->actingAs($user)
        ->post("/lancamentos/parcela/{$parcela->id}/desmarcar")
        ->assertNotFound();
});

// ── Ocorrência de recorrência ──────────────────────────────────────────────────

it('exige login para desmarcar ocorrência', function () {
    $oc = ocorrenciaPixPaga(User::factory()->create());

    $this->post(route('lancamentos.recorrencia.desmarcar', OpaqueId::encode($oc->id)))
        ->assertRedirect(route('login'));
});

it('desmarca a ocorrência paga e limpa a data de pagamento', function () {
    $user = User::factory()->create();
    $oc = ocorrenciaPixPaga($user);

    $this->actingAs($user)
        ->from(route('lancamentos'))
        ->post(route('lancamentos.recorrencia.desmarcar', OpaqueId::encode($oc->id)))
        ->assertRedirect(route('lancamentos'))
        ->assertSessionHas('sucesso');

    $oc->refresh();
    expect($oc->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO))
        ->and($oc->data_pagamento)->toBeNull();
});

it('é idempotente na borda: desmarcar duas vezes não vira erro', function () {
    $user = User::factory()->create();
    $oc = ocorrenciaPixPaga($user);
    $url = route('lancamentos.recorrencia.desmarcar', OpaqueId::encode($oc->id));

    $this->actingAs($user)->from(route('lancamentos'))->post($url);
    $this->actingAs($user)->from(route('lancamentos'))->post($url)
        ->assertRedirect(route('lancamentos'))
        ->assertSessionHasNoErrors();

    expect($oc->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO));
});

it('recusa desmarcar ocorrência de cartão sem estourar erro', function () {
    $user = User::factory()->create();
    $card = Card::factory()->create(['user_id' => $user->id]);
    $oc = ocorrenciaPixPaga($user, [
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::CREDITO),
        'card_id' => $card->id,
    ]);

    $this->actingAs($user)
        ->from(route('lancamentos'))
        ->post(route('lancamentos.recorrencia.desmarcar', OpaqueId::encode($oc->id)))
        ->assertSessionHasErrors();

    expect($oc->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));
});

it('devolve 404 ao desmarcar ocorrência de outro usuário', function () {
    $user = User::factory()->create();
    $alheia = ocorrenciaPixPaga(User::factory()->create());

    $this->actingAs($user)
        ->post(route('lancamentos.recorrencia.desmarcar', OpaqueId::encode($alheia->id)))
        ->assertNotFound();

    expect($alheia->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));
});

it('recusa (404) o id REAL da ocorrência no path', function () {
    $user = User::factory()->create();
    $oc = ocorrenciaPixPaga($user);

    $this->actingAs($user)
        ->post("/lancamentos/recorrencia/{$oc->id}/desmarcar")
        ->assertNotFound();
});
