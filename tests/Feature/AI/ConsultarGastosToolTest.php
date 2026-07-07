<?php

use App\Ai\Tools\ConsultarGastos;
use App\Models\Category;
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
 * Tool `consultar_gastos` (doc 02 §3.2/§3.3, barreira 3). É construída AMARRADA ao
 * usuário autenticado — o modelo só passa periodo/categoria/cartao/status, nunca um
 * user_id —, executa a consulta determinística e devolve os números JÁ calculados.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function gastoNoMes(User $user, int $valorCents, string $vencimento, ?Category $categoria = null): void
{
    $transaction = Transaction::factory()->for($user)->create([
        'valor_total_cents' => $valorCents,
        'categoria_id' => $categoria?->id,
    ]);
    Installment::factory()->for($transaction, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
}

it('devolve o total formatado em pt-BR para o período pedido', function () {
    $user = User::factory()->create();
    gastoNoMes($user, 80000, '2026-06-10');
    gastoNoMes($user, 70000, '2026-06-20');

    $saida = (string) (new ConsultarGastos($user))->handle(new Request(['periodo' => '2026-06']));

    expect($saida)->toContain('2026-06')
        ->and($saida)->toContain('R$ 1.500,00'); // 800 + 700
});

it('usa o mês corrente (fuso SP) quando o período não é informado', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-26 10:00:00', 'America/Sao_Paulo'));

    $user = User::factory()->create();
    gastoNoMes($user, 25000, '2026-06-05');

    $saida = (string) (new ConsultarGastos($user))->handle(new Request([]));

    expect($saida)->toContain('2026-06')
        ->and($saida)->toContain('R$ 250,00');
});

it('repassa o filtro de categoria ao domínio', function () {
    $user = User::factory()->create();
    $alimentacao = Category::factory()->for($user)->create(['nome' => 'Alimentação']);
    gastoNoMes($user, 80000, '2026-06-10', $alimentacao);
    gastoNoMes($user, 50000, '2026-06-15'); // sem categoria, deve ser excluído pelo filtro

    $saida = (string) (new ConsultarGastos($user))
        ->handle(new Request(['periodo' => '2026-06', 'categoria' => 'Alimentação']));

    expect($saida)->toContain('R$ 800,00')
        ->and($saida)->not->toContain('R$ 1.300,00');
});

it('é escopada: a tool de um usuário não enxerga gastos de outro', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    gastoNoMes($outro, 999900, '2026-06-10');

    $saida = (string) (new ConsultarGastos($user))->handle(new Request(['periodo' => '2026-06']));

    expect($saida)->toContain('R$ 0,00')
        ->and($saida)->not->toContain('9.999');
});

it('expõe um schema com periodo, categoria, cartao, status e detalhar', function () {
    $schema = (new ConsultarGastos(User::factory()->create()))->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKeys(['periodo', 'categoria', 'cartao', 'status', 'detalhar']);
});

it('lista cada gasto quando detalhar=true (descrição, valor e data)', function () {
    $user = User::factory()->create();
    $futebol = Category::factory()->for($user)->create(['nome' => 'Futebol']);

    $t = Transaction::factory()->for($user)->create([
        'valor_total_cents' => 6000, 'categoria_id' => $futebol->id, 'descricao' => 'Aluguel de quadra',
    ]);
    Installment::factory()->for($t, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => '2026-07-05',
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);

    $saida = (string) (new ConsultarGastos($user))
        ->handle(new Request(['periodo' => '2026-07', 'categoria' => 'Futebol', 'detalhar' => true]));

    expect($saida)->toContain('Aluguel de quadra')
        ->and($saida)->toContain('R$ 60,00')
        ->and($saida)->toContain('05/07/2026');
});

it('não lista os itens quando detalhar não é pedido', function () {
    $user = User::factory()->create();
    gastoNoMes($user, 6000, '2026-07-05');

    $saida = (string) (new ConsultarGastos($user))->handle(new Request(['periodo' => '2026-07']));

    expect($saida)->not->toContain('05/07/2026');
});
