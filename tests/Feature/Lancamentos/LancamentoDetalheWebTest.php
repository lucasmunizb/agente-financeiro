<?php

declare(strict_types=1);

use App\Domain\Shared\OpaqueId;
use App\Models\Card;
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
 * Detalhe do lançamento (FE §7.8). Fiação da tela com o domínio já testado
 * ({@see App\Domain\Lancamentos\ConsultarLancamentoDetalhe}): metadados + parcelas com status
 * derivado por data e valores formatados na borda (regra 5), sem cálculo no cliente (regra 4),
 * escopo ESTRITO por usuário. "Hoje" é congelado.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 09:00:00', 'America/Sao_Paulo'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * @param  list<array{numero:int,total:int,vencimento:string,status:string}>  $parcelas
 */
function txDetalheWeb(
    User $user,
    int $cents,
    array $parcelas,
    string $descricao = 'Mercado do mês',
    ?Category $categoria = null,
    ?Card $cartao = null,
    ?string $forma = null,
): Transaction {
    $tx = Transaction::factory()->for($user)->create([
        'valor_total_cents' => $cents,
        'descricao' => $descricao,
        'categoria_id' => $categoria?->id,
        'card_id' => $cartao?->id,
        'payment_method_id' => PaymentMethod::idFor($forma ?? PaymentMethod::PIX),
        'data_compra' => '2026-06-10',
    ]);
    foreach ($parcelas as $p) {
        Installment::factory()->for($tx, 'transaction')->create([
            'numero' => $p['numero'], 'total' => $p['total'], 'vencimento' => $p['vencimento'],
            'status_id' => StatusPagamento::idFor($p['status']),
        ]);
    }

    return $tx;
}

it('exige login', function () {
    $user = User::factory()->create();
    $tx = txDetalheWeb($user, 5000, [['numero' => 1, 'total' => 1, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::ABERTO]]);

    $this->get(route('lancamentos.show', $tx))->assertRedirect(route('login'));
});

it('mostra o quadro de recorrência (dia + próxima) quando o lançamento é recorrente', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create(['dia' => 10, 'proxima_em' => '2026-07-10', 'status' => Recurrence::STATUS_ATIVO]);
    $tx = txDetalheWeb($user, 5000, [['numero' => 1, 'total' => 1, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::ABERTO]]);
    $tx->update(['recurrence_id' => $rec->id]);

    $this->actingAs($user)->get(route('lancamentos.show', $tx))
        ->assertOk()
        ->assertSee('Lançamento recorrente')
        ->assertSee('no dia 10')
        ->assertSee('10/07/2026');
});

it('não mostra o quadro de recorrência num lançamento comum', function () {
    $user = User::factory()->create();
    $tx = txDetalheWeb($user, 5000, [['numero' => 1, 'total' => 1, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::ABERTO]]);

    $this->actingAs($user)->get(route('lancamentos.show', $tx))
        ->assertOk()
        ->assertDontSee('Lançamento recorrente');
});

it('mostra os dados reais do lançamento formatados em pt-BR', function () {
    $user = User::factory()->create();
    $mercado = Category::factory()->for($user)->create(['nome' => 'Mercado']);
    $cartao = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);

    $tx = txDetalheWeb($user, 45000, [
        ['numero' => 1, 'total' => 3, 'vencimento' => '2026-05-10', 'status' => StatusPagamento::PAGO],
        ['numero' => 2, 'total' => 3, 'vencimento' => '2026-06-15', 'status' => StatusPagamento::ABERTO],
        ['numero' => 3, 'total' => 3, 'vencimento' => '2026-07-10', 'status' => StatusPagamento::AGENDADO],
    ], descricao: 'Mercado Central', categoria: $mercado, cartao: $cartao, forma: PaymentMethod::CREDITO);

    $this->actingAs($user)->get(route('lancamentos.show', $tx))
        ->assertOk()
        ->assertSee('Mercado Central')     // descrição
        ->assertSee('Mercado')             // chip de categoria
        ->assertSee('R$ 450,00')           // valor total
        ->assertSee('Nubank')              // cartão
        ->assertSee('•••• 1234')           // final mascarado
        ->assertSee('Crédito')             // forma
        ->assertSee('1/3')                 // parcela
        ->assertSee('2/3')
        ->assertSee('3/3')
        ->assertSee('R$ 150,00');          // valor por parcela
});

it('mostra os selos de status derivados por parcela', function () {
    $user = User::factory()->create();
    $tx = txDetalheWeb($user, 30000, [
        ['numero' => 1, 'total' => 3, 'vencimento' => '2026-05-10', 'status' => StatusPagamento::PAGO],
        ['numero' => 2, 'total' => 3, 'vencimento' => '2026-06-15', 'status' => StatusPagamento::ABERTO],
        ['numero' => 3, 'total' => 3, 'vencimento' => '2026-07-10', 'status' => StatusPagamento::AGENDADO],
    ]);

    $this->actingAs($user)->get(route('lancamentos.show', $tx))
        ->assertOk()
        ->assertSee('Pago')
        ->assertSee('Em aberto')
        ->assertSee('Agendado');
});

it('bloqueia a edição e explica quando há parcela paga', function () {
    $user = User::factory()->create();
    $tx = txDetalheWeb($user, 30000, [
        ['numero' => 1, 'total' => 2, 'vencimento' => '2026-05-10', 'status' => StatusPagamento::PAGO],
        ['numero' => 2, 'total' => 2, 'vencimento' => '2026-07-10', 'status' => StatusPagamento::AGENDADO],
    ]);

    $this->actingAs($user)->get(route('lancamentos.show', $tx))
        ->assertOk()
        ->assertSee('não é possível editar');
});

it('permite editar quando não há parcela paga (link para a edição)', function () {
    $user = User::factory()->create();
    $tx = txDetalheWeb($user, 30000, [
        ['numero' => 1, 'total' => 1, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::ABERTO],
    ]);

    $this->actingAs($user)->get(route('lancamentos.show', $tx))
        ->assertOk()
        ->assertDontSee('não é possível editar')
        ->assertSee('Editar');
});

it('mostra a ação de marcar pago numa parcela fora de cartão em aberto', function () {
    $user = User::factory()->create();
    $tx = txDetalheWeb($user, 30000, [
        ['numero' => 1, 'total' => 3, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::ABERTO],
    ], forma: PaymentMethod::PIX);

    $html = $this->actingAs($user)->get(route('lancamentos.show', $tx))
        ->assertOk()
        ->assertSee('Marcar pago')
        ->getContent();

    // O form de pagamento aponta para a parcela por TOKEN OPACO (nunca o id real).
    expect(preg_match('#/lancamentos/parcela/([A-Za-z0-9_-]+)/pagar#', $html, $m))->toBe(1)
        ->and(OpaqueId::decode($m[1]))->toBe($tx->installments()->first()->id)
        ->and($html)->not->toContain('/lancamentos/parcela/'.$tx->installments()->first()->id.'/');
});

it('não oferece marcar pago em lançamento de cartão (quita pela fatura)', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create();
    $tx = txDetalheWeb($user, 30000, [
        ['numero' => 1, 'total' => 1, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::ABERTO],
    ], cartao: $cartao, forma: PaymentMethod::CREDITO);

    $this->actingAs($user)->get(route('lancamentos.show', $tx))
        ->assertOk()
        ->assertDontSee('Marcar pago');
});

it('não oferece marcar pago numa parcela já paga', function () {
    $user = User::factory()->create();
    $tx = txDetalheWeb($user, 30000, [
        ['numero' => 1, 'total' => 1, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::PAGO],
    ], forma: PaymentMethod::PIX);

    $this->actingAs($user)->get(route('lancamentos.show', $tx))
        ->assertOk()
        ->assertDontSee('Marcar pago');
});

it('devolve 404 para transação de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $txAlheia = txDetalheWeb($outro, 99900, [['numero' => 1, 'total' => 1, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::ABERTO]]);

    $this->actingAs($user)->get(route('lancamentos.show', $txAlheia))->assertNotFound();
});

it('recusa (404) o id REAL no path do detalhe — só token criptografado abre a tela', function () {
    $user = User::factory()->create();
    $tx = txDetalheWeb($user, 5000, [['numero' => 1, 'total' => 1, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::ABERTO]]);

    // O token opaco abre; o id em claro (URL enumerável antiga) é 404.
    $this->actingAs($user)->get(route('lancamentos.show', $tx))->assertOk();
    $this->actingAs($user)->get("/lancamentos/{$tx->id}")->assertNotFound();
});

it('a lista linka cada linha por token OPACO (nunca o id real) e o editar com ?editar=1', function () {
    $user = User::factory()->create();
    $tx = txDetalheWeb($user, 5000, [['numero' => 1, 'total' => 1, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::ABERTO]]);

    $html = $this->actingAs($user)->get('/lancamentos')->assertOk()->getContent();

    // O id real NÃO aparece no path, em nenhuma forma.
    expect($html)->not->toContain('/lancamentos/'.$tx->id.'"')
        ->and($html)->not->toContain('/lancamentos/'.$tx->id.'?')
        ->and($html)->not->toContain('/lancamentos/'.$tx->id.'/');

    // Há um link (token opaco) para o detalhe com ?editar=1, e ele decodifica para ESTE tx.
    expect(preg_match('#/lancamentos/([A-Za-z0-9_-]+)\?editar=1#', $html, $m))->toBe(1)
        ->and(OpaqueId::decode($m[1]))->toBe($tx->id);

    // E o token realmente resolve a tela de detalhe.
    $this->actingAs($user)->get('/lancamentos/'.$m[1])->assertOk()->assertSee('Mercado do mês');
});

it('oferece cancelar (esta e as próximas) com prévia quando há parcela em aberto', function () {
    $user = User::factory()->create();
    $tx = txDetalheWeb($user, 30000, [
        ['numero' => 1, 'total' => 3, 'vencimento' => '2026-05-20', 'status' => StatusPagamento::ABERTO],
        ['numero' => 2, 'total' => 3, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::ABERTO],
        ['numero' => 3, 'total' => 3, 'vencimento' => '2026-07-20', 'status' => StatusPagamento::ABERTO],
    ]);

    $this->actingAs($user)->get(route('lancamentos.show', $tx))
        ->assertOk()
        ->assertSee('Cancelar restantes')
        ->assertSee('Confirmar cancelamento') // o form (prévia + POST) só aparece quando há o que cancelar
        ->assertSee('/cancelar');
});

it('ainda oferece cancelar as restantes mesmo com o lançamento bloqueado por parcela paga', function () {
    $user = User::factory()->create();
    $tx = txDetalheWeb($user, 30000, [
        ['numero' => 1, 'total' => 3, 'vencimento' => '2026-05-20', 'status' => StatusPagamento::PAGO],
        ['numero' => 2, 'total' => 3, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::ABERTO],
        ['numero' => 3, 'total' => 3, 'vencimento' => '2026-07-20', 'status' => StatusPagamento::ABERTO],
    ]);

    $this->actingAs($user)->get(route('lancamentos.show', $tx))
        ->assertOk()
        ->assertSee('não é possível editar; você ainda pode cancelar as restantes')
        ->assertSee('Confirmar cancelamento');
});

it('não oferece a confirmação de cancelar quando nada resta cancelável', function () {
    $user = User::factory()->create();
    $tx = txDetalheWeb($user, 30000, [
        ['numero' => 1, 'total' => 2, 'vencimento' => '2026-05-20', 'status' => StatusPagamento::PAGO],
        ['numero' => 2, 'total' => 2, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::CANCELADO],
    ]);

    $this->actingAs($user)->get(route('lancamentos.show', $tx))
        ->assertOk()
        ->assertSee('Cancelar restantes')      // rótulo permanece (botão inerte)
        ->assertDontSee('Confirmar cancelamento'); // mas sem form/POST
});
