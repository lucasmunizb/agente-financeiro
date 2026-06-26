<?php

use App\Domain\Orcamento\ConsumoDoMes;
use App\Domain\Orcamento\ConsumoMensal;
use App\Models\Category;
use App\Models\Installment;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * ConsumoDoMes — total gasto e consumo por categoria no mês (doc 08 §6).
 * Base: parcelas vencendo no mês (mesma base do "disponível", §4.5). Valor da
 * parcela é DERIVADO. Exclui pendente_revisao/cancelado/estornado. Escopo por usuário.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

/** Cria um gasto de parcela única, com vencimento e status dados. */
function gastoComParcela(User $user, int $valorCents, string $vencimento, ?int $categoriaId, string $statusCodigo = StatusPagamento::ABERTO): Transaction
{
    $transaction = Transaction::factory()->for($user)->create([
        'valor_total_cents' => $valorCents,
        'categoria_id' => $categoriaId,
    ]);

    Installment::factory()->for($transaction, 'transaction')->create([
        'numero' => 1,
        'total' => 1,
        'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor($statusCodigo),
    ]);

    return $transaction;
}

it('soma o total e quebra por categoria no mês', function () {
    $user = User::factory()->create();
    $alimentacao = Category::factory()->for($user)->create();
    $transporte = Category::factory()->for($user)->create();

    gastoComParcela($user, 30000, '2026-06-10', $alimentacao->id);
    gastoComParcela($user, 12000, '2026-06-20', $alimentacao->id);
    gastoComParcela($user, 8000, '2026-06-15', $transporte->id);

    $consumo = app(ConsumoDoMes::class)->para($user->id, '2026-06');

    expect($consumo)->toBeInstanceOf(ConsumoMensal::class)
        ->and($consumo->totalCents)->toBe(50000)
        ->and($consumo->porCategoria[$alimentacao->id])->toBe(42000)
        ->and($consumo->porCategoria[$transporte->id])->toBe(8000);
});

it('exclui status que não entram no cálculo', function () {
    $user = User::factory()->create();

    gastoComParcela($user, 10000, '2026-06-10', null, StatusPagamento::ABERTO);
    gastoComParcela($user, 99900, '2026-06-10', null, StatusPagamento::PENDENTE_REVISAO);
    gastoComParcela($user, 50000, '2026-06-10', null, StatusPagamento::CANCELADO);
    gastoComParcela($user, 70000, '2026-06-10', null, StatusPagamento::ESTORNADO);

    expect(app(ConsumoDoMes::class)->para($user->id, '2026-06')->totalCents)->toBe(10000);
});

it('considera apenas parcelas vencendo no mês', function () {
    $user = User::factory()->create();
    gastoComParcela($user, 10000, '2026-06-30', null);
    gastoComParcela($user, 88800, '2026-07-01', null);

    expect(app(ConsumoDoMes::class)->para($user->id, '2026-06')->totalCents)->toBe(10000);
});

it('ignora gastos de outro usuário', function () {
    $user = User::factory()->create();
    gastoComParcela(User::factory()->create(), 55500, '2026-06-10', null);

    expect(app(ConsumoDoMes::class)->para($user->id, '2026-06')->totalCents)->toBe(0);
});

it('agrupa gastos sem categoria sob a chave nula', function () {
    $user = User::factory()->create();
    gastoComParcela($user, 10000, '2026-06-10', null);

    $consumo = app(ConsumoDoMes::class)->para($user->id, '2026-06');

    expect($consumo->semCategoriaCents())->toBe(10000);
});
