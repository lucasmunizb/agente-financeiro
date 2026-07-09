<?php

declare(strict_types=1);

use App\Models\AuditLog;
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
 * Página de criar/editar lançamento (FE §7.7) — mesma tela do modal rápido (§7.7b) como
 * página cheia. A regra de negócio já vive (e é testada) em RegistrarGastoManual /
 * EditarGastoManual; aqui garantimos a fiação da página: render (criar × editar prefill),
 * a PRÉVIA que não persiste (regra 7), a edição que regenera as parcelas, o bloqueio com
 * parcela paga e o isolamento por usuário.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function criarLancamento(
    User $user,
    int $cents = 45000,
    string $descricao = 'Aluguel',
    string $forma = PaymentMethod::PIX,
    string $venc = '2026-07-10',
    string $statusCodigo = StatusPagamento::ABERTO,
    ?Card $card = null,
    ?Category $cat = null,
): Transaction {
    $tx = Transaction::factory()->for($user)->create([
        'valor_total_cents' => $cents,
        'descricao' => $descricao,
        'payment_method_id' => PaymentMethod::idFor($forma),
        'card_id' => $card?->id,
        'categoria_id' => $cat?->id,
        'data_compra' => $venc,
    ]);
    Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $venc,
        'status_id' => StatusPagamento::idFor($statusCodigo),
    ]);

    return $tx;
}

/* ----------------------------------------------------------------- auth ----- */

it('exige login na página de criar e de editar', function () {
    $this->get('/lancamentos/novo')->assertRedirect(route('login'));

    $user = User::factory()->create();
    $tx = criarLancamento($user);
    $this->get("/lancamentos/{$tx->id}/editar")->assertRedirect(route('login'));
});

/* --------------------------------------------------------------- criar ------ */

it('renderiza a página de novo lançamento com o formulário', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/lancamentos/novo')
        ->assertOk()
        ->assertSee('Novo lançamento')
        ->assertSee('Descrição')
        ->assertSee('Revisar e confirmar');
});

/* --------------------------------------------------------------- editar ----- */

it('renderiza a edição já preenchida com os dados do lançamento', function () {
    $user = User::factory()->create();
    $cat = Category::factory()->for($user)->create(['nome' => 'Moradia']);
    $tx = criarLancamento($user, cents: 45000, descricao: 'Aluguel de julho', venc: '2026-07-10', cat: $cat);

    $this->actingAs($user)->get("/lancamentos/{$tx->id}/editar")
        ->assertOk()
        ->assertSee('Editar lançamento')
        ->assertSee('Aluguel de julho', false)   // value do input
        ->assertSee('450,00')                     // valor em pt-BR (sem R$)
        ->assertSee('2026-07-10');                // vencimento no input date
});

it('devolve 404 ao editar lançamento de outro usuário (escopo)', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $tx = criarLancamento($outro);

    $this->actingAs($user)->get("/lancamentos/{$tx->id}/editar")->assertNotFound();
});

it('mostra aviso de bloqueio quando há parcela paga', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, statusCodigo: StatusPagamento::PAGO);

    $this->actingAs($user)->get("/lancamentos/{$tx->id}/editar")
        ->assertOk()
        ->assertSee('Há parcelas pagas');
});

/* ------------------------------------------------- prévia da edição --------- */

it('a prévia da edição recalcula sem persistir', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, cents: 45000, descricao: 'Aluguel', forma: PaymentMethod::PIX, venc: '2026-07-10');

    $this->actingAs($user)->postJson("/lancamentos/{$tx->id}/previa", [
        'descricao' => 'Aluguel reajustado',
        'valor' => '500,00',
        'forma' => 'pix',
        'vencimento' => '2026-07-15',
    ])->assertOk()
        ->assertJsonPath('valorTotal', 'R$ 500,00')
        ->assertJsonCount(1, 'parcelas');

    // Nada mudou no banco (regra 7).
    expect($tx->fresh()->descricao)->toBe('Aluguel')
        ->and($tx->fresh()->valor_total_cents)->toBe(45000);
});

/* ------------------------------------------------- gravar a edição ---------- */

it('atualiza o lançamento, regenera as parcelas e audita', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, cents: 45000, descricao: 'Aluguel', forma: PaymentMethod::PIX, venc: '2026-07-10');

    $this->actingAs($user)->putJson("/lancamentos/{$tx->id}", [
        'descricao' => 'Aluguel reajustado',
        'valor' => '500,00',
        'forma' => 'pix',
        'vencimento' => '2026-07-15',
    ])->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('redirect', route('lancamentos'));

    $tx->refresh()->load('installments');
    expect($tx->descricao)->toBe('Aluguel reajustado')
        ->and($tx->valor_total_cents)->toBe(50000)
        ->and($tx->installments)->toHaveCount(1)
        ->and($tx->installments->first()->vencimento->toDateString())->toBe('2026-07-15');

    expect(AuditLog::where('entidade', 'transaction')->where('entidade_id', $tx->id)
        ->where('acao', AuditLog::ACAO_EDITAR)->count())->toBe(1);
});

it('devolve 404 ao atualizar lançamento de outro usuário', function () {
    $user = User::factory()->create();
    $tx = criarLancamento(User::factory()->create());

    $this->actingAs($user)->putJson("/lancamentos/{$tx->id}", [
        'descricao' => 'Invadido', 'valor' => '1,00', 'forma' => 'pix', 'vencimento' => '2026-07-15',
    ])->assertNotFound();
});

it('bloqueia a edição quando há parcela paga (422)', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, statusCodigo: StatusPagamento::PAGO);

    $this->actingAs($user)->putJson("/lancamentos/{$tx->id}", [
        'descricao' => 'Tentativa', 'valor' => '10,00', 'forma' => 'pix', 'vencimento' => '2026-07-15',
    ])->assertStatus(422)
        ->assertJsonPath('errors.geral.0', 'Não é possível editar: há parcela já paga.');

    expect($tx->fresh()->descricao)->toBe('Aluguel'); // inalterado
});
