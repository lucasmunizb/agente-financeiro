<?php

use App\Domain\Orcamento\OrcamentoMensal;
use App\Models\Budget;
use App\Models\Installment;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * OrcamentoMensal — junta o limite geral do mês (budgets) com o consumo do mês
 * (ConsumoDoMes) e avalia via Orcamento (doc 08 §6). Determinístico, sem alerta.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function gasto(User $user, int $valorCents, string $vencimento): void
{
    $transaction = Transaction::factory()->for($user)->create(['valor_total_cents' => $valorCents]);
    Installment::factory()->for($transaction, 'transaction')->create([
        'numero' => 1,
        'total' => 1,
        'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
}

it('avalia o orçamento geral contra o consumo do mês', function () {
    $user = User::factory()->create();
    Budget::factory()->for($user)->create(['mes' => '2026-06', 'categoria_id' => null, 'limite_cents' => 300000]);

    gasto($user, 120000, '2026-06-10');
    gasto($user, 30000, '2026-06-20');

    $resultado = app(OrcamentoMensal::class)->para($user->id, '2026-06');

    expect($resultado->limite->cents())->toBe(300000)
        ->and($resultado->consumido->cents())->toBe(150000)
        ->and($resultado->restante->cents())->toBe(150000)
        ->and($resultado->estourou)->toBeFalse();
});

it('quando não há orçamento definido, limite é zero e qualquer gasto estoura', function () {
    $user = User::factory()->create();
    gasto($user, 5000, '2026-06-10');

    $resultado = app(OrcamentoMensal::class)->para($user->id, '2026-06');

    expect($resultado->limite->cents())->toBe(0)
        ->and($resultado->consumido->cents())->toBe(5000)
        ->and($resultado->estourou)->toBeTrue();
});
