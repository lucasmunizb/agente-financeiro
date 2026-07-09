<?php

declare(strict_types=1);

use App\Domain\Recorrencia\CancelarRecorrencia;
use App\Domain\Recorrencia\DadosRecorrencia;
use App\Domain\Recorrencia\RegistrarRecorrencia;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Tela de gerenciar recorrências (spec 10). Borda fina: lista as recorrências ATIVAS já
 * formatadas (regra 5; a UI nunca calcula, regra 4) e cancela via CancelarRecorrencia
 * (idempotente, escopo por usuário). Ids sempre por token opaco. Cancelar é destrutivo →
 * confirmado na tela por <details> (sem JS).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-09 06:00', 'America/Sao_Paulo'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function novaRecorrencia(User $user, array $over = []): Recurrence
{
    return (new RegistrarRecorrencia)->registrar(new DadosRecorrencia(
        userId: $user->id,
        descricao: $over['descricao'] ?? 'Netflix',
        valorCents: $over['valorCents'] ?? 5590,
        paymentMethodId: PaymentMethod::idFor($over['forma'] ?? PaymentMethod::PIX),
        dia: $over['dia'] ?? 5,
    ), CarbonImmutable::now('America/Sao_Paulo'));
}

it('a tela exige login', function () {
    $this->get(route('recorrencias'))->assertRedirect(route('login'));
});

it('lista as recorrências ativas do usuário, já formatadas', function () {
    $user = User::factory()->create();
    novaRecorrencia($user, ['descricao' => 'Netflix', 'valorCents' => 5590, 'dia' => 5]);

    $this->actingAs($user)->get(route('recorrencias'))
        ->assertOk()
        ->assertSee('Recorrências')
        ->assertSee('Netflix')
        ->assertSee('R$ 55,90')
        ->assertSee('todo dia 5')
        ->assertSee('Pix');
});

it('não lista recorrência cancelada nem de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $cancelada = novaRecorrencia($user, ['descricao' => 'Antiga']);
    (new CancelarRecorrencia)->cancelar($cancelada->id, $user->id, CarbonImmutable::now('America/Sao_Paulo'));
    novaRecorrencia($user, ['descricao' => 'MinhaAtiva']);
    novaRecorrencia($outro, ['descricao' => 'GastoAlheio']);

    $this->actingAs($user)->get(route('recorrencias'))
        ->assertOk()
        ->assertSee('MinhaAtiva')
        ->assertDontSee('Antiga')
        ->assertDontSee('GastoAlheio');
});

it('mostra o estado vazio quando não há recorrências', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('recorrencias'))
        ->assertOk()
        ->assertSee('Nenhuma recorrência ativa');
});

it('cancelar encerra a recorrência e volta à tela com aviso', function () {
    $user = User::factory()->create();
    $rec = novaRecorrencia($user);

    $this->actingAs($user)
        ->post(route('recorrencias.cancelar', $rec->getRouteKey()))
        ->assertRedirect(route('recorrencias'))
        ->assertSessionHas('sucesso');

    expect($rec->fresh()->status)->toBe(Recurrence::STATUS_CANCELADO)
        ->and($rec->fresh()->proxima_em)->toBeNull();
});

it('cancelar exige login', function () {
    $user = User::factory()->create();
    $rec = novaRecorrencia($user);

    $this->post(route('recorrencias.cancelar', $rec->getRouteKey()))->assertRedirect(route('login'));

    expect($rec->fresh()->status)->toBe(Recurrence::STATUS_ATIVO);
});

it('devolve 404 ao cancelar recorrência de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $rec = novaRecorrencia($outro);

    $this->actingAs($user)
        ->post(route('recorrencias.cancelar', $rec->getRouteKey()))
        ->assertNotFound();

    expect($rec->fresh()->status)->toBe(Recurrence::STATUS_ATIVO);
});

it('recusa o id REAL no path — só token opaco', function () {
    $user = User::factory()->create();
    $rec = novaRecorrencia($user);

    $this->actingAs($user)
        ->post("/recorrencias/{$rec->id}/cancelar")
        ->assertNotFound();

    expect($rec->fresh()->status)->toBe(Recurrence::STATUS_ATIVO);
});
