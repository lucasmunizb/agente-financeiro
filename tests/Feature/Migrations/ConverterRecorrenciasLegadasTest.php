<?php

declare(strict_types=1);

use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Conversão do histórico legado (spec 12, D4 / R14). O banco antigo tinha um LANÇAMENTO por mês
 * de recorrência (`transactions.recurrence_id`); a migration transforma cada um na ocorrência
 * equivalente e apaga a transaction + parcelas, preservando status, data de pagamento e
 * competência — os totais do mês têm de ficar INALTERADOS.
 *
 * A coluna já foi dropada pela migration seguinte, então o teste a recria para montar o estado
 * legado e roda o `up()` da própria migration (nada de reimplementar a regra aqui).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);

    // Recria a coluna legada só para montar o cenário.
    Schema::table('transactions', function (Blueprint $table) {
        $table->foreignId('recurrence_id')->nullable()->constrained('recurrences')->nullOnDelete();
    });
});

afterEach(function () {
    if (Schema::hasColumn('transactions', 'recurrence_id')) {
        Schema::table('transactions', fn (Blueprint $t) => $t->dropConstrainedForeignId('recurrence_id'));
    }
});

/** Roda o `up()` da migration de dados (a real, não uma cópia). */
function converterLegado(): void
{
    (require base_path('database/migrations/2026_07_21_000003_converter_recorrencias_legadas.php'))->up();
}

/** Um lançamento legado vinculado a uma recorrência, com a sua única parcela. */
function lancamentoLegado(User $user, Recurrence $rec, int $cents, string $vencimento, string $status, ?string $dataPagamento = null): Transaction
{
    $tx = Transaction::factory()->for($user)->create([
        'descricao' => 'Netflix',
        'valor_total_cents' => $cents,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'data_compra' => $vencimento,
        'origem' => 'recorrencia',
        'status_id' => StatusPagamento::idFor($status),
    ]);
    // `recurrence_id` não está no fillable (a coluna não existe mais no schema oficial).
    DB::table('transactions')->where('id', $tx->id)->update(['recurrence_id' => $rec->id]);

    Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor($status),
        'data_pagamento' => $dataPagamento,
    ]);

    return $tx;
}

it('converte o lançamento recorrente pago na ocorrência equivalente e o remove (R14)', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create(['dia' => 5, 'proxima_em' => '2026-08-01']);
    $tx = lancamentoLegado($user, $rec, 5590, '2026-07-05', StatusPagamento::PAGO, '2026-07-06');

    converterLegado();

    $oc = RecurrenceOccurrence::sole();
    expect($oc->user_id)->toBe($user->id)
        ->and($oc->recurrence_id)->toBe($rec->id)
        ->and($oc->competencia)->toBe('2026-07')
        ->and($oc->descricao)->toBe('Netflix')
        ->and($oc->valor_cents)->toBe(5590)
        ->and($oc->vencimento->toDateString())->toBe('2026-07-05')
        ->and($oc->data_cobranca->toDateString())->toBe('2026-07-05')
        ->and($oc->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO))
        ->and($oc->data_pagamento->setTimezone('America/Sao_Paulo')->toDateString())->toBe('2026-07-06');

    // A transaction e as parcelas deixam de existir (remoção física, D4).
    expect(Transaction::withTrashed()->whereKey($tx->id)->exists())->toBeFalse()
        ->and(Installment::where('transaction_id', $tx->id)->exists())->toBeFalse();
});

it('preserva os totais do mês: o que era parcela vira ocorrência de mesmo valor', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create(['dia' => 5, 'proxima_em' => '2026-08-01']);
    lancamentoLegado($user, $rec, 5590, '2026-06-05', StatusPagamento::ABERTO);
    lancamentoLegado($user, $rec, 5590, '2026-07-05', StatusPagamento::ABERTO);

    converterLegado();

    expect(RecurrenceOccurrence::pluck('competencia')->sort()->values()->all())->toBe(['2026-06', '2026-07'])
        ->and((int) RecurrenceOccurrence::sum('valor_cents'))->toBe(11180)
        ->and(Transaction::count())->toBe(0);
});

it('é idempotente: rodar de novo não duplica nem apaga o que já converteu', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create(['dia' => 5, 'proxima_em' => '2026-08-01']);
    lancamentoLegado($user, $rec, 5590, '2026-07-05', StatusPagamento::ABERTO);

    converterLegado();
    converterLegado();

    expect(RecurrenceOccurrence::count())->toBe(1);
});

it('não converte o que já tem ocorrência na competência (conflito ⇒ ignora)', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create(['dia' => 5, 'proxima_em' => '2026-08-01']);
    RecurrenceOccurrence::factory()->create([
        'user_id' => $user->id, 'recurrence_id' => $rec->id, 'competencia' => '2026-07',
        'descricao' => 'Já existia', 'valor_cents' => 1000,
        'data_cobranca' => '2026-07-05', 'vencimento' => '2026-07-05',
    ]);
    lancamentoLegado($user, $rec, 5590, '2026-07-05', StatusPagamento::ABERTO);

    converterLegado();

    // A ocorrência preexistente vence; a transaction some do mesmo jeito (não duplica a conta).
    expect(RecurrenceOccurrence::sole()->descricao)->toBe('Já existia')
        ->and(Transaction::count())->toBe(0);
});

it('não toca em lançamento comum (sem recurrence_id)', function () {
    $user = User::factory()->create();
    $tx = Transaction::factory()->for($user)->create([
        'descricao' => 'Padaria',
        'valor_total_cents' => 3000,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
    Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => '2026-07-05',
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);

    converterLegado();

    expect(Transaction::whereKey($tx->id)->exists())->toBeTrue()
        ->and(RecurrenceOccurrence::count())->toBe(0);
});
