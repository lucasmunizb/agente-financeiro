<?php

use App\Ai\Tools\ConsultarFaturaCartao;
use App\Models\Card;
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
 * Tool `consultar_fatura_cartao` (doc 02 §3.2/§3.3, barreira 3). Construída AMARRADA ao
 * usuário autenticado — o modelo só passa `cartao` e `competencia`, nunca um user_id —,
 * executa a consulta determinística e devolve o extrato JÁ calculado. `competencia`
 * ausente assume o mês corrente (fuso SP).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-26 10:00:00', 'America/Sao_Paulo'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function compraTool(
    User $user,
    Card $cartao,
    int $valorTotalCents,
    string $vencimento,
    string $descricao = 'Compra',
): void {
    $transaction = Transaction::factory()->for($user)->create([
        'descricao' => $descricao,
        'valor_total_cents' => $valorTotalCents,
        'card_id' => $cartao->id,
    ]);
    Installment::factory()->for($transaction, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
}

it('devolve o total e cada cobrança formatados em pt-BR para o cartão e a competência pedidos', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);
    compraTool($user, $cartao, 70000, '2026-06-10', 'Mercado');
    compraTool($user, $cartao, 30000, '2026-06-20', 'iFood');

    $saida = (string) (new ConsultarFaturaCartao($user))
        ->handle(new Request(['cartao' => 'cartão pai', 'competencia' => '2026-06']));

    expect($saida)->toContain('R$ 1.000,00') // total
        ->and($saida)->toContain('Mercado')
        ->and($saida)->toContain('iFood')
        ->and($saida)->toContain('1234'); // identifica o cartão
});

it('usa a competência do mês corrente quando não informada', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);
    compraTool($user, $cartao, 25000, '2026-06-15'); // mês corrente
    compraTool($user, $cartao, 99900, '2026-07-15'); // próxima competência

    $saida = (string) (new ConsultarFaturaCartao($user))
        ->handle(new Request(['cartao' => 'cartão pai']));

    expect($saida)->toContain('R$ 250,00')
        ->and($saida)->not->toContain('999');
});

it('é escopada: a tool de um usuário não enxerga a fatura de outro', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $cartaoAlheio = Card::factory()->for($outro)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);
    compraTool($outro, $cartaoAlheio, 999900, '2026-06-10');

    $saida = (string) (new ConsultarFaturaCartao($user))
        ->handle(new Request(['cartao' => 'cartão pai', 'competencia' => '2026-06']));

    expect($saida)->toContain('R$ 0,00')
        ->and($saida)->not->toContain('9.999');
});

it('expõe um schema com cartao e competencia', function () {
    $schema = (new ConsultarFaturaCartao(User::factory()->create()))->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKey('cartao')
        ->and($schema)->toHaveKey('competencia');
});
