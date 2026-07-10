<?php

use App\Domain\Gastos\ConsultarGastos;
use App\Domain\Gastos\ResultadoConsultaGastos;
use App\Domain\IA\Guard\PayloadDeResposta;
use App\Models\Card;
use App\Models\Category;
use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\PendingConfirmation;
use App\Models\Recurrence;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
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

// ---- Recorrências previstas na soma do mês FUTURO (donut/bot ↔ extrato) ------------------
// O extrato já lista as recorrências previstas de um mês futuro; a soma por categoria (donut
// e bot) tem de INCLUIR os mesmos números — sem contar duas vezes no mês corrente/passado
// (esses já vêm do lançamento real). "Agora" é injetado; mês corrente = 2026-07.

/** "Agora" fixo do usuário: 09/07/2026 (mês corrente = 2026-07). */
function gastosAgora(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');
}

/** Recorrência mensal ativa fora de cartão. */
function recorrenciaAtiva(User $user, int $valorCents, int $dia, string $proximaEm, ?Category $categoria = null, string $descricao = 'Aluguel'): Recurrence
{
    return Recurrence::factory()->for($user)->create([
        'descricao' => $descricao, 'valor_cents' => $valorCents, 'dia' => $dia,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => $proximaEm,
        'categoria_id' => $categoria?->id,
    ]);
}

it('inclui as recorrências previstas do mês futuro no total e na quebra por categoria', function () {
    $user = User::factory()->create();
    $moradia = Category::factory()->for($user)->create(['nome' => 'Moradia']);

    gastoFiltravel($user, 50000, '2026-08-20');                              // gasto real futuro, sem categoria
    recorrenciaAtiva($user, 180000, 10, '2026-08-10', categoria: $moradia); // conta fixa prevista

    $r = app(ConsultarGastos::class)->para($user->id, '2026-08', agora: gastosAgora());

    expect($r->totalCents)->toBe(230000)
        ->and(centsDaCategoria($r, 'Moradia'))->toBe(180000)
        ->and(centsDaCategoria($r, 'Sem categoria'))->toBe(50000)
        ->and($r->trace->registros)->toBe(2); // 1 real + 1 prevista
});

it('não conta a recorrência no mês corrente — guard anti-dupla-contagem', function () {
    $user = User::factory()->create();

    gastoFiltravel($user, 50000, '2026-07-20');
    recorrenciaAtiva($user, 180000, 20, '2026-07-20');

    $r = app(ConsultarGastos::class)->para($user->id, '2026-07', agora: gastosAgora());

    expect($r->totalCents)->toBe(50000); // só o lançamento real; recorrência do mês não é projetada
});

it('não conta a recorrência em mês passado', function () {
    $user = User::factory()->create();

    gastoFiltravel($user, 50000, '2026-06-20');
    recorrenciaAtiva($user, 180000, 20, '2026-06-20');

    $r = app(ConsultarGastos::class)->para($user->id, '2026-06', agora: gastosAgora());

    expect($r->totalCents)->toBe(50000);
});

it('o filtro de cartão exclui as recorrências previstas (recorrência é fora de cartão)', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);

    gastoFiltravel($user, 70000, '2026-08-10', cartao: $cartao);
    recorrenciaAtiva($user, 180000, 10, '2026-08-10');

    $r = app(ConsultarGastos::class)->para($user->id, '2026-08', cartao: 'cartão pai', agora: gastosAgora());

    expect($r->totalCents)->toBe(70000);
});

it('o filtro por categoria inclui só a recorrência prevista daquela categoria', function () {
    $user = User::factory()->create();
    $moradia = Category::factory()->for($user)->create(['nome' => 'Moradia']);
    $lazer = Category::factory()->for($user)->create(['nome' => 'Lazer']);

    recorrenciaAtiva($user, 180000, 10, '2026-08-10', categoria: $moradia, descricao: 'Aluguel');
    recorrenciaAtiva($user, 5590, 5, '2026-08-05', categoria: $lazer, descricao: 'Netflix');

    $r = app(ConsultarGastos::class)->para($user->id, '2026-08', categoria: 'Moradia', agora: gastosAgora());

    expect($r->totalCents)->toBe(180000)
        ->and($r->porCategoria)->toHaveCount(1);
});

it('não conta a recorrência prevista quando o filtro é um status já resolvido (ex.: pago)', function () {
    $user = User::factory()->create();

    recorrenciaAtiva($user, 180000, 10, '2026-08-10');

    $r = app(ConsultarGastos::class)->para($user->id, '2026-08', status: StatusPagamento::PAGO, agora: gastosAgora());

    expect($r->totalCents)->toBe(0);
});

it('detalha a ocorrência prevista e permite seu valor/data no payload do guard', function () {
    $user = User::factory()->create();

    recorrenciaAtiva($user, 180000, 10, '2026-08-10');

    $r = app(ConsultarGastos::class)->para($user->id, '2026-08', detalhar: true, agora: gastosAgora());

    expect($r->itens)->toHaveCount(1)
        ->and($r->itens[0]['descricao'])->toBe('Aluguel')
        ->and($r->itens[0]['cents'])->toBe(180000)
        ->and($r->itens[0]['vencimento']->format('Y-m-d'))->toBe('2026-08-10')
        ->and($r->itens[0]['parcela'])->toBeNull();

    $payload = $r->payload();
    expect($payload->permiteValor(180000))->toBeTrue()
        ->and($payload->permiteData(10, 8, 2026))->toBeTrue();
});

it('isola as recorrências previstas por usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();

    recorrenciaAtiva($user, 180000, 10, '2026-08-10', descricao: 'Minha');
    recorrenciaAtiva($outro, 999900, 10, '2026-08-10', descricao: 'Alheia');

    $r = app(ConsultarGastos::class)->para($user->id, '2026-08', agora: gastosAgora());

    expect($r->totalCents)->toBe(180000);
});

it('inclui a ocorrência de recorrência da FILA (pendente) do mês corrente no total e por categoria', function () {
    $user = User::factory()->create();
    $moradia = Category::factory()->for($user)->create(['nome' => 'Moradia']);

    // Mês corrente (2026-07): molde não projeta (guard); a ocorrência vive na fila (pendente).
    (new App\Domain\Confirmacao\EnfileirarConfirmacao)->enfileirar(
        new App\Domain\Gasto\DadosGastoManual(
            userId: $user->id, descricao: 'Aluguel', valorTotalCents: 180000,
            dataCompra: CarbonImmutable::parse('2026-07-20', 'America/Sao_Paulo'),
            paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX), parcelas: 1,
            categoriaId: $moradia->id, origem: 'recorrencia',
            recurrenceId: Recurrence::factory()->for($user)->create()->id,
        ),
        PendingConfirmation::ORIGEM_RECORRENCIA,
    );

    $r = app(ConsultarGastos::class)->para($user->id, '2026-07', agora: gastosAgora());

    expect($r->totalCents)->toBe(180000)
        ->and(centsDaCategoria($r, 'Moradia'))->toBe(180000);
});

it('não conta a ocorrência pendente já confirmada (o lançamento real assume)', function () {
    $user = User::factory()->create();

    $pendente = (new App\Domain\Confirmacao\EnfileirarConfirmacao)->enfileirar(
        new App\Domain\Gasto\DadosGastoManual(
            userId: $user->id, descricao: 'Aluguel', valorTotalCents: 180000,
            dataCompra: CarbonImmutable::parse('2026-07-20', 'America/Sao_Paulo'),
            paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX), parcelas: 1,
            origem: 'recorrencia', recurrenceId: Recurrence::factory()->for($user)->create()->id,
        ),
        PendingConfirmation::ORIGEM_RECORRENCIA,
    );
    $pendente->update(['status' => PendingConfirmation::STATUS_CONFIRMADO]);

    $r = app(ConsultarGastos::class)->para($user->id, '2026-07', agora: gastosAgora());

    expect($r->totalCents)->toBe(0);
});
