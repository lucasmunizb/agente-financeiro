<?php

declare(strict_types=1);

use App\Domain\Recorrencia\DadosRecorrencia;
use App\Domain\Recorrencia\RegistrarRecorrencia;
use App\Models\PaymentMethod;
use App\Models\PendingConfirmation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Borda agendada da recorrência (spec 10): o comando resolve "hoje" e delega ao domínio.
 * Saída só com contagem (sem dado sensível), como o expurgo de conversas.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

it('materializa as recorrências vencíveis e reporta só a contagem', function () {
    $user = User::factory()->create();
    // Fixa "hoje" para o comando via Carbon::setTestNow (o comando lê now() em SP).
    $hoje = CarbonImmutable::parse('2026-07-09 06:00', 'America/Sao_Paulo');
    CarbonImmutable::setTestNow($hoje);

    (new RegistrarRecorrencia)->registrar(new DadosRecorrencia(
        userId: $user->id,
        descricao: 'Spotify',
        valorCents: 2190,
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
        dia: 9,
    ), $hoje);

    $this->artisan('recorrencia:materializar')
        ->expectsOutputToContain('1')
        ->assertSuccessful();

    expect(PendingConfirmation::where('user_id', $user->id)->count())->toBe(1);

    CarbonImmutable::setTestNow();
});
