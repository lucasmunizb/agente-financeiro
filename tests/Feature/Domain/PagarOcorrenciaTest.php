<?php

declare(strict_types=1);

use App\Domain\Gasto\PagamentoNaoPermitidoException;
use App\Domain\Recorrencia\PagarOcorrencia;
use App\Models\AuditLog;
use App\Models\Card;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * "Marcar como paga" uma ocorrência de recorrência (spec 12, R10–R12). Só FORA DE CARTÃO —
 * cartão liquida sozinho pela data de cobrança (D3). Idempotente, escopado por usuário
 * (404 para ocorrência alheia) e auditado. "Agora" injetado (regras 4 e 5).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function ocorrenciaDe(User $user, array $over = []): RecurrenceOccurrence
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
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
}

it('marca a ocorrência fora de cartão como paga, com data de pagamento = agora (R11)', function () {
    $user = User::factory()->create();
    $oc = ocorrenciaDe($user);
    $agora = CarbonImmutable::parse('2026-07-21 14:30', 'America/Sao_Paulo');

    $paga = (new PagarOcorrencia)->pagar($oc->id, $user->id, $agora);

    expect($paga)->not->toBeNull()
        ->and($paga->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO))
        ->and($paga->data_pagamento->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i'))->toBe('2026-07-21 14:30');

    expect(AuditLog::where('entidade', 'recurrence_occurrence')
        ->where('entidade_id', $oc->id)
        ->where('acao', AuditLog::ACAO_PAGAR)
        ->count())->toBe(1);
});

it('é idempotente: um segundo pagamento não muda nada (R11)', function () {
    $user = User::factory()->create();
    $oc = ocorrenciaDe($user);

    (new PagarOcorrencia)->pagar($oc->id, $user->id, CarbonImmutable::parse('2026-07-21 14:30', 'America/Sao_Paulo'));
    $primeira = $oc->fresh()->data_pagamento;

    $segunda = (new PagarOcorrencia)->pagar($oc->id, $user->id, CarbonImmutable::parse('2026-07-22 09:00', 'America/Sao_Paulo'));

    expect($segunda)->toBeNull()
        ->and($oc->fresh()->data_pagamento->equalTo($primeira))->toBeTrue();
});

it('recusa marcar como paga uma ocorrência de cartão (R10)', function () {
    $user = User::factory()->create();
    $card = Card::factory()->create(['user_id' => $user->id]);
    $oc = ocorrenciaDe($user, [
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::CREDITO),
        'card_id' => $card->id,
    ]);

    expect(fn () => (new PagarOcorrencia)->pagar($oc->id, $user->id, CarbonImmutable::parse('2026-07-21 14:30', 'America/Sao_Paulo')))
        ->toThrow(PagamentoNaoPermitidoException::class);

    expect($oc->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO));
});

it('não paga ocorrência de outro usuário — 404 e nada muda (R12)', function () {
    $dono = User::factory()->create();
    $intruso = User::factory()->create();
    $oc = ocorrenciaDe($dono);

    expect(fn () => (new PagarOcorrencia)->pagar($oc->id, $intruso->id, CarbonImmutable::parse('2026-07-21 14:30', 'America/Sao_Paulo')))
        ->toThrow(ModelNotFoundException::class);

    expect($oc->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO))
        ->and($oc->fresh()->data_pagamento)->toBeNull();
});
