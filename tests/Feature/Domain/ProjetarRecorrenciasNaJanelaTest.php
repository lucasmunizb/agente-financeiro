<?php

declare(strict_types=1);

use App\Domain\Recorrencia\ProjetarRecorrenciasPendentes;
use App\Models\PaymentMethod;
use App\Models\PendingConfirmation;
use App\Models\Recurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Ponte "fila ↔ tela" por JANELA DE DATAS. A consulta mensal ({@see ProjetarRecorrenciasPendentes::para})
 * serve o extrato/donut, que são mensais; os quadros do dashboard não são — "a vencer" é
 * [hoje, hoje+N] (cruza a fronteira do mês) e "em atraso" é (.., ontem] sem limite inferior.
 * Daí a janela: mesma leitura da fila, recorte por data.
 *
 * Read-only (regra 7); "agora" injetado (regra 4/5); escopo estrito por usuário.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

/** "Agora" fixo: 16/07/2026 (mês corrente = 2026-07). */
function agoraJanela(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-07-16 09:00', 'America/Sao_Paulo');
}

/** Enfileira uma ocorrência de recorrência na fila, vencendo na data dada. */
function ocorrenciaNaFila(
    User $user,
    Recurrence $recorrencia,
    string $vencimento,
    int $cents = 5590,
    string $descricao = 'Netflix',
): PendingConfirmation {
    return PendingConfirmation::factory()->for($user)->create([
        'origem' => PendingConfirmation::ORIGEM_RECORRENCIA,
        'status' => PendingConfirmation::STATUS_PENDENTE,
        'recurrence_id' => $recorrencia->id,
        'expira_em' => null,
        'payload' => [
            'descricao' => $descricao,
            'valorTotalCents' => $cents,
            'dataCompra' => $vencimento,
            'paymentMethodId' => PaymentMethod::idFor(PaymentMethod::BOLETO),
            'parcelas' => 1,
            'categoriaId' => null,
        ],
    ]);
}

it('projeta a ocorrência da fila dentro da janela, cruzando a fronteira do mês', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create(['dia' => 5, 'proxima_em' => '2026-09-05']);
    ocorrenciaNaFila($user, $rec, '2026-08-05', 9900, 'Internet');

    $ocorrencias = app(ProjetarRecorrenciasPendentes::class)->naJanela(
        $user->id,
        agoraJanela()->startOfDay(),
        agoraJanela()->startOfDay()->addDays(30),
        agoraJanela(),
    );

    expect($ocorrencias)->toHaveCount(1)
        ->and($ocorrencias[0]['vencimento'])->toBe('2026-08-05')
        ->and($ocorrencias[0]['cents'])->toBe(9900);
});

it('exclui a ocorrência da fila fora da janela', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create(['dia' => 5, 'proxima_em' => '2026-09-05']);
    ocorrenciaNaFila($user, $rec, '2026-08-20', 9900, 'Fora');

    $ocorrencias = app(ProjetarRecorrenciasPendentes::class)->naJanela(
        $user->id,
        agoraJanela()->startOfDay(),
        agoraJanela()->startOfDay()->addDays(10),
        agoraJanela(),
    );

    expect($ocorrencias)->toBe([]);
});

it('aceita janela sem limite inferior (em atraso: tudo que venceu antes de hoje)', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create(['dia' => 10, 'proxima_em' => '2026-08-10']);
    ocorrenciaNaFila($user, $rec, '2026-05-10', 4500, 'Antiga');
    ocorrenciaNaFila($user, $rec, '2026-07-10', 5590, 'Recente');
    ocorrenciaNaFila($user, $rec, '2026-07-25', 5590, 'Ainda vai vencer');

    $ocorrencias = app(ProjetarRecorrenciasPendentes::class)->naJanela(
        $user->id,
        null,
        agoraJanela()->startOfDay()->subDay(),
        agoraJanela(),
    );

    expect(array_column($ocorrencias, 'descricao'))->toBe(['Antiga', 'Recente'])
        ->and(array_column($ocorrencias, 'status'))->toBe(['atraso', 'atraso']);
});

it('isola a fila por usuário na janela', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create(['dia' => 25, 'proxima_em' => '2026-08-25']);
    $recOutro = Recurrence::factory()->for($outro)->create(['dia' => 25, 'proxima_em' => '2026-08-25']);
    ocorrenciaNaFila($user, $rec, '2026-07-25', 5590, 'Minha');
    ocorrenciaNaFila($outro, $recOutro, '2026-07-25', 999900, 'Alheia');

    $ocorrencias = app(ProjetarRecorrenciasPendentes::class)->naJanela(
        $user->id,
        agoraJanela()->startOfDay(),
        agoraJanela()->startOfDay()->addDays(30),
        agoraJanela(),
    );

    expect($ocorrencias)->toHaveCount(1)
        ->and($ocorrencias[0]['descricao'])->toBe('Minha');
});

it('a consulta mensal continua funcionando (delegando à janela do mês)', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create(['dia' => 20, 'proxima_em' => '2026-08-20']);
    ocorrenciaNaFila($user, $rec, '2026-07-20', 5590, 'Netflix');
    ocorrenciaNaFila($user, $rec, '2026-08-20', 5590, 'Netflix agosto');

    $ocorrencias = app(ProjetarRecorrenciasPendentes::class)->para($user->id, '2026-07', agoraJanela());

    expect($ocorrencias)->toHaveCount(1)
        ->and($ocorrencias[0]['descricao'])->toBe('Netflix')
        ->and($ocorrencias[0]['status'])->toBe('previsto');
});
