<?php

declare(strict_types=1);

use App\Domain\Confirmacao\EnfileirarConfirmacao;
use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Shared\OpaqueId;
use App\Models\Card;
use App\Models\PendingConfirmation;
use App\Models\Category;
use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Lançamentos — lista/extrato (FE §7.6). Fiação da tela com o domínio já testado
 * ({@see App\Domain\Lancamentos\ConsultarLancamentos}): valores reais, agrupados por dia e
 * formatados na borda (regra 5), sem cálculo no cliente (regra 4), escopo estrito por
 * usuário. "Hoje" é congelado.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 09:00:00', 'America/Sao_Paulo'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function lancamentoWeb(
    User $user,
    int $cents,
    string $venc,
    string $descricao = 'Mercado',
    string $statusCodigo = StatusPagamento::ABERTO,
    ?Category $categoria = null,
    ?Card $cartao = null,
    ?string $forma = null,
    int $numero = 1,
    int $total = 1,
): Transaction {
    $tx = Transaction::factory()->for($user)->create([
        'valor_total_cents' => $cents,
        'descricao' => $descricao,
        'categoria_id' => $categoria?->id,
        'card_id' => $cartao?->id,
        'payment_method_id' => PaymentMethod::idFor($forma ?? PaymentMethod::PIX),
    ]);
    Installment::factory()->for($tx, 'transaction')->create([
        'numero' => $numero, 'total' => $total, 'vencimento' => $venc,
        'status_id' => StatusPagamento::idFor($statusCodigo),
    ]);

    return $tx;
}

it('exige login', function () {
    $this->get('/lancamentos')->assertRedirect(route('login'));
});

it('no mês FUTURO lista as recorrências previstas com o selo "Previsto" (spec 10b)', function () {
    $user = User::factory()->create();
    Recurrence::factory()->for($user)->create([
        'descricao' => 'Netflix', 'valor_cents' => 5590, 'dia' => 5,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-07-05',
    ]);

    // hoje = 2026-06-15; agosto é estritamente futuro.
    $this->actingAs($user)->get('/lancamentos?mes=2026-08')
        ->assertOk()
        ->assertSee('Netflix')
        ->assertSee('Previsto')
        ->assertSee('R$ 55,90');
});

function pendenteRecorrenciaWeb(User $user, string $dataCompra = '2026-06-10'): PendingConfirmation
{
    $rec = Recurrence::factory()->for($user)->create([
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-07-05',
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
    ]);

    return (new EnfileirarConfirmacao)->enfileirar(
        new DadosGastoManual(
            userId: $user->id, descricao: 'Netflix', valorTotalCents: 5590,
            dataCompra: CarbonImmutable::parse($dataCompra, 'America/Sao_Paulo'),
            paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX), parcelas: 1,
            origem: 'recorrencia', recurrenceId: $rec->id,
        ),
        PendingConfirmation::ORIGEM_RECORRENCIA,
    );
}

it('marca a recorrência da fila como paga: cria o lançamento pago e resolve o pendente', function () {
    $user = User::factory()->create();
    $pendente = pendenteRecorrenciaWeb($user);

    $this->actingAs($user)
        ->post(route('lancamentos.recorrencia.pagar', $pendente->opaqueId()))
        ->assertRedirect()
        ->assertSessionHas('sucesso');

    $tx = Transaction::where('recurrence_id', $pendente->recurrence_id)->first();
    expect($tx)->not->toBeNull()
        ->and($tx->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO))
        ->and($pendente->fresh()->status)->toBe(PendingConfirmation::STATUS_CONFIRMADO);
});

it('mostra a ocorrência de recorrência da fila no extrato como ATRASO com botão "marcar como pago"', function () {
    $user = User::factory()->create();
    // hoje = 2026-06-15; ocorrência venceu 10/06 e não foi paga ⇒ atraso.
    pendenteRecorrenciaWeb($user, '2026-06-10');

    $html = $this->actingAs($user)->get('/lancamentos')
        ->assertOk()
        ->assertSee('Netflix')
        ->assertSee('Atraso')
        ->assertSee('Confirmar pagamento')
        ->getContent();

    expect($html)->toContain('/lancamentos/recorrencia/')
        ->and($html)->toContain('/pagar');
});

it('não paga a ocorrência pendente de outro usuário (404)', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $pendente = pendenteRecorrenciaWeb($outro);

    $this->actingAs($user)
        ->post(route('lancamentos.recorrencia.pagar', $pendente->opaqueId()))
        ->assertNotFound();

    expect(Transaction::count())->toBe(0);
});

it('no mês CORRENTE não projeta previstas — servido pela materialização (regressão 10b)', function () {
    $user = User::factory()->create();
    Recurrence::factory()->for($user)->create([
        'descricao' => 'Netflix', 'valor_cents' => 5590, 'dia' => 20,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-06-20',
    ]);

    $this->actingAs($user)->get('/lancamentos')
        ->assertOk()
        ->assertDontSee('Previsto');
});

it('mostra o estado vazio quando o usuário não tem lançamentos', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/lancamentos')
        ->assertOk()
        ->assertSee('Lançamentos');
});

it('lista os lançamentos reais do mês, agrupados e já formatados', function () {
    $user = User::factory()->create();
    $mercado = Category::factory()->for($user)->create(['nome' => 'Mercado']);
    $cartao = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);

    // Parcela 2/3 de R$ 450,00 → R$ 150,00 nesta parcela (valor derivado pelo domínio).
    lancamentoWeb($user, 45000, '2026-06-14', 'Mercado Central', StatusPagamento::PAGO, $mercado, $cartao, PaymentMethod::CREDITO, numero: 2, total: 3);
    lancamentoWeb($user, 28000, '2026-06-10', 'Posto Shell', StatusPagamento::ABERTO);

    $resp = $this->actingAs($user)->get('/lancamentos')->assertOk();

    $resp->assertSee('Mercado Central')
        ->assertSee('Mercado')          // chip de categoria
        ->assertSee('Nubank')           // cartão
        ->assertSee('2/3')              // parcela
        ->assertSee('R$ 150,00')        // 15000 formatado
        ->assertSee('Posto Shell')
        ->assertSee('R$ 280,00')
        ->assertSee('R$ 430,00');       // total exibido (150 + 280)
});

it('filtra por status via query (?status=pago)', function () {
    $user = User::factory()->create();

    lancamentoWeb($user, 15000, '2026-06-14', 'Pago aqui', StatusPagamento::PAGO);
    lancamentoWeb($user, 28000, '2026-06-10', 'Em aberto aqui', StatusPagamento::ABERTO);

    $this->actingAs($user)->get('/lancamentos?status=pago')
        ->assertOk()
        ->assertSee('Pago aqui')
        ->assertDontSee('Em aberto aqui')
        ->assertSee('R$ 150,00')
        ->assertDontSee('R$ 280,00');
});

it('busca por descrição via query (?busca=)', function () {
    $user = User::factory()->create();

    lancamentoWeb($user, 15000, '2026-06-14', 'Mercado Central');
    lancamentoWeb($user, 28000, '2026-06-10', 'Posto Shell');

    $this->actingAs($user)->get('/lancamentos?busca=mercado')
        ->assertOk()
        ->assertSee('Mercado Central')
        ->assertDontSee('Posto Shell');
});

it('mostra copy de "sem resultado" quando o filtro não casa nada', function () {
    $user = User::factory()->create();
    lancamentoWeb($user, 15000, '2026-06-14', 'Mercado Central');

    $this->actingAs($user)->get('/lancamentos?busca=inexistente')
        ->assertOk()
        ->assertSee('Nenhum lançamento neste filtro');
});

it('linka o detalhe por token OPACO (nunca o id real), o editar com ?editar=1 e oferece "Novo lançamento"', function () {
    $user = User::factory()->create();
    $tx = lancamentoWeb($user, 5000, '2026-06-12', 'Mercado');

    $html = $this->actingAs($user)->get('/lancamentos')
        ->assertOk()
        ->assertSee(route('lancamentos.create'), false)
        ->getContent();

    // O id real NÃO aparece no path.
    expect($html)->not->toContain('/lancamentos/'.$tx->id.'"')
        ->and($html)->not->toContain('/lancamentos/'.$tx->id.'?');

    // O link do editar é um token opaco que decodifica para ESTE tx.
    expect(preg_match('#/lancamentos/([A-Za-z0-9_-]+)\?editar=1#', $html, $m))->toBe(1)
        ->and(OpaqueId::decode($m[1]))->toBe($tx->id);
});

it('filtra por categoria via token OPACO na query (?categoria=<token>)', function () {
    $user = User::factory()->create();
    $mercado = Category::factory()->for($user)->create(['nome' => 'Mercado']);
    $lazer = Category::factory()->for($user)->create(['nome' => 'Lazer']);

    lancamentoWeb($user, 15000, '2026-06-14', 'Compra mercado', StatusPagamento::ABERTO, $mercado);
    lancamentoWeb($user, 28000, '2026-06-10', 'Cinema', StatusPagamento::ABERTO, $lazer);

    $this->actingAs($user)->get('/lancamentos?categoria='.OpaqueId::encode($mercado->id))
        ->assertOk()
        ->assertSee('Compra mercado')
        ->assertDontSee('Cinema');
});

it('ignora um id REAL de categoria na query — só o token vale (filtro não aplicado)', function () {
    $user = User::factory()->create();
    $mercado = Category::factory()->for($user)->create(['nome' => 'Mercado']);
    $lazer = Category::factory()->for($user)->create(['nome' => 'Lazer']);

    lancamentoWeb($user, 15000, '2026-06-14', 'Compra mercado', StatusPagamento::ABERTO, $mercado);
    lancamentoWeb($user, 28000, '2026-06-10', 'Cinema', StatusPagamento::ABERTO, $lazer);

    // Id em claro não filtra (decode falha → filtro nulo): a lista sai inteira.
    $this->actingAs($user)->get('/lancamentos?categoria='.$mercado->id)
        ->assertOk()
        ->assertSee('Compra mercado')
        ->assertSee('Cinema');
});

it('renderiza os <option> de filtro com value criptografado (nunca o id real)', function () {
    $user = User::factory()->create();
    $mercado = Category::factory()->for($user)->create(['nome' => 'Mercado']);
    $cartao = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);
    lancamentoWeb($user, 15000, '2026-06-14', 'Compra', StatusPagamento::ABERTO, $mercado);

    $html = $this->actingAs($user)->get('/lancamentos')->assertOk()->getContent();

    // O value do option de categoria/cartão não é o id em claro; é um token que decodifica.
    expect($html)->not->toContain('value="'.$mercado->id.'"')
        ->and($html)->not->toContain('value="'.$cartao->id.'"');
    expect(preg_match('#<option value="([A-Za-z0-9_-]+)"[^>]*>Mercado</option>#', $html, $m))->toBe(1)
        ->and(OpaqueId::decode($m[1]))->toBe($mercado->id);
});

it('respeita ?estado= como afordância de revisão das variações', function () {
    $user = User::factory()->create();
    lancamentoWeb($user, 5000, '2026-06-12', 'Mercado');

    // vazio (mesmo com dados), sem-resultado e carregando são revisáveis pela query.
    $this->actingAs($user)->get('/lancamentos?estado=vazio')
        ->assertOk()->assertSee('Nenhum lançamento registrado ainda');

    $this->actingAs($user)->get('/lancamentos?estado=sem-resultado')
        ->assertOk()->assertSee('Nenhum lançamento neste filtro');

    $this->actingAs($user)->get('/lancamentos?estado=carregando')
        ->assertOk()->assertSee('Carregando os lançamentos');
});

it('é isolado por usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();

    lancamentoWeb($user, 5000, '2026-06-12', 'Meu gasto');
    lancamentoWeb($outro, 999900, '2026-06-12', 'Gasto alheio');

    $this->actingAs($user)->get('/lancamentos')
        ->assertOk()
        ->assertSee('Meu gasto')
        ->assertDontSee('Gasto alheio')
        ->assertDontSee('R$ 9.999,00');
});
