<?php

declare(strict_types=1);

use App\Domain\Confirmacao\EnfileirarConfirmacao;
use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Recorrencia\PagarRecorrenciaPendente;
use App\Models\PaymentMethod;
use App\Models\PendingConfirmation;
use App\Models\Recurrence;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * "Marcar como pago" uma ocorrência de recorrência que está na fila (confirmação pendente,
 * origem `recorrencia`). Compõe o que já existe (regra 4, sem recalcular): CONFIRMA o pendente
 * ({@see ConfirmarPendente} → materializa o lançamento com o `recurrence_id` e resolve a fila)
 * e MARCA a parcela como PAGA ({@see RegistrarPagamentoParcela}). Fila e extrato coexistem:
 * pagar aqui resolve a confirmação pendente. A recorrência segue ativa (o ponteiro do próximo
 * mês é assunto do agendador — não recua aqui). Escopo estrito por usuário; idempotente.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

/** "Agora" fixo do usuário: 09/07/2026. */
function agoraPagamentoRec(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');
}

/** Recorrência mensal ativa fora de cartão (PIX). */
function recorrenciaFora(User $user): Recurrence
{
    return Recurrence::factory()->for($user)->create([
        'descricao' => 'Netflix', 'valor_cents' => 5590, 'dia' => 5,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-08-05',
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
    ]);
}

/** Enfileira a ocorrência da recorrência como confirmação pendente (o que o agendador faz). */
function pendenteDeRecorrencia(User $user, Recurrence $rec, string $dataCompra = '2026-07-05'): PendingConfirmation
{
    return (new EnfileirarConfirmacao)->enfileirar(
        new DadosGastoManual(
            userId: $user->id,
            descricao: (string) $rec->descricao,
            valorTotalCents: (int) $rec->valor_cents,
            dataCompra: CarbonImmutable::parse($dataCompra, 'America/Sao_Paulo'),
            paymentMethodId: (int) $rec->payment_method_id,
            parcelas: 1,
            categoriaId: $rec->categoria_id,
            origem: 'recorrencia',
            recurrenceId: $rec->id,
        ),
        PendingConfirmation::ORIGEM_RECORRENCIA,
        expiraEm: null,
    );
}

it('paga a recorrência pendente: cria o lançamento PAGO com o recurrence_id e resolve a confirmação', function () {
    $user = User::factory()->create();
    $rec = recorrenciaFora($user);
    $pendente = pendenteDeRecorrencia($user, $rec, '2026-07-05');

    $tx = (new PagarRecorrenciaPendente)->pagar($pendente->id, $user->id, agoraPagamentoRec());

    expect($tx)->toBeInstanceOf(Transaction::class)
        ->and($tx->recurrence_id)->toBe($rec->id)
        ->and($tx->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));

    $parcela = $tx->installments()->first();
    expect($parcela->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO))
        ->and($parcela->data_pagamento->toDateString())->toBe('2026-07-09');

    $pendente->refresh();
    expect($pendente->status)->toBe(PendingConfirmation::STATUS_CONFIRMADO)
        ->and($pendente->transaction_id)->toBe($tx->id);
});

it('mantém a recorrência ativa e não recua o ponteiro ao pagar', function () {
    $user = User::factory()->create();
    $rec = recorrenciaFora($user);
    $pendente = pendenteDeRecorrencia($user, $rec);

    (new PagarRecorrenciaPendente)->pagar($pendente->id, $user->id, agoraPagamentoRec());

    $rec->refresh();
    expect($rec->status)->toBe(Recurrence::STATUS_ATIVO)
        ->and($rec->proxima_em->toDateString())->toBe('2026-08-05');
});

it('é idempotente: pagar de novo não cria um segundo lançamento', function () {
    $user = User::factory()->create();
    $rec = recorrenciaFora($user);
    $pendente = pendenteDeRecorrencia($user, $rec);

    (new PagarRecorrenciaPendente)->pagar($pendente->id, $user->id, agoraPagamentoRec());
    $segunda = (new PagarRecorrenciaPendente)->pagar($pendente->id, $user->id, agoraPagamentoRec());

    expect($segunda)->toBeNull()
        ->and(Transaction::count())->toBe(1);
});

it('isola por usuário: pagar pendente alheio dá ModelNotFound', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $rec = recorrenciaFora($outro);
    $pendente = pendenteDeRecorrencia($outro, $rec);

    expect(fn () => (new PagarRecorrenciaPendente)->pagar($pendente->id, $user->id, agoraPagamentoRec()))
        ->toThrow(ModelNotFoundException::class);
});
