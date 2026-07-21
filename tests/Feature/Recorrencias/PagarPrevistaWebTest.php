<?php

declare(strict_types=1);

use App\Domain\Shared\OpaqueId;
use App\Models\Card;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Borda de "marcar como paga" na conta fixa ainda PREVISTA (spec 13, decisão 2026-07-21).
 *
 * A linha prevista não tem ocorrência no banco: a rota materializa a competência
 * ({@see MaterializarOcorrencia}) e paga pelo caminho já testado ({@see PagarOcorrencia}).
 * Dois serviços de domínio, nenhuma regra nova aqui — a borda só valida e delega.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 09:00:00', 'America/Sao_Paulo'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function moldePrevisto(User $user, array $attrs = []): Recurrence
{
    return Recurrence::factory()->for($user)->create([
        'descricao' => 'Academia',
        'valor_cents' => 12000,
        'dia' => 20,
        'status' => Recurrence::STATUS_ATIVO,
        'proxima_em' => '2026-06-01',
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        ...$attrs,
    ]);
}

it('materializa e marca como paga a conta fixa prevista', function () {
    $user = User::factory()->create();
    $molde = moldePrevisto($user);

    $this->actingAs($user)
        ->post(route('lancamentos.recorrencia-prevista.pagar', $molde->getRouteKey()), ['competencia' => '2026-06'])
        ->assertRedirect()
        ->assertSessionHas('sucesso');

    $ocorrencia = RecurrenceOccurrence::query()->sole();

    expect($ocorrencia->competencia)->toBe('2026-06')
        ->and($ocorrencia->valor_cents)->toBe(12000)
        ->and($ocorrencia->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO))
        ->and($ocorrencia->data_pagamento)->not->toBeNull();
});

it('é idempotente na borda: o segundo clique não duplica nem repaga', function () {
    $user = User::factory()->create();
    $molde = moldePrevisto($user);
    $url = route('lancamentos.recorrencia-prevista.pagar', $molde->getRouteKey());

    $this->actingAs($user)->post($url, ['competencia' => '2026-06'])->assertRedirect();
    $primeiraData = RecurrenceOccurrence::query()->sole()->data_pagamento;

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-16 09:00:00', 'America/Sao_Paulo'));
    $this->actingAs($user)->post($url, ['competencia' => '2026-06'])->assertRedirect();

    expect(RecurrenceOccurrence::query()->count())->toBe(1)
        ->and(RecurrenceOccurrence::query()->sole()->data_pagamento->toIso8601String())
        ->toBe($primeiraData->toIso8601String());
});

it('recusa competência ausente ou mal formada (422 na validação)', function () {
    $user = User::factory()->create();
    $molde = moldePrevisto($user);
    $url = route('lancamentos.recorrencia-prevista.pagar', $molde->getRouteKey());

    $this->actingAs($user)->post($url, [])->assertSessionHasErrors('competencia');
    $this->actingAs($user)->post($url, ['competencia' => 'junho'])->assertSessionHasErrors('competencia');

    expect(RecurrenceOccurrence::query()->count())->toBe(0);
});

it('recusa molde de OUTRO usuário (404) e nada é criado', function () {
    $dono = User::factory()->create();
    $intruso = User::factory()->create();
    $molde = moldePrevisto($dono);

    $this->actingAs($intruso)
        ->post(route('lancamentos.recorrencia-prevista.pagar', $molde->getRouteKey()), ['competencia' => '2026-06'])
        ->assertNotFound();

    expect(RecurrenceOccurrence::query()->count())->toBe(0);
});

it('recusa id em claro na URL (nenhum id real em rota)', function () {
    $user = User::factory()->create();
    $molde = moldePrevisto($user);

    $this->actingAs($user)
        ->post('/lancamentos/recorrencia-prevista/'.$molde->id.'/pagar', ['competencia' => '2026-06'])
        ->assertNotFound();

    expect(OpaqueId::decode((string) $molde->id))->toBeNull();
});

it('recusa conta fixa em cartão com mensagem, sem 500 e sem criar ocorrência', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['dia_fechamento' => 28, 'dia_vencimento' => 5]);
    $molde = moldePrevisto($user, ['card_id' => $card->id]);

    $this->actingAs($user)
        ->post(route('lancamentos.recorrencia-prevista.pagar', $molde->getRouteKey()), ['competencia' => '2026-06'])
        ->assertRedirect()
        ->assertSessionHasErrors('geral');

    expect(RecurrenceOccurrence::query()->count())->toBe(0);
});

it('recusa competência de mês já encerrado', function () {
    $user = User::factory()->create();
    $molde = moldePrevisto($user, ['proxima_em' => '2026-05-01']);

    $this->actingAs($user)
        ->post(route('lancamentos.recorrencia-prevista.pagar', $molde->getRouteKey()), ['competencia' => '2026-05'])
        ->assertRedirect()
        ->assertSessionHasErrors('geral');

    expect(RecurrenceOccurrence::query()->count())->toBe(0);
});
