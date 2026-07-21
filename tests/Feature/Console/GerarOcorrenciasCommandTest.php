<?php

declare(strict_types=1);

use App\Models\Card;
use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Borda agendada da recorrência (spec 12): o comando resolve "hoje" e delega ao domínio —
 * gera as competências faltantes e liquida as cobranças de cartão já debitadas. Saída só com
 * contagens (sem dado sensível), como o expurgo de conversas.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('gera as ocorrências do mês e reporta só as contagens', function () {
    $user = User::factory()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-21 06:00', 'America/Sao_Paulo'));

    Recurrence::factory()->create([
        'user_id' => $user->id,
        'descricao' => 'Spotify',
        'valor_cents' => 2190,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'dia' => 9,
        'status' => Recurrence::STATUS_ATIVO,
        'proxima_em' => '2026-07-01',
    ]);

    $this->artisan('recorrencia:gerar')
        ->expectsOutputToContain('geradas: 1')
        ->expectsOutputToContain('liquidadas: 0')
        ->assertSuccessful();

    // Recorrência nunca vira lançamento (spec 12, invariante central).
    expect(RecurrenceOccurrence::where('user_id', $user->id)->count())->toBe(1)
        ->and(Transaction::count())->toBe(0)
        ->and(Installment::count())->toBe(0);
});

it('liquida a cobrança de cartão cuja data já passou', function () {
    $user = User::factory()->create();
    $card = Card::factory()->create(['user_id' => $user->id, 'dia_fechamento' => 20, 'dia_vencimento' => 28]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-21 06:00', 'America/Sao_Paulo'));

    Recurrence::factory()->create([
        'user_id' => $user->id,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::CREDITO),
        'card_id' => $card->id,
        'dia' => 5,
        'status' => Recurrence::STATUS_ATIVO,
        'proxima_em' => '2026-07-01',
    ]);

    // Nasce já paga na geração (D3), então a varredura de liquidação não acha nada sobrando.
    $this->artisan('recorrencia:gerar')
        ->expectsOutputToContain('geradas: 1')
        ->assertSuccessful();

    expect(RecurrenceOccurrence::sole()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));
});
