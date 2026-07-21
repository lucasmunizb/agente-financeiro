<?php

use App\Domain\Gasto\CancelarGastoManual;
use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\EdicaoBloqueadaException;
use App\Domain\Gasto\EditarGastoManual;
use App\Domain\Gasto\PreviaGastoManual;
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
 * Edição de gasto manual (F2 — CRUD): regenera as parcelas de forma
 * determinística a partir dos novos dados, reavaliando status por data, e
 * registra a auditoria antes/depois. Bloqueia se houver parcela já paga.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function dadosEdit(User $user, array $over = []): DadosGastoManual
{
    return new DadosGastoManual(
        userId: $over['userId'] ?? $user->id,
        descricao: $over['descricao'] ?? 'Mercado',
        valorTotalCents: $over['valorTotalCents'] ?? 30000,
        dataCompra: $over['dataCompra'] ?? CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo'),
        paymentMethodId: $over['paymentMethodId'] ?? PaymentMethod::idFor(PaymentMethod::PIX),
        parcelas: $over['parcelas'] ?? 3,
    );
}

function gastoExistente(User $user, CarbonImmutable $hoje): Transaction
{
    return (new RegistrarGastoManual)->confirmar(dadosEdit($user), $hoje);
}

/* -------- preview: não persiste -------- */

it('preview da edição calcula as novas parcelas sem persistir', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoExistente($user, $hoje);

    $novos = dadosEdit($user, ['valorTotalCents' => 60000, 'parcelas' => 2]);
    $previa = (new EditarGastoManual)->preview($tx->id, $novos, $hoje);

    expect($previa)->toBeInstanceOf(PreviaGastoManual::class)
        ->and($previa->parcelas)->toHaveCount(2)
        ->and($previa->valorTotal->cents())->toBe(60000)
        // nada mudou no banco
        ->and($tx->fresh()->valor_total_cents)->toBe(30000)
        ->and(Installment::where('transaction_id', $tx->id)->count())->toBe(3);
});

/* -------- confirmar: regenera parcelas + atualiza transaction -------- */

it('confirmar atualiza os campos da transaction', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoExistente($user, $hoje);

    $novos = dadosEdit($user, ['descricao' => 'Mercado Atacadão', 'valorTotalCents' => 45000, 'parcelas' => 1]);
    (new EditarGastoManual)->confirmar($tx->id, $novos, $hoje);

    expect($tx->fresh()->descricao)->toBe('Mercado Atacadão')
        ->and($tx->fresh()->valor_total_cents)->toBe(45000);
});

it('confirmar regenera as parcelas (apaga as antigas e recria pelos novos dados)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoExistente($user, $hoje);
    $idsAntigos = Installment::where('transaction_id', $tx->id)->pluck('id')->all();

    $novos = dadosEdit($user, ['valorTotalCents' => 60000, 'parcelas' => 2]);
    (new EditarGastoManual)->confirmar($tx->id, $novos, $hoje);

    $parcelas = Installment::where('transaction_id', $tx->id)->orderBy('numero')->get();

    expect($parcelas)->toHaveCount(2)
        ->and($parcelas->pluck('id')->intersect($idsAntigos))->toBeEmpty()
        ->and($parcelas->pluck('total')->all())->toBe([2, 2])
        ->and($parcelas->first()->valor()->cents())->toBe(30000);
});

it('confirmar reavalia o status das parcelas pela data', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-10', 'America/Sao_Paulo');
    $tx = gastoExistente($user, CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo'));

    $novos = dadosEdit($user, ['parcelas' => 3]); // venc 06-10, 07-10, 08-10
    (new EditarGastoManual)->confirmar($tx->id, $novos, $hoje);

    $status = Installment::where('transaction_id', $tx->id)->orderBy('numero')
        ->pluck('status_id')->all();

    expect($status)->toBe([
        StatusPagamento::idFor(StatusPagamento::VENCIDO),
        StatusPagamento::idFor(StatusPagamento::ABERTO),
        StatusPagamento::idFor(StatusPagamento::AGENDADO),
    ]);
});

it('confirmar registra auditoria de edição com antes e depois', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoExistente($user, $hoje);

    $novos = dadosEdit($user, ['valorTotalCents' => 45000]);
    (new EditarGastoManual)->confirmar($tx->id, $novos, $hoje);

    $log = AuditLog::where('entidade', 'transaction')->where('entidade_id', $tx->id)
        ->where('acao', AuditLog::ACAO_EDITAR)->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($user->id)
        ->and($log->antes['valor_total_cents'])->toBe(30000)
        ->and($log->depois['valor_total_cents'])->toBe(45000);
});

/* -------- bloqueios -------- */

it('bloqueia edição quando há parcela já paga', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoExistente($user, $hoje);
    $tx->installments()->first()->update(['status_id' => StatusPagamento::idFor(StatusPagamento::PAGO)]);

    (new EditarGastoManual)->confirmar($tx->id, dadosEdit($user, ['valorTotalCents' => 45000]), $hoje);
})->throws(EdicaoBloqueadaException::class);

it('bloqueia edição de lançamento cancelado (parcelas-zumbi, auditoria P2-2)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoExistente($user, $hoje);
    (new CancelarGastoManual)->confirmar($tx->id, $user->id);

    // Regenerar as parcelas de um cancelado as recriaria "abertas" — o gasto
    // voltaria a contar no Disponível/Consumo com a transação cancelada.
    expect(fn () => (new EditarGastoManual)->confirmar($tx->id, dadosEdit($user), $hoje))
        ->toThrow(EdicaoBloqueadaException::class);

    expect(Installment::where('transaction_id', $tx->id)
        ->where('status_id', StatusPagamento::idFor(StatusPagamento::CANCELADO))
        ->count())->toBe(3); // parcelas seguem canceladas
});

it('não edita transaction de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoExistente($user, $hoje);

    (new EditarGastoManual)->confirmar($tx->id, dadosEdit($outro), $hoje);
})->throws(ModelNotFoundException::class);
