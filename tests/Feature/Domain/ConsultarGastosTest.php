<?php

use App\Domain\Gastos\ConsultarGastos;
use App\Domain\Gastos\ResultadoConsultaGastos;
use App\Domain\IA\Guard\PayloadDeResposta;
use App\Models\Card;
use App\Models\Category;
use App\Models\Installment;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Camada de consulta `consultar_gastos` (doc 02 §3.2). Tool com escopo por usuário:
 * soma as parcelas vencendo no período (mesma base do "disponível"/consumo), com
 * filtros opcionais por categoria, cartão e status, e devolve dados JÁ calculados +
 * quebra por categoria + trace. A IA não calcula — só redige sobre estes números.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

/**
 * Cria um gasto de parcela única vencendo na data dada, com categoria/cartão/status
 * opcionais. Devolve a transaction.
 */
function gastoFiltravel(
    User $user,
    int $valorCents,
    string $vencimento,
    ?Category $categoria = null,
    ?Card $cartao = null,
    string $statusCodigo = StatusPagamento::ABERTO,
): Transaction {
    $transaction = Transaction::factory()->for($user)->create([
        'valor_total_cents' => $valorCents,
        'categoria_id' => $categoria?->id,
        'card_id' => $cartao?->id,
    ]);

    Installment::factory()->for($transaction, 'transaction')->create([
        'numero' => 1,
        'total' => 1,
        'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor($statusCodigo),
    ]);

    return $transaction;
}

/** Soma de centavos de uma categoria na quebra do resultado (0 se ausente). */
function centsDaCategoria(ResultadoConsultaGastos $r, string $nome): int
{
    foreach ($r->porCategoria as $linha) {
        if ($linha['nome'] === $nome) {
            return $linha['cents'];
        }
    }

    return 0;
}

it('soma as parcelas vencendo no período, sem filtros', function () {
    $user = User::factory()->create();

    gastoFiltravel($user, 120000, '2026-06-10');
    gastoFiltravel($user, 30000, '2026-06-25');
    gastoFiltravel($user, 999900, '2026-07-01'); // fora do período

    $resultado = app(ConsultarGastos::class)->para($user->id, '2026-06');

    expect($resultado)->toBeInstanceOf(ResultadoConsultaGastos::class)
        ->and($resultado->totalCents)->toBe(150000);
});

it('quebra o total por categoria, resolvendo os nomes', function () {
    $user = User::factory()->create();
    $alimentacao = Category::factory()->for($user)->create(['nome' => 'Alimentação']);
    $transporte = Category::factory()->for($user)->create(['nome' => 'Transporte']);

    gastoFiltravel($user, 80000, '2026-06-10', categoria: $alimentacao);
    gastoFiltravel($user, 20000, '2026-06-12', categoria: $alimentacao);
    gastoFiltravel($user, 50000, '2026-06-15', categoria: $transporte);
    gastoFiltravel($user, 10000, '2026-06-18'); // sem categoria

    $resultado = app(ConsultarGastos::class)->para($user->id, '2026-06');

    expect($resultado->totalCents)->toBe(160000)
        ->and(centsDaCategoria($resultado, 'Alimentação'))->toBe(100000)
        ->and(centsDaCategoria($resultado, 'Transporte'))->toBe(50000)
        ->and(centsDaCategoria($resultado, 'Sem categoria'))->toBe(10000);
});

it('filtra por categoria (case-insensitive)', function () {
    $user = User::factory()->create();
    $alimentacao = Category::factory()->for($user)->create(['nome' => 'Alimentação']);
    $transporte = Category::factory()->for($user)->create(['nome' => 'Transporte']);

    gastoFiltravel($user, 80000, '2026-06-10', categoria: $alimentacao);
    gastoFiltravel($user, 50000, '2026-06-15', categoria: $transporte);

    $resultado = app(ConsultarGastos::class)->para($user->id, '2026-06', categoria: 'alimentação');

    expect($resultado->totalCents)->toBe(80000)
        ->and($resultado->porCategoria)->toHaveCount(1);
});

it('filtra por cartão, tanto pela descrição quanto pelos 4 dígitos', function () {
    $user = User::factory()->create();
    $cartaoPai = Card::factory()->for($user)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);
    $cartaoMae = Card::factory()->for($user)->create(['descricao' => 'cartão mãe', 'final_4' => '5678']);

    gastoFiltravel($user, 70000, '2026-06-10', cartao: $cartaoPai);
    gastoFiltravel($user, 40000, '2026-06-15', cartao: $cartaoMae);

    $porDescricao = app(ConsultarGastos::class)->para($user->id, '2026-06', cartao: 'cartão pai');
    $porFinal4 = app(ConsultarGastos::class)->para($user->id, '2026-06', cartao: '1234');

    expect($porDescricao->totalCents)->toBe(70000)
        ->and($porFinal4->totalCents)->toBe(70000);
});

it('sem filtro de status, exclui pendente_revisao, cancelado e estornado', function () {
    $user = User::factory()->create();

    gastoFiltravel($user, 10000, '2026-06-10', statusCodigo: StatusPagamento::ABERTO);
    gastoFiltravel($user, 99900, '2026-06-11', statusCodigo: StatusPagamento::CANCELADO);
    gastoFiltravel($user, 88800, '2026-06-12', statusCodigo: StatusPagamento::ESTORNADO);
    gastoFiltravel($user, 77700, '2026-06-13', statusCodigo: StatusPagamento::PENDENTE_REVISAO);

    $resultado = app(ConsultarGastos::class)->para($user->id, '2026-06');

    expect($resultado->totalCents)->toBe(10000);
});

it('com filtro de status, mostra exatamente o status pedido (mesmo se normalmente excluído)', function () {
    $user = User::factory()->create();

    gastoFiltravel($user, 10000, '2026-06-10', statusCodigo: StatusPagamento::ABERTO);
    gastoFiltravel($user, 99900, '2026-06-11', statusCodigo: StatusPagamento::CANCELADO);

    $resultado = app(ConsultarGastos::class)->para($user->id, '2026-06', status: StatusPagamento::CANCELADO);

    expect($resultado->totalCents)->toBe(99900);
});

it('isola por usuário: ignora gastos de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();

    gastoFiltravel($user, 30000, '2026-06-10');
    gastoFiltravel($outro, 555500, '2026-06-10');

    $resultado = app(ConsultarGastos::class)->para($user->id, '2026-06');

    expect($resultado->totalCents)->toBe(30000);
});

it('carrega um trace com a ferramenta, os filtros efetivos e a contagem de parcelas', function () {
    $user = User::factory()->create();
    $alimentacao = Category::factory()->for($user)->create(['nome' => 'Alimentação']);

    gastoFiltravel($user, 80000, '2026-06-10', categoria: $alimentacao);
    gastoFiltravel($user, 20000, '2026-06-12', categoria: $alimentacao);

    $trace = app(ConsultarGastos::class)->para($user->id, '2026-06', categoria: 'Alimentação')->trace;

    expect($trace->ferramenta)->toBe('consultar_gastos')
        ->and($trace->filtros['periodo'])->toBe('2026-06')
        ->and($trace->filtros['categoria'])->toBe('Alimentação')
        ->and($trace->registros)->toBe(2);
});

it('expõe um payload para o guard com o total e os subtotais por categoria', function () {
    $user = User::factory()->create();
    $alimentacao = Category::factory()->for($user)->create(['nome' => 'Alimentação']);

    gastoFiltravel($user, 80000, '2026-06-10', categoria: $alimentacao);
    gastoFiltravel($user, 20000, '2026-06-12'); // sem categoria

    $payload = app(ConsultarGastos::class)->para($user->id, '2026-06')->payload();

    expect($payload)->toBeInstanceOf(PayloadDeResposta::class)
        ->and($payload->permiteValor(100000))->toBeTrue() // total
        ->and($payload->permiteValor(80000))->toBeTrue()  // subtotal alimentação
        ->and($payload->permiteValor(123456))->toBeFalse(); // valor inventado
});

it('não detalha os itens por padrão — só total e quebra por categoria', function () {
    $user = User::factory()->create();

    gastoFiltravel($user, 6000, '2026-07-05');

    $resultado = app(ConsultarGastos::class)->para($user->id, '2026-07');

    expect($resultado->itens)->toBe([])
        ->and($resultado->totalCents)->toBe(6000);
});

it('detalha cada gasto individual quando detalhar=true (descrição, valor, vencimento, parcela)', function () {
    $user = User::factory()->create();
    $futebol = Category::factory()->for($user)->create(['nome' => 'Futebol']);

    $t1 = Transaction::factory()->for($user)->create([
        'valor_total_cents' => 6000, 'categoria_id' => $futebol->id, 'descricao' => 'Aluguel de quadra',
    ]);
    Installment::factory()->for($t1, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => '2026-07-20',
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);

    $t2 = Transaction::factory()->for($user)->create([
        'valor_total_cents' => 5000, 'categoria_id' => $futebol->id, 'descricao' => 'Chuteira',
    ]);
    Installment::factory()->for($t2, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => '2026-07-05',
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);

    $resultado = app(ConsultarGastos::class)->para($user->id, '2026-07', categoria: 'Futebol', detalhar: true);

    expect($resultado->totalCents)->toBe(11000)
        ->and($resultado->itens)->toHaveCount(2)
        // ordenado por vencimento asc: Chuteira (05/07) antes de Aluguel (20/07)
        ->and($resultado->itens[0]['descricao'])->toBe('Chuteira')
        ->and($resultado->itens[0]['cents'])->toBe(5000)
        ->and($resultado->itens[0]['vencimento']->format('Y-m-d'))->toBe('2026-07-05')
        ->and($resultado->itens[0]['parcela'])->toBeNull()
        ->and($resultado->itens[1]['descricao'])->toBe('Aluguel de quadra')
        ->and($resultado->itens[1]['cents'])->toBe(6000);
});

it('rotula a parcela (numero/total) quando o gasto é parcelado', function () {
    $user = User::factory()->create();

    $t = Transaction::factory()->for($user)->create(['valor_total_cents' => 30000, 'descricao' => 'Notebook']);
    Installment::factory()->for($t, 'transaction')->create([
        'numero' => 2, 'total' => 3, 'vencimento' => '2026-07-10',
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);

    $resultado = app(ConsultarGastos::class)->para($user->id, '2026-07', detalhar: true);

    expect($resultado->itens[0]['parcela'])->toBe('2/3');
});

it('inclui o valor e a data de cada item detalhado no payload do guard', function () {
    $user = User::factory()->create();
    $futebol = Category::factory()->for($user)->create(['nome' => 'Futebol']);

    $t = Transaction::factory()->for($user)->create([
        'valor_total_cents' => 6000, 'categoria_id' => $futebol->id, 'descricao' => 'Quadra',
    ]);
    Installment::factory()->for($t, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => '2026-07-05',
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);

    $payload = app(ConsultarGastos::class)->para($user->id, '2026-07', detalhar: true)->payload();

    expect($payload->permiteValor(6000))->toBeTrue()
        ->and($payload->permiteData(5, 7, 2026))->toBeTrue();
});
