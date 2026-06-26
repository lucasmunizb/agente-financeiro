<?php

use App\Domain\Gasto\CancelarGastoManual;
use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Models\AuditLog;
use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Cancelamento de gasto manual (F2 — CRUD): marca a transaction e as parcelas
 * ainda não finalizadas como 'cancelado' (mantém o histórico, sem apagar a
 * linha), preservando parcelas já pagas/estornadas. Registra auditoria.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function gastoParaCancelar(User $user, CarbonImmutable $hoje): Transaction
{
    return (new RegistrarGastoManual)->confirmar(new DadosGastoManual(
        userId: $user->id,
        descricao: 'Mercado',
        valorTotalCents: 30000,
        dataCompra: CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo'),
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
        parcelas: 3,
    ), $hoje);
}

it('marca a transaction como cancelada', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoParaCancelar($user, $hoje);

    (new CancelarGastoManual)->confirmar($tx->id, $user->id);

    expect($tx->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::CANCELADO));
});

it('cancela as parcelas não finalizadas e mantém a linha (não apaga)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoParaCancelar($user, $hoje);

    (new CancelarGastoManual)->confirmar($tx->id, $user->id);

    $status = Installment::where('transaction_id', $tx->id)->pluck('status_id')->all();

    expect(Installment::where('transaction_id', $tx->id)->count())->toBe(3)
        ->and($status)->each->toBe(StatusPagamento::idFor(StatusPagamento::CANCELADO));
});

it('preserva parcela já paga ao cancelar', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoParaCancelar($user, $hoje);
    $paga = $tx->installments()->first();
    $paga->update(['status_id' => StatusPagamento::idFor(StatusPagamento::PAGO)]);

    (new CancelarGastoManual)->confirmar($tx->id, $user->id);

    expect($paga->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));
});

it('registra auditoria de cancelamento', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoParaCancelar($user, $hoje);

    (new CancelarGastoManual)->confirmar($tx->id, $user->id);

    $log = AuditLog::where('entidade', 'transaction')->where('entidade_id', $tx->id)
        ->where('acao', AuditLog::ACAO_CANCELAR)->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($user->id);
});

it('não cancela transaction de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoParaCancelar($user, $hoje);

    (new CancelarGastoManual)->confirmar($tx->id, $outro->id);
})->throws(ModelNotFoundException::class);
