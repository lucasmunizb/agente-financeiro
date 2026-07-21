<?php

declare(strict_types=1);

use App\Domain\Gasto\PagamentoNaoPermitidoException;
use App\Domain\Recorrencia\PagarOcorrencia;
use App\Domain\Recorrencia\ReverterPagamentoOcorrencia;
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
 * DESMARCAR o pagamento de uma ocorrência de recorrência (decisão do usuário 2026-07-21).
 * Inverso de {@see PagarOcorrencia}: devolve a ocorrência para 'aberto' e apaga a
 * `data_pagamento`. Mesmas barreiras — só FORA DE CARTÃO (cartão liquida sozinho pela data
 * de cobrança, D3, e o agendador voltaria a liquidá-la), cancelada não reabre, escopo
 * estrito por usuário e idempotência.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function ocorrenciaParaReverter(User $user, array $over = []): RecurrenceOccurrence
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
        'status_id' => $over['status_id'] ?? StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
}

it('devolve a ocorrência paga para aberto e apaga a data de pagamento', function () {
    $user = User::factory()->create();
    $oc = ocorrenciaParaReverter($user);
    (new PagarOcorrencia)->pagar($oc->id, $user->id, CarbonImmutable::parse('2026-07-21 14:30', 'America/Sao_Paulo'));

    $revertida = (new ReverterPagamentoOcorrencia)->reverter($oc->id, $user->id);

    expect($revertida)->not->toBeNull()
        ->and($revertida->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO))
        ->and($revertida->data_pagamento)->toBeNull();
});

it('é idempotente: desmarcar uma ocorrência já aberta devolve null e não audita', function () {
    $user = User::factory()->create();
    $oc = ocorrenciaParaReverter($user);

    expect((new ReverterPagamentoOcorrencia)->reverter($oc->id, $user->id))->toBeNull()
        ->and(AuditLog::where('entidade', 'recurrence_occurrence')
            ->where('acao', AuditLog::ACAO_DESMARCAR_PAGAMENTO)->count())->toBe(0);
});

it('recusa desmarcar ocorrência de cartão (D3)', function () {
    // O agendador (LiquidarOcorrenciasDeCartao) voltaria a marcá-la paga: reabrir aqui
    // só produziria um vaivém que mente sobre a fatura.
    $user = User::factory()->create();
    $card = Card::factory()->create(['user_id' => $user->id]);
    $oc = ocorrenciaParaReverter($user, [
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::CREDITO),
        'card_id' => $card->id,
        'status_id' => StatusPagamento::idFor(StatusPagamento::PAGO),
    ]);

    expect(fn () => (new ReverterPagamentoOcorrencia)->reverter($oc->id, $user->id))
        ->toThrow(PagamentoNaoPermitidoException::class);

    expect($oc->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));
});

it('não reabre uma ocorrência cancelada', function () {
    $user = User::factory()->create();
    $oc = ocorrenciaParaReverter($user, ['status_id' => StatusPagamento::idFor(StatusPagamento::CANCELADO)]);

    expect((new ReverterPagamentoOcorrencia)->reverter($oc->id, $user->id))->toBeNull()
        ->and($oc->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::CANCELADO));
});

it('não desmarca ocorrência de outro usuário — 404 e nada muda', function () {
    $dono = User::factory()->create();
    $intruso = User::factory()->create();
    $oc = ocorrenciaParaReverter($dono);
    (new PagarOcorrencia)->pagar($oc->id, $dono->id, CarbonImmutable::parse('2026-07-21 14:30', 'America/Sao_Paulo'));

    expect(fn () => (new ReverterPagamentoOcorrencia)->reverter($oc->id, $intruso->id))
        ->toThrow(ModelNotFoundException::class);

    expect($oc->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));
});

it('registra auditoria do estorno da marcação', function () {
    $user = User::factory()->create();
    $oc = ocorrenciaParaReverter($user);
    (new PagarOcorrencia)->pagar($oc->id, $user->id, CarbonImmutable::parse('2026-07-21 14:30', 'America/Sao_Paulo'));

    (new ReverterPagamentoOcorrencia)->reverter($oc->id, $user->id);

    $log = AuditLog::where('entidade', 'recurrence_occurrence')->where('entidade_id', $oc->id)
        ->where('acao', AuditLog::ACAO_DESMARCAR_PAGAMENTO)->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($user->id)
        ->and($log->depois['status_id'])->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO));
});
