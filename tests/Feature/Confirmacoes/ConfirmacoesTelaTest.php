<?php

declare(strict_types=1);

use App\Domain\Confirmacao\EnfileirarConfirmacao;
use App\Domain\Gasto\DadosGastoManual;
use App\Models\PaymentMethod;
use App\Models\PendingConfirmation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Tela da fila de confirmações pendentes (FE §7.9). Renderiza os pendentes JÁ formatados pela
 * borda (regra 5) — a UI nunca calcula (regra 4) —, o estado vazio e a garantia "nada é gravado
 * até confirmar". Escopo estrito por usuário. (As AÇÕES confirmar/rejeitar são cobertas em
 * ConfirmacoesWebTest.)
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function enfileirarTela(User $user, string $descricao, int $cents, int $parcelas = 1): PendingConfirmation
{
    return (new EnfileirarConfirmacao)->enfileirar(new DadosGastoManual(
        userId: $user->id,
        descricao: $descricao,
        valorTotalCents: $cents,
        dataCompra: CarbonImmutable::parse('2026-07-05', 'America/Sao_Paulo'),
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
        parcelas: $parcelas,
        origem: 'manual',
    ), PendingConfirmation::ORIGEM_RECORRENCIA);
}

it('lista os pendentes do usuário, já formatados', function () {
    $user = User::factory()->create();
    enfileirarTela($user, 'Netflix', 5590);

    $this->actingAs($user)->get(route('confirmacoes'))
        ->assertOk()
        ->assertSee('Confirmações pendentes')
        ->assertSee('Netflix')
        ->assertSee('R$ 55,90')
        ->assertSee('Recorrência')          // rótulo da origem
        ->assertSee('Confirmar')
        ->assertSee('Descartar')
        ->assertSee('Nada é gravado até você confirmar.');
});

it('mostra o estado vazio quando não há pendentes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('confirmacoes'))
        ->assertOk()
        ->assertSee('Nada para confirmar agora.')
        ->assertDontSee('Nada é gravado até você confirmar.');
});

it('não lista itens já resolvidos', function () {
    $user = User::factory()->create();
    $pendente = enfileirarTela($user, 'Vivo', 8000);
    PendingConfirmation::factory()->for($user)->confirmado()->create(['payload' => $pendente->payload]);

    $this->actingAs($user)->get(route('confirmacoes'))
        ->assertOk()
        ->assertSee('Vivo')
        ->assertSee('R$ 80,00');
});

it('é isolado por usuário (não vaza fila de terceiros)', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    enfileirarTela($user, 'MeuGasto', 1000);
    enfileirarTela($outro, 'GastoAlheio', 999900);

    $this->actingAs($user)->get(route('confirmacoes'))
        ->assertOk()
        ->assertSee('MeuGasto')
        ->assertDontSee('GastoAlheio')
        ->assertDontSee('R$ 9.999,00');
});
