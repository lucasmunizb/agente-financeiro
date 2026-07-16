<?php

use App\Domain\Confirmacao\EnfileirarConfirmacao;
use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Shared\OpaqueId;
use App\Domain\Lancamentos\ConsultarLancamentos;
use App\Domain\Lancamentos\ResultadoConsultaLancamentos;
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

/** Enfileira uma ocorrência de recorrência na fila (o que o agendador faz), fora de cartão. */
function pendenteRecorrenciaExtrato(User $user, int $cents, string $dataCompra, string $descricao = 'Netflix', ?Category $categoria = null): PendingConfirmation
{
    return (new EnfileirarConfirmacao)->enfileirar(
        new DadosGastoManual(
            userId: $user->id,
            descricao: $descricao,
            valorTotalCents: $cents,
            dataCompra: CarbonImmutable::parse($dataCompra, 'America/Sao_Paulo'),
            paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
            parcelas: 1,
            categoriaId: $categoria?->id,
            origem: 'recorrencia',
            recurrenceId: Recurrence::factory()->for($user)->create(['status' => Recurrence::STATUS_ATIVO])->id,
        ),
        PendingConfirmation::ORIGEM_RECORRENCIA,
        expiraEm: null,
    );
}

/** Busca um item (por descrição) em qualquer grupo do resultado. */
function itemDoExtrato(ResultadoConsultaLancamentos $r, string $descricao): ?array
{
    foreach ($r->grupos as $grupo) {
        foreach ($grupo['itens'] as $item) {
            if ($item['descricao'] === $descricao) {
                return $item;
            }
        }
    }

    return null;
}

/*
 * Consulta de extrato `consultar_lancamentos` (FE §7.6) — varredura determinística que
 * lista os lançamentos do usuário no período (uma linha por PARCELA vencendo no mês, mesma
 * base do dashboard/ConsultarGastos), agrupados por dia de vencimento, com filtros opcionais
 * (busca, categoria, forma, cartão, status) e o "total exibido" JÁ somado. O status de
 * exibição é derivado por DATA (spec 06b): venceu e ainda em aberto → atraso. A tela nunca
 * calcula dinheiro (regra 4); escopo ESTRITO por usuário.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function hojeExtrato(string $data = '2026-06-15'): CarbonImmutable
{
    return CarbonImmutable::parse($data.' 09:00:00', 'America/Sao_Paulo');
}

/**
 * Cria um lançamento de parcela única vencendo na data dada, com atributos opcionais.
 * Devolve a transaction.
 */
function lancamentoExtrato(
    User $user,
    int $valorCents,
    string $vencimento,
    string $statusCodigo = StatusPagamento::ABERTO,
    ?Category $categoria = null,
    ?Card $cartao = null,
    string $descricao = 'Mercado',
    ?string $forma = null,
    int $numero = 1,
    int $total = 1,
): Transaction {
    $tx = Transaction::factory()->for($user)->create([
        'valor_total_cents' => $valorCents,
        'descricao' => $descricao,
        'categoria_id' => $categoria?->id,
        'card_id' => $cartao?->id,
        'payment_method_id' => $forma !== null
            ? PaymentMethod::idFor($forma)
            : PaymentMethod::idFor(PaymentMethod::PIX),
    ]);

    Installment::factory()->for($tx, 'transaction')->create([
        'numero' => $numero,
        'total' => $total,
        'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor($statusCodigo),
    ]);

    return $tx;
}

/** Achata os itens de todos os grupos numa lista única (ordem preservada). */
function itensDoExtrato(ResultadoConsultaLancamentos $r): array
{
    return array_merge(...array_map(fn (array $g) => $g['itens'], $r->grupos)) ?: [];
}

it('lista as parcelas vencendo no período e soma o total exibido', function () {
    $user = User::factory()->create();

    lancamentoExtrato($user, 120000, '2026-06-10');
    lancamentoExtrato($user, 30000, '2026-06-25');
    lancamentoExtrato($user, 999900, '2026-07-01'); // fora do período

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato());

    expect($r)->toBeInstanceOf(ResultadoConsultaLancamentos::class)
        ->and($r->totalExibidoCents)->toBe(150000)
        ->and($r->registros)->toBe(2)
        ->and(itensDoExtrato($r))->toHaveCount(2);
});

it('agrupa por dia de vencimento, do mais recente ao mais antigo', function () {
    $user = User::factory()->create();

    lancamentoExtrato($user, 1000, '2026-06-10', descricao: 'Antigo');
    lancamentoExtrato($user, 2000, '2026-06-25', descricao: 'Recente');

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato());

    expect($r->grupos)->toHaveCount(2)
        ->and($r->grupos[0]['data']->toDateString())->toBe('2026-06-25')
        ->and($r->grupos[1]['data']->toDateString())->toBe('2026-06-10');
});

it('deriva o status de exibição por data: venceu e em aberto vira atraso', function () {
    $user = User::factory()->create();

    lancamentoExtrato($user, 1000, '2026-06-10', statusCodigo: StatusPagamento::ABERTO); // já venceu (hoje=15)
    lancamentoExtrato($user, 2000, '2026-06-20', statusCodigo: StatusPagamento::AGENDADO); // futuro

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato());
    $itens = collect(itensDoExtrato($r))->keyBy('descricao');

    expect(collect(itensDoExtrato($r))->firstWhere('cents', 1000)['status'])->toBe('atraso')
        ->and(collect(itensDoExtrato($r))->firstWhere('cents', 2000)['status'])->toBe('a_vencer');
});

it('mapeia pago/pago_parcial para pago e cancelado/estornado para cancelado', function () {
    $user = User::factory()->create();

    lancamentoExtrato($user, 1000, '2026-06-10', statusCodigo: StatusPagamento::PAGO);
    lancamentoExtrato($user, 2000, '2026-06-11', statusCodigo: StatusPagamento::PAGO_PARCIAL);

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato());

    expect(collect(itensDoExtrato($r))->pluck('status')->unique()->values()->all())->toBe(['pago']);
});

it('por padrão exclui pendente_revisao, cancelado e estornado', function () {
    $user = User::factory()->create();

    lancamentoExtrato($user, 1000, '2026-06-10', statusCodigo: StatusPagamento::ABERTO);
    lancamentoExtrato($user, 99900, '2026-06-11', statusCodigo: StatusPagamento::CANCELADO);
    lancamentoExtrato($user, 88800, '2026-06-12', statusCodigo: StatusPagamento::ESTORNADO);
    lancamentoExtrato($user, 77700, '2026-06-13', statusCodigo: StatusPagamento::PENDENTE_REVISAO);

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato());

    expect($r->totalExibidoCents)->toBe(1000)
        ->and(itensDoExtrato($r))->toHaveCount(1);
});

it('filtra pelo status de exibição pedido (pago)', function () {
    $user = User::factory()->create();

    lancamentoExtrato($user, 1000, '2026-06-10', statusCodigo: StatusPagamento::ABERTO);
    lancamentoExtrato($user, 2000, '2026-06-11', statusCodigo: StatusPagamento::PAGO);

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato(), status: 'pago');

    expect($r->totalExibidoCents)->toBe(2000)
        ->and(itensDoExtrato($r))->toHaveCount(1);
});

it('filtra atraso mostrando só o que venceu e segue em aberto', function () {
    $user = User::factory()->create();

    lancamentoExtrato($user, 1000, '2026-06-10', statusCodigo: StatusPagamento::ABERTO); // atraso
    lancamentoExtrato($user, 2000, '2026-06-20', statusCodigo: StatusPagamento::ABERTO); // a vencer

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato(), status: 'atraso');

    expect($r->totalExibidoCents)->toBe(1000);
});

it('mostra cancelados quando o status pedido é cancelado', function () {
    $user = User::factory()->create();

    lancamentoExtrato($user, 1000, '2026-06-10', statusCodigo: StatusPagamento::ABERTO);
    lancamentoExtrato($user, 99900, '2026-06-11', statusCodigo: StatusPagamento::CANCELADO);

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato(), status: 'cancelado');

    expect($r->totalExibidoCents)->toBe(99900);
});

it('busca por parte da descrição (case-insensitive)', function () {
    $user = User::factory()->create();

    lancamentoExtrato($user, 1000, '2026-06-10', descricao: 'Mercado Central');
    lancamentoExtrato($user, 2000, '2026-06-11', descricao: 'Posto Shell');

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato(), busca: 'mercado');

    expect($r->totalExibidoCents)->toBe(1000)
        ->and(itensDoExtrato($r)[0]['descricao'])->toBe('Mercado Central');
});

it('filtra por categoria, forma e cartão', function () {
    $user = User::factory()->create();
    $mercado = Category::factory()->for($user)->create(['nome' => 'Mercado']);
    $cartao = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);

    lancamentoExtrato($user, 1000, '2026-06-10', categoria: $mercado, forma: PaymentMethod::PIX);
    lancamentoExtrato($user, 2000, '2026-06-11', forma: PaymentMethod::PIX);
    lancamentoExtrato($user, 4000, '2026-06-12', cartao: $cartao, forma: PaymentMethod::CREDITO);

    $svc = app(ConsultarLancamentos::class);

    expect($svc->para($user->id, '2026-06', hojeExtrato(), categoriaId: $mercado->id)->totalExibidoCents)->toBe(1000)
        ->and($svc->para($user->id, '2026-06', hojeExtrato(), forma: PaymentMethod::CREDITO)->totalExibidoCents)->toBe(4000)
        ->and($svc->para($user->id, '2026-06', hojeExtrato(), cartaoId: $cartao->id)->totalExibidoCents)->toBe(4000);
});

it('rotula a parcela numero/total e traz categoria, forma e cartão no item', function () {
    $user = User::factory()->create();
    $mercado = Category::factory()->for($user)->create(['nome' => 'Mercado', 'cor' => '#1F6E5A']);
    $cartao = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);

    lancamentoExtrato($user, 30000, '2026-06-10', categoria: $mercado, cartao: $cartao,
        forma: PaymentMethod::CREDITO, descricao: 'Notebook', numero: 2, total: 3);

    $item = itensDoExtrato(app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato()))[0];

    expect($item['parcela'])->toBe('2/3')
        ->and($item['cents'])->toBe(10000) // 30000 / 3
        ->and($item['categoria']['nome'])->toBe('Mercado')
        ->and($item['categoria']['cor'])->toBe('#1F6E5A')
        ->and($item['forma'])->toBe(PaymentMethod::CREDITO)
        ->and($item['cartaoDescricao'])->toBe('Nubank')
        ->and($item['descricao'])->toBe('Notebook');
});

it('é isolado por usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();

    lancamentoExtrato($user, 30000, '2026-06-10', descricao: 'Meu gasto');
    lancamentoExtrato($outro, 555500, '2026-06-10', descricao: 'Gasto alheio');

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato());

    expect($r->totalExibidoCents)->toBe(30000)
        ->and(itensDoExtrato($r))->toHaveCount(1)
        ->and(itensDoExtrato($r)[0]['descricao'])->toBe('Meu gasto');
});

/*
 * Recorrência no extrato (F10 frontend / spec 10b): o item MATERIALIZADO ganha a flag
 * `recorrente`; num mês FUTURO as ocorrências PREVISTAS (ProjetarRecorrencias, read-only)
 * são MESCLADAS na listagem como itens `prevista=true` — mesmo guard anti-dupla-contagem
 * (mês corrente/passado ⇒ nada projetado, servido pela materialização just-in-time).
 */

it('marca o lançamento materializado como recorrente (e os demais como não)', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create();

    $tx = lancamentoExtrato($user, 5000, '2026-06-10', descricao: 'Netflix');
    $tx->update(['recurrence_id' => $rec->id]);
    lancamentoExtrato($user, 3000, '2026-06-11', descricao: 'Padaria');

    $itens = collect(itensDoExtrato(app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato())))
        ->keyBy('descricao');

    expect($itens['Netflix']['recorrente'])->toBeTrue()
        ->and($itens['Netflix']['prevista'])->toBeFalse()
        ->and($itens['Padaria']['recorrente'])->toBeFalse();
});

it('projeta as recorrências previstas de um mês futuro como itens do extrato', function () {
    $user = User::factory()->create();
    Recurrence::factory()->for($user)->create([
        'descricao' => 'Aluguel', 'valor_cents' => 180000, 'dia' => 10,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-07-10',
    ]);

    // hoje = 2026-06-15; 2026-08 é estritamente futuro.
    $item = itensDoExtrato(app(ConsultarLancamentos::class)->para($user->id, '2026-08', hojeExtrato()))[0];

    expect($item)->toMatchArray([
        'descricao' => 'Aluguel',
        'cents' => 180000,
        'status' => 'a_vencer',
        'prevista' => true,
        'recorrente' => true,
        'transactionId' => null,
    ]);
    expect($item['vencimento']->toDateString())->toBe('2026-08-10');
});

it('soma as previstas no total exibido e na contagem de registros', function () {
    $user = User::factory()->create();
    Recurrence::factory()->for($user)->create(['valor_cents' => 5590, 'dia' => 5, 'proxima_em' => '2026-07-05']);
    Recurrence::factory()->for($user)->create(['valor_cents' => 180000, 'dia' => 10, 'proxima_em' => '2026-07-10']);

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-08', hojeExtrato());

    expect($r->totalExibidoCents)->toBe(185590)
        ->and($r->registros)->toBe(2);
});

it('projeta no mês corrente a recorrência cujo dia ainda não chegou (molde não materializado)', function () {
    $user = User::factory()->create();
    Recurrence::factory()->for($user)->create(['dia' => 20, 'proxima_em' => '2026-06-20']);

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato());

    expect($r->registros)->toBe(1)
        ->and(itensDoExtrato($r)[0]['prevista'])->toBeTrue();
});

it('não projeta no mês corrente a recorrência já materializada — o ponteiro avançado a exclui', function () {
    $user = User::factory()->create();
    Recurrence::factory()->for($user)->create(['dia' => 20, 'proxima_em' => '2026-07-20']);

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato());

    expect($r->registros)->toBe(0);
});

it('mescla parcelas reais e previstas do mês futuro, ordenando os grupos por dia (desc)', function () {
    $user = User::factory()->create();
    lancamentoExtrato($user, 60000, '2026-08-20', statusCodigo: StatusPagamento::AGENDADO, descricao: 'Parcela');
    Recurrence::factory()->for($user)->create(['descricao' => 'Aluguel', 'valor_cents' => 180000, 'dia' => 10, 'proxima_em' => '2026-07-10']);

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-08', hojeExtrato());

    expect(array_column(itensDoExtrato($r), 'descricao'))->toBe(['Parcela', 'Aluguel'])
        ->and($r->registros)->toBe(2);
});

it('exclui as previstas ao filtrar por cartão (recorrência é fora de cartão)', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);
    Recurrence::factory()->for($user)->create(['dia' => 10, 'proxima_em' => '2026-07-10']);

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-08', hojeExtrato(), cartaoId: $cartao->id);

    expect($r->registros)->toBe(0);
});

it('filtra as previstas por categoria', function () {
    $user = User::factory()->create();
    $moradia = Category::factory()->for($user)->create(['nome' => 'Moradia']);
    $lazer = Category::factory()->for($user)->create(['nome' => 'Lazer']);
    Recurrence::factory()->for($user)->create(['descricao' => 'Aluguel', 'dia' => 10, 'proxima_em' => '2026-07-10', 'categoria_id' => $moradia->id]);

    $svc = app(ConsultarLancamentos::class);

    expect($svc->para($user->id, '2026-08', hojeExtrato(), categoriaId: $moradia->id)->registros)->toBe(1)
        ->and($svc->para($user->id, '2026-08', hojeExtrato(), categoriaId: $lazer->id)->registros)->toBe(0);
});

it('filtra as previstas por forma e por busca', function () {
    $user = User::factory()->create();
    Recurrence::factory()->for($user)->create([
        'descricao' => 'Aluguel', 'dia' => 10, 'proxima_em' => '2026-07-10',
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::BOLETO),
    ]);
    Recurrence::factory()->for($user)->create([
        'descricao' => 'Netflix', 'dia' => 5, 'proxima_em' => '2026-07-05',
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
    ]);

    $svc = app(ConsultarLancamentos::class);

    expect($svc->para($user->id, '2026-08', hojeExtrato(), forma: PaymentMethod::BOLETO)->registros)->toBe(1)
        ->and($svc->para($user->id, '2026-08', hojeExtrato(), busca: 'flix')->registros)->toBe(1)
        ->and(itensDoExtrato($svc->para($user->id, '2026-08', hojeExtrato(), busca: 'flix'))[0]['descricao'])->toBe('Netflix');
});

it('exclui as previstas quando o status filtrado não é a_vencer', function () {
    $user = User::factory()->create();
    Recurrence::factory()->for($user)->create(['dia' => 10, 'proxima_em' => '2026-07-10']);

    $svc = app(ConsultarLancamentos::class);

    expect($svc->para($user->id, '2026-08', hojeExtrato(), status: 'a_vencer')->registros)->toBe(1)
        ->and($svc->para($user->id, '2026-08', hojeExtrato(), status: 'pago')->registros)->toBe(0);
});

it('isola as previstas por usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    Recurrence::factory()->for($user)->create(['descricao' => 'Minha', 'dia' => 10, 'proxima_em' => '2026-07-10']);
    Recurrence::factory()->for($outro)->create(['descricao' => 'Alheia', 'dia' => 10, 'proxima_em' => '2026-07-10']);

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-08', hojeExtrato());

    expect($r->registros)->toBe(1)
        ->and(itensDoExtrato($r)[0]['descricao'])->toBe('Minha');
});

// ---- Ocorrências de recorrência na FILA (confirmação pendente) no extrato (fila↔extrato) ----

it('mostra a ocorrência de recorrência da fila como item PREVISTO, com o id do pendente e no total', function () {
    $user = User::factory()->create();

    // hoje = 2026-06-15; ocorrência vence 20/06 (ainda não venceu) ⇒ previsto.
    $pendente = pendenteRecorrenciaExtrato($user, 5590, '2026-06-20', 'Netflix');

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato());
    $item = itemDoExtrato($r, 'Netflix');

    expect($item)->not->toBeNull()
        ->and($item['recorrente'])->toBeTrue()
        ->and($item['prevista'])->toBeTrue()
        ->and($item['status'])->toBe('previsto')
        ->and($item['cents'])->toBe(5590)
        ->and($item['transactionId'])->toBeNull()
        ->and(OpaqueId::decode($item['pendenteId']))->toBe($pendente->id)
        ->and($r->totalExibidoCents)->toBe(5590);
});

it('marca como ATRASO a ocorrência pendente cujo dia já passou', function () {
    $user = User::factory()->create();

    // hoje = 2026-06-15; ocorrência venceu 10/06 e não foi paga ⇒ atraso.
    pendenteRecorrenciaExtrato($user, 5590, '2026-06-10', 'Netflix');

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato());

    expect(itemDoExtrato($r, 'Netflix')['status'])->toBe('atraso');
});

it('não mostra a ocorrência pendente já confirmada (o lançamento real assume)', function () {
    $user = User::factory()->create();
    $pendente = pendenteRecorrenciaExtrato($user, 5590, '2026-06-20', 'Netflix');
    $pendente->update(['status' => PendingConfirmation::STATUS_CONFIRMADO]);

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato());

    expect(itemDoExtrato($r, 'Netflix'))->toBeNull();
});

it('isola as ocorrências pendentes por usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    pendenteRecorrenciaExtrato($outro, 999900, '2026-06-20', 'Alheia');

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato());

    expect(itemDoExtrato($r, 'Alheia'))->toBeNull()
        ->and($r->totalExibidoCents)->toBe(0);
});

it('exclui a ocorrência pendente ao filtrar por cartão (recorrência é fora de cartão)', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);
    pendenteRecorrenciaExtrato($user, 5590, '2026-06-20', 'Netflix');

    $r = app(ConsultarLancamentos::class)->para($user->id, '2026-06', hojeExtrato(), cartaoId: $cartao->id);

    expect(itemDoExtrato($r, 'Netflix'))->toBeNull();
});
