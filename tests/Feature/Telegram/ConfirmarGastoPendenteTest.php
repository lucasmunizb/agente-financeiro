<?php

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\Telegram\Confirmacao\ConfirmacoesPendentes;
use App\Domain\Telegram\Confirmacao\ConfirmarGastoPendente;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Spec 04b §3/§6 — o "sim" persiste o gasto. Recupera o pendente (escopo/TTL/uso único) e
 * REUSA `RegistrarGastoManual::confirmar()` (regra 4: não recalcula nada), descartando o
 * pendente na mesma operação (uso único). "agora" é injetado (determinismo). Nunca grava
 * duas vezes (C3) nem grava pendente expirado (C4) nem de outro usuário (C5).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    $this->agora = CarbonImmutable::parse('2026-06-26 12:00', 'America/Sao_Paulo');
});

function pendenteDe(User $user, array $over = []): ConfirmacaoDeGasto
{
    $dados = new DadosGastoManual(
        userId: $user->id,
        descricao: $over['descricao'] ?? 'Mercado',
        valorTotalCents: $over['valorTotalCents'] ?? 9000,
        dataCompra: $over['dataCompra'] ?? CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo'),
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
        parcelas: $over['parcelas'] ?? 1,
    );

    $previa = (new RegistrarGastoManual)->preview($dados, CarbonImmutable::parse('2026-06-26 12:00', 'America/Sao_Paulo'));

    return new ConfirmacaoDeGasto($previa, $dados, []);
}

it('C1 — "sim" grava via RegistrarGastoManual, gera parcelas, audita e descarta o pendente', function () {
    $user = User::factory()->create();
    $pendentes = app(ConfirmacoesPendentes::class);

    $pendentes->guardar($user->id, pendenteDe($user, ['parcelas' => 3]), $this->agora);

    $tx = app(ConfirmarGastoPendente::class)->confirmar($user->id, $this->agora);

    expect($tx)->toBeInstanceOf(Transaction::class)
        ->and(Transaction::where('user_id', $user->id)->count())->toBe(1)
        ->and($tx->installments)->toHaveCount(3)
        ->and($tx->origem)->toBe('manual')
        ->and($pendentes->recuperar($user->id, $this->agora))->toBeNull()
        ->and(AuditLog::where('entidade', 'transaction')->where('acao', AuditLog::ACAO_CRIAR)->count())->toBe(1);
});

it('C3 — uso único: um segundo "sim" não grava de novo', function () {
    $user = User::factory()->create();
    app(ConfirmacoesPendentes::class)->guardar($user->id, pendenteDe($user), $this->agora);
    $confirmar = app(ConfirmarGastoPendente::class);

    $confirmar->confirmar($user->id, $this->agora);
    $segundo = $confirmar->confirmar($user->id, $this->agora);

    expect($segundo)->toBeNull()
        ->and(Transaction::count())->toBe(1);
});

it('C4 — pendente expirado não grava', function () {
    $user = User::factory()->create();
    app(ConfirmacoesPendentes::class)->guardar($user->id, pendenteDe($user), $this->agora);

    $tx = app(ConfirmarGastoPendente::class)->confirmar($user->id, $this->agora->addMinutes(16));

    expect($tx)->toBeNull()
        ->and(Transaction::count())->toBe(0);
});

it('C5 — escopo por usuário: B não confirma o gasto de A', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $pendentes = app(ConfirmacoesPendentes::class);

    $pendentes->guardar($a->id, pendenteDe($a), $this->agora);

    $tx = app(ConfirmarGastoPendente::class)->confirmar($b->id, $this->agora);

    expect($tx)->toBeNull()
        ->and(Transaction::count())->toBe(0)
        ->and($pendentes->recuperar($a->id, $this->agora))->not->toBeNull();
});
