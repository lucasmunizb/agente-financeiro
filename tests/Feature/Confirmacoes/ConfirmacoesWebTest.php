<?php

declare(strict_types=1);

use App\Domain\Confirmacao\EnfileirarConfirmacao;
use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Shared\OpaqueId;
use App\Models\PaymentMethod;
use App\Models\PendingConfirmation;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Borda web da fila de confirmações pendentes (FE §7.9). POST server-rendered: delega ao
 * domínio já testado (Confirmar/Rejeitar Pendente) e volta à fila. {pendente} SEMPRE por token
 * opaco; escopo por usuário (404 para item alheio). Confirmar grava (RegistrarGastoManual);
 * rejeitar descarta sem gravar (regra 7).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function enfileirarPara(User $user, string $descricao = 'Netflix', int $cents = 5590): PendingConfirmation
{
    return (new EnfileirarConfirmacao)->enfileirar(new DadosGastoManual(
        userId: $user->id,
        descricao: $descricao,
        valorTotalCents: $cents,
        dataCompra: CarbonImmutable::parse('2026-07-05', 'America/Sao_Paulo'),
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
        parcelas: 1,
        origem: 'manual',
    ), PendingConfirmation::ORIGEM_RECORRENCIA);
}

it('a fila exige login', function () {
    $this->get(route('confirmacoes'))->assertRedirect(route('login'));
});

it('confirmar exige login', function () {
    $pendente = enfileirarPara(User::factory()->create());

    $this->post(route('confirmacoes.confirmar', OpaqueId::encode($pendente->id)))
        ->assertRedirect(route('login'));
});

it('confirma: grava o lançamento, resolve o pendente e volta à fila', function () {
    $user = User::factory()->create();
    $pendente = enfileirarPara($user);

    $this->actingAs($user)
        ->post(route('confirmacoes.confirmar', OpaqueId::encode($pendente->id)))
        ->assertRedirect(route('confirmacoes'))
        ->assertSessionHas('sucesso');

    expect(Transaction::where('user_id', $user->id)->count())->toBe(1)
        ->and($pendente->fresh()->status)->toBe(PendingConfirmation::STATUS_CONFIRMADO);
});

it('rejeita: descarta o pendente sem gravar e volta à fila', function () {
    $user = User::factory()->create();
    $pendente = enfileirarPara($user);

    $this->actingAs($user)
        ->post(route('confirmacoes.rejeitar', OpaqueId::encode($pendente->id)))
        ->assertRedirect(route('confirmacoes'))
        ->assertSessionHas('sucesso');

    expect(Transaction::where('user_id', $user->id)->count())->toBe(0)
        ->and($pendente->fresh()->status)->toBe(PendingConfirmation::STATUS_REJEITADO);
});

it('confirmar de novo é inócuo (idempotente) — não grava duas vezes', function () {
    $user = User::factory()->create();
    $pendente = enfileirarPara($user);

    $this->actingAs($user)->post(route('confirmacoes.confirmar', OpaqueId::encode($pendente->id)));
    $this->actingAs($user)->post(route('confirmacoes.confirmar', OpaqueId::encode($pendente->id)))
        ->assertRedirect(route('confirmacoes'));

    expect(Transaction::where('user_id', $user->id)->count())->toBe(1);
});

it('devolve 404 ao confirmar item de outro usuário', function () {
    $user = User::factory()->create();
    $alheio = enfileirarPara(User::factory()->create());

    $this->actingAs($user)
        ->post(route('confirmacoes.confirmar', OpaqueId::encode($alheio->id)))
        ->assertNotFound();

    expect($alheio->fresh()->status)->toBe(PendingConfirmation::STATUS_PENDENTE);
});

it('devolve 404 ao rejeitar item de outro usuário', function () {
    $user = User::factory()->create();
    $alheio = enfileirarPara(User::factory()->create());

    $this->actingAs($user)
        ->post(route('confirmacoes.rejeitar', OpaqueId::encode($alheio->id)))
        ->assertNotFound();

    expect($alheio->fresh()->status)->toBe(PendingConfirmation::STATUS_PENDENTE);
});

it('recusa (404) o id REAL no path — só token criptografado', function () {
    $user = User::factory()->create();
    $pendente = enfileirarPara($user);

    $this->actingAs($user)
        ->post("/confirmacoes/{$pendente->id}/confirmar")
        ->assertNotFound();

    expect($pendente->fresh()->status)->toBe(PendingConfirmation::STATUS_PENDENTE);
});
