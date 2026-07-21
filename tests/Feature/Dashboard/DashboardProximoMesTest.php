<?php

declare(strict_types=1);

use App\Models\Card;
use App\Models\Installment;
use App\Models\Recurrence;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Card "Previsto para <próximo mês>" do dashboard: o total do que JÁ está registrado para o
 * mês seguinte ao navegado — parcelas de cartão que vencem lá (fatura), lançamentos fora de
 * cartão com vencimento lá e as contas fixas (ocorrência real ou projeção do molde).
 *
 * É a MESMA consulta do card "Gastos do mês" ({@see App\Domain\Gastos\ConsultarGastos}),
 * aplicada à competência seguinte — nada é somado na borda nem na tela (regras 4/5).
 * "Hoje" congelado em 2026-06-15 → mês atual = junho, próximo = julho.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 09:00:00', 'America/Sao_Paulo'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function proximoMesGasto(User $user, int $cents, string $venc, ?Card $card = null): Transaction
{
    $tx = Transaction::factory()->for($user)->create([
        'valor_total_cents' => $cents,
        'card_id' => $card?->id,
    ]);
    Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $venc,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);

    return $tx;
}

it('soma cartão, lançamento fora de cartão e conta fixa do próximo mês', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['dia_fechamento' => 28, 'dia_vencimento' => 5]);

    proximoMesGasto($user, 120000, '2026-07-05', $card); // fatura vencendo em julho
    proximoMesGasto($user, 30000, '2026-07-10');         // fora de cartão, julho
    proximoMesGasto($user, 999900, '2026-06-20');        // junho: não entra no previsto
    Recurrence::factory()->for($user)->create([
        'descricao' => 'Netflix', 'valor_cents' => 5590, 'dia' => 5,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-07-05',
    ]);

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('Previsto para julho')
        ->assertSee('R$ 1.555,90'); // 1.200 + 300 + 55,90
});

it('acompanha a navegação por competência (mês seguinte ao navegado)', function () {
    $user = User::factory()->create();
    proximoMesGasto($user, 50000, '2026-07-10');  // dá estado "pronto"
    proximoMesGasto($user, 20000, '2026-08-10');  // previsto da visão de julho

    $this->actingAs($user)->get('/?mes=2026-07')
        ->assertOk()
        ->assertSee('Previsto para agosto')
        ->assertSee('R$ 200,00');
});

it('mostra zero quando nada está registrado para o próximo mês', function () {
    $user = User::factory()->create();
    proximoMesGasto($user, 50000, '2026-06-10');

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('Previsto para julho')
        ->assertSee('R$ 0,00');
});

it('é isolado por usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    proximoMesGasto($user, 5000, '2026-07-10');
    proximoMesGasto($outro, 999900, '2026-07-10');

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('R$ 50,00')
        ->assertDontSee('R$ 9.999,00');
});
