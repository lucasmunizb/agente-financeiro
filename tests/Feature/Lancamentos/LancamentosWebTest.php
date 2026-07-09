<?php

declare(strict_types=1);

use App\Domain\Shared\OpaqueId;
use App\Models\Card;
use App\Models\Category;
use App\Models\Installment;
use App\Models\PaymentMethod;
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
