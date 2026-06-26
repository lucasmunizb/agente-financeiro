<?php

use App\Ai\Tools\ConsultarDisponivelMes;
use App\Models\Income;
use App\Models\Installment;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

/*
 * Tool `consultar_disponivel_mes` (doc 02 §3.2/§3.3, barreira 3). É construída
 * AMARRADA ao usuário autenticado — o modelo só passa `mes`, nunca um user_id —,
 * executa a consulta determinística e devolve os números JÁ calculados + trace.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function umGasto(User $user, int $valorCents, string $vencimento): void
{
    $transaction = Transaction::factory()->for($user)->create(['valor_total_cents' => $valorCents]);
    Installment::factory()->for($transaction, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
}

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('devolve os valores já calculados e formatados em pt-BR para o mês pedido', function () {
    $user = User::factory()->create();
    Income::factory()->for($user)->create(['valor_cents' => 400000, 'data' => '2026-06-05']);
    umGasto($user, 140000, '2026-06-10');

    $saida = (string) (new ConsultarDisponivelMes($user))->handle(new Request(['mes' => '2026-06']));

    expect($saida)->toContain('2026-06')
        ->and($saida)->toContain('R$ 2.600,00'); // 4000 - 1400
});

it('usa o mês corrente (fuso SP) quando o mês não é informado', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-26 10:00:00', 'America/Sao_Paulo'));

    $user = User::factory()->create();
    Income::factory()->for($user)->create(['valor_cents' => 100000, 'data' => '2026-06-05']);

    $saida = (string) (new ConsultarDisponivelMes($user))->handle(new Request([]));

    expect($saida)->toContain('2026-06')
        ->and($saida)->toContain('R$ 1.000,00');
});

it('é escopada: a tool de um usuário não enxerga dados de outro', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    Income::factory()->for($outro)->create(['valor_cents' => 999900, 'data' => '2026-06-05']);
    umGasto($outro, 500000, '2026-06-10');

    $saida = (string) (new ConsultarDisponivelMes($user))->handle(new Request(['mes' => '2026-06']));

    expect($saida)->toContain('R$ 0,00')        // disponível do usuário sem dados
        ->and($saida)->not->toContain('9.999');  // nada do outro usuário vaza
});

it('expõe um schema com o parâmetro mes', function () {
    $schema = (new ConsultarDisponivelMes(User::factory()->create()))->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKey('mes');
});
