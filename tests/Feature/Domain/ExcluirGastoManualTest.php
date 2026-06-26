<?php

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\ExcluirGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Exclusão de gasto manual (F2 — CRUD): exclusão LÓGICA (soft delete, LGPD),
 * preservando a trilha de auditoria. A linha some das consultas normais mas é
 * mantida no banco; registra auditoria de exclusão.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function gastoParaExcluir(User $user, CarbonImmutable $hoje): Transaction
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

it('exclui logicamente a transaction (soft delete)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoParaExcluir($user, $hoje);

    (new ExcluirGastoManual)->confirmar($tx->id, $user->id);

    expect(Transaction::find($tx->id))->toBeNull()
        ->and(Transaction::withTrashed()->find($tx->id))->not->toBeNull()
        ->and(Transaction::withTrashed()->find($tx->id)->trashed())->toBeTrue();
});

it('registra auditoria de exclusão', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoParaExcluir($user, $hoje);

    (new ExcluirGastoManual)->confirmar($tx->id, $user->id);

    $log = AuditLog::where('entidade', 'transaction')->where('entidade_id', $tx->id)
        ->where('acao', AuditLog::ACAO_EXCLUIR)->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($user->id);
});

it('não exclui transaction de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoParaExcluir($user, $hoje);

    (new ExcluirGastoManual)->confirmar($tx->id, $outro->id);
})->throws(ModelNotFoundException::class);
