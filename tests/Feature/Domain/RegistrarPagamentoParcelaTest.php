<?php

use App\Domain\Gasto\CancelarGastoManual;
use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\PagamentoNaoPermitidoException;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\Gasto\RegistrarPagamentoParcela;
use App\Models\AuditLog;
use App\Models\Card;
use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Marcar parcela como paga (F2 — CRUD, decisão do usuário 2026-07-08): ação POR
 * PARCELA, só para lançamento FORA DE CARTÃO. Grava status 'pago' + data de
 * pagamento na parcela alvo SEM tocar nas irmãs (durável, imune à regeneração da
 * edição), agrega o status da transação e registra auditoria. Escopo por usuário.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function gastoPixParcelado(User $user, CarbonImmutable $hoje): Transaction
{
    return (new RegistrarGastoManual)->confirmar(new DadosGastoManual(
        userId: $user->id,
        descricao: 'Combinado com o João',
        valorTotalCents: 30000,
        dataCompra: CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo'),
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
        parcelas: 3,
    ), $hoje);
}

it('recusa pagar parcela de lançamento cancelado (auditoria P2-2)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoPixParcelado($user, $hoje);
    (new CancelarGastoManual)->confirmar($tx->id, $user->id);
    $parcela = $tx->installments()->where('numero', 1)->first();

    // Pagar uma parcela cancelada a devolveria ao Disponível/Consumo (dinheiro 2×).
    expect(fn () => (new RegistrarPagamentoParcela)->confirmar($parcela->id, $user->id, $hoje))
        ->toThrow(PagamentoNaoPermitidoException::class);

    expect($parcela->fresh()->status_id)
        ->toBe(StatusPagamento::idFor(StatusPagamento::CANCELADO));
});

it('marca a parcela alvo como paga e grava a data de pagamento', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoPixParcelado($user, $hoje);
    $parcela = $tx->installments()->where('numero', 1)->first();

    (new RegistrarPagamentoParcela)->confirmar(
        $parcela->id,
        $user->id,
        CarbonImmutable::parse('2026-06-20', 'America/Sao_Paulo'),
    );

    $parcela->refresh();
    expect($parcela->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO))
        ->and($parcela->data_pagamento->toDateString())->toBe('2026-06-20');
});

it('não altera as parcelas irmãs', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoPixParcelado($user, $hoje);
    $alvo = $tx->installments()->where('numero', 2)->first();

    (new RegistrarPagamentoParcela)->confirmar(
        $alvo->id,
        $user->id,
        CarbonImmutable::parse('2026-07-10', 'America/Sao_Paulo'),
    );

    $irmas = $tx->installments()->where('numero', '!=', 2)->get();
    expect($irmas)->toHaveCount(2);
    foreach ($irmas as $irma) {
        expect($irma->status_id)->not->toBe(StatusPagamento::idFor(StatusPagamento::PAGO))
            ->and($irma->data_pagamento)->toBeNull();
    }
});

it('agrega a transação como pago_parcial quando só uma parcela é paga', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoPixParcelado($user, $hoje);
    $parcela = $tx->installments()->where('numero', 1)->first();

    (new RegistrarPagamentoParcela)->confirmar(
        $parcela->id,
        $user->id,
        CarbonImmutable::parse('2026-06-20', 'America/Sao_Paulo'),
    );

    expect($tx->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO_PARCIAL));
});

it('agrega a transação como pago quando todas as parcelas são pagas', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoPixParcelado($user, $hoje);
    $servico = new RegistrarPagamentoParcela;

    foreach ($tx->installments()->orderBy('numero')->get() as $parcela) {
        $servico->confirmar($parcela->id, $user->id, CarbonImmutable::parse('2026-06-20', 'America/Sao_Paulo'));
    }

    expect($tx->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));
});

it('rejeita marcar como paga uma parcela de lançamento em cartão', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    $tx = Transaction::factory()->for($user)->create([
        'card_id' => $card->id,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::CREDITO),
    ]);
    $parcela = Installment::factory()->for($tx, 'transaction')->create(['numero' => 1, 'total' => 1]);

    (new RegistrarPagamentoParcela)->confirmar(
        $parcela->id,
        $user->id,
        CarbonImmutable::parse('2026-06-20', 'America/Sao_Paulo'),
    );
})->throws(PagamentoNaoPermitidoException::class);

it('não marca parcela de lançamento de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoPixParcelado($user, $hoje);
    $parcela = $tx->installments()->first();

    (new RegistrarPagamentoParcela)->confirmar(
        $parcela->id,
        $outro->id,
        CarbonImmutable::parse('2026-06-20', 'America/Sao_Paulo'),
    );
})->throws(ModelNotFoundException::class);

it('registra auditoria do pagamento da parcela', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoPixParcelado($user, $hoje);
    $parcela = $tx->installments()->where('numero', 1)->first();

    (new RegistrarPagamentoParcela)->confirmar(
        $parcela->id,
        $user->id,
        CarbonImmutable::parse('2026-06-20', 'America/Sao_Paulo'),
    );

    $log = AuditLog::where('entidade', 'installment')->where('entidade_id', $parcela->id)
        ->where('acao', AuditLog::ACAO_PAGAR)->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($user->id);
});
