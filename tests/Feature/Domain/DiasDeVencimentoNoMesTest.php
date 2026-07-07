<?php

declare(strict_types=1);

use App\Domain\Dashboard\DiasDeVencimentoNoMes;
use App\Models\Installment;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Ticks da "régua do mês" (spec FE §4.5): os dias do mês com vencimento de parcela.
 * Read-only, determinístico, escopo por usuário; exclui status que não são conta viva.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([StatusPagamentoSeeder::class]);
});

function parcelaEm(User $user, string $vencimento, string $status = StatusPagamento::ABERTO): void
{
    $tx = Transaction::factory()->for($user)->create(['valor_total_cents' => 10000]);
    Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor($status),
    ]);
}

it('devolve os dias do mês com vencimento, únicos e ordenados', function () {
    $user = User::factory()->create();
    parcelaEm($user, '2026-06-20');
    parcelaEm($user, '2026-06-05');
    parcelaEm($user, '2026-06-20'); // mesmo dia → aparece uma vez só
    parcelaEm($user, '2026-07-01'); // fora do mês → ignorado

    expect(app(DiasDeVencimentoNoMes::class)->para($user->id, '2026-06'))
        ->toBe([5, 20]);
});

it('ignora status cancelado/estornado/pendente_revisao', function () {
    $user = User::factory()->create();
    parcelaEm($user, '2026-06-10', StatusPagamento::CANCELADO);
    parcelaEm($user, '2026-06-15', StatusPagamento::ESTORNADO);
    parcelaEm($user, '2026-06-25', StatusPagamento::ABERTO);

    expect(app(DiasDeVencimentoNoMes::class)->para($user->id, '2026-06'))
        ->toBe([25]);
});

it('é isolado por usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    parcelaEm($user, '2026-06-08');
    parcelaEm($outro, '2026-06-22');

    expect(app(DiasDeVencimentoNoMes::class)->para($user->id, '2026-06'))
        ->toBe([8]);
});
