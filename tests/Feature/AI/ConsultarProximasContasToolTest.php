<?php

use App\Ai\Tools\ConsultarProximasContas;
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
 * Tool `consultar_proximas_contas` (doc 02 §3.2/§3.3, barreira 3). É construída AMARRADA
 * ao usuário autenticado — o modelo só passa `janela` (dias), nunca um user_id —, resolve
 * "hoje" no fuso SP, executa a consulta determinística e devolve os números JÁ calculados.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-26 10:00:00', 'America/Sao_Paulo'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function contaTool(User $user, int $valorCents, string $vencimento, string $descricao = 'Conta'): void
{
    $transaction = Transaction::factory()->for($user)->create([
        'descricao' => $descricao,
        'valor_total_cents' => $valorCents,
    ]);
    Installment::factory()->for($transaction, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
}

it('devolve o total e cada conta formatados em pt-BR para a janela pedida', function () {
    $user = User::factory()->create();
    contaTool($user, 50000, '2026-06-30', 'Aluguel');
    contaTool($user, 30000, '2026-07-02', 'Internet');

    $saida = (string) (new ConsultarProximasContas($user))->handle(new Request(['janela' => 30]));

    expect($saida)->toContain('R$ 800,00') // total
        ->and($saida)->toContain('Aluguel')
        ->and($saida)->toContain('30/06/2026')
        ->and($saida)->toContain('Internet');
});

it('usa a janela padrão de 30 dias quando não informada', function () {
    $user = User::factory()->create();
    contaTool($user, 25000, '2026-07-20'); // +24 dias, dentro de 30
    contaTool($user, 99900, '2026-08-10'); // +45 dias, fora de 30

    $saida = (string) (new ConsultarProximasContas($user))->handle(new Request([]));

    expect($saida)->toContain('R$ 250,00')
        ->and($saida)->not->toContain('999');
});

it('resolve "hoje" no fuso de São Paulo a partir do relógio', function () {
    $user = User::factory()->create();
    contaTool($user, 12300, '2026-06-26'); // exatamente hoje

    $saida = (string) (new ConsultarProximasContas($user))->handle(new Request(['janela' => 7]));

    expect($saida)->toContain('R$ 123,00')
        ->and($saida)->toContain('2026-06-26');
});

it('é escopada: a tool de um usuário não enxerga contas de outro', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    contaTool($outro, 999900, '2026-06-30');

    $saida = (string) (new ConsultarProximasContas($user))->handle(new Request(['janela' => 30]));

    expect($saida)->toContain('R$ 0,00')
        ->and($saida)->not->toContain('9.999');
});

it('expõe um schema com janela', function () {
    $schema = (new ConsultarProximasContas(User::factory()->create()))->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKey('janela');
});
