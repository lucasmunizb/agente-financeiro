<?php

use App\Ai\Tools\ConsultarGastos;
use App\Domain\IA\Consulta\ColetorDeConsultas;
use App\Models\Installment;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

/*
 * Amarra a tool ao coletor do Bloco 6: ao rodar, a tool registra no coletor o que
 * calculou (payload + trace), além de devolver o texto ao modelo. É isso que dá ao guard
 * o conjunto-verdade das tools efetivamente chamadas. Sem coletor, a tool segue
 * funcionando isolada (compatível com seus testes diretos).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function gastoColeta(User $user, int $valorCents, string $vencimento): void
{
    $transaction = Transaction::factory()->for($user)->create([
        'valor_total_cents' => $valorCents,
    ]);
    Installment::factory()->for($transaction, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
}

it('registra seu payload e sua fonte no coletor quando presente', function () {
    $user = User::factory()->create();
    gastoColeta($user, 150000, '2026-06-10');

    $coletor = new ColetorDeConsultas;
    $saida = (string) (new ConsultarGastos($user, $coletor))->handle(new Request(['periodo' => '2026-06']));

    expect($saida)->toContain('R$ 1.500,00') // texto ao modelo, inalterado
        ->and($coletor->payloadCombinado()->permiteValor(150000))->toBeTrue()
        ->and($coletor->fontes())->toHaveCount(1)
        ->and($coletor->fontes()[0]->ferramenta)->toBe('consultar_gastos');
});

it('sem coletor a tool funciona normalmente (uso isolado)', function () {
    $user = User::factory()->create();
    gastoColeta($user, 150000, '2026-06-10');

    $saida = (string) (new ConsultarGastos($user))->handle(new Request(['periodo' => '2026-06']));

    expect($saida)->toContain('gastos_periodo')
        ->and($saida)->toContain('R$ 1.500,00');
});
