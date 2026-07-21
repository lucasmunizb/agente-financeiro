<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Card;
use App\Models\Category;
use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
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
        'origem' => 'manual',
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
    $this->get(route('lancamentos.edit', $tx))->assertRedirect(route('login'));
});

/* ------------------------------------------- id em claro é rejeitado -------- */

it('recusa (404) o id REAL no path — só token criptografado é aceito', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user);

    // O que a URL antiga fazia (id sequencial em claro) agora é 404 em toda a família.
    $this->actingAs($user)->get("/lancamentos/{$tx->id}/editar")->assertNotFound();
    $this->actingAs($user)->postJson("/lancamentos/{$tx->id}/previa", [])->assertNotFound();
    $this->actingAs($user)->putJson("/lancamentos/{$tx->id}", [])->assertNotFound();
    $this->actingAs($user)->get("/lancamentos/{$tx->id}")->assertNotFound();
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

    $this->actingAs($user)->get(route('lancamentos.edit', $tx))
        ->assertOk()
        ->assertSee('Editar lançamento')
        ->assertSee('Aluguel de julho', false)   // value do input
        ->assertSee('450,00')                     // valor em pt-BR (sem R$)
        ->assertSee('2026-07-10');                // vencimento no input date
});

it('a edição sempre mostra o switch: todo lançamento editável é comum (spec 12)', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, forma: PaymentMethod::PIX, venc: '2026-07-10');

    $this->actingAs($user)->get(route('lancamentos.edit', $tx))
        ->assertOk()
        ->assertSee('Repete todo mês?')                     // ligar o switch CONVERTE (D5)
        ->assertDontSee('Lançamento recorrente');           // recorrência não vive em transactions
});

it('devolve 404 ao editar lançamento de outro usuário (escopo)', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $tx = criarLancamento($outro);

    $this->actingAs($user)->get(route('lancamentos.edit', $tx))->assertNotFound();
});

it('mostra aviso de bloqueio quando há parcela paga', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, statusCodigo: StatusPagamento::PAGO);

    $this->actingAs($user)->get(route('lancamentos.edit', $tx))
        ->assertOk()
        ->assertSee('Há parcelas pagas');
});

/* ------------------------------------------------- prévia da edição --------- */

it('a prévia da edição recalcula sem persistir', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, cents: 45000, descricao: 'Aluguel', forma: PaymentMethod::PIX, venc: '2026-07-10');

    $this->actingAs($user)->postJson(route('lancamentos.previa', $tx), [
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

    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
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

/* ------------------------------------- edição + recorrência (spec 12) ------- */

it('ligar o switch CONVERTE o lançamento em recorrência + ocorrência do mês (D5)', function () {
    $user = User::factory()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-21 08:00', 'America/Sao_Paulo'));
    $tx = criarLancamento($user, cents: 45000, descricao: 'Aluguel', forma: PaymentMethod::PIX, venc: '2026-07-10');

    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
        'descricao' => 'Aluguel',
        'valor' => '450,00',
        'forma' => 'pix',
        'vencimento' => '2026-07-10',
        'recorrente' => true,
        'periodicidade' => 'mensal',
        'dia_recorrencia' => 10,
    ])->assertOk()->assertJsonPath('ok', true);

    // O lançamento foi SUBSTITUÍDO: nada de coexistir com a recorrência (era a dupla contagem).
    expect(Transaction::count())->toBe(0)
        ->and(Installment::count())->toBe(0);

    $rec = Recurrence::sole();
    expect($rec->user_id)->toBe($user->id)
        ->and($rec->status)->toBe(Recurrence::STATUS_ATIVO)
        ->and($rec->valor_cents)->toBe(45000)
        ->and($rec->dia)->toBe(10)
        ->and($rec->proxima_em->toDateString())->toBe('2026-08-01');

    // Uma única ocorrência, na competência do mês corrente (D2).
    expect(RecurrenceOccurrence::sole())
        ->competencia->toBe('2026-07')
        ->vencimento->toDateString()->toBe('2026-07-10');

    // O rastro da conversão fica na auditoria (a linha física some — LGPD).
    expect(AuditLog::where('entidade', 'transaction')->where('entidade_id', $tx->id)
        ->where('acao', AuditLog::ACAO_EXCLUIR)->count())->toBe(1);
});

it('editar sem o switch não cria recorrência', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, forma: PaymentMethod::PIX, venc: '2026-07-10');

    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
        'descricao' => 'Aluguel', 'valor' => '450,00', 'forma' => 'pix', 'vencimento' => '2026-07-10',
    ])->assertOk();

    expect(Recurrence::count())->toBe(0)
        ->and(Transaction::count())->toBe(1);
});

it('converte também no crédito, ligando a recorrência ao cartão (D3)', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['dia_fechamento' => 20, 'dia_vencimento' => 28]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-21 08:00', 'America/Sao_Paulo'));
    $tx = criarLancamento($user, forma: PaymentMethod::CREDITO, venc: '2026-07-10', card: $card);

    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
        'descricao' => 'Assinatura', 'valor' => '30,00', 'forma' => 'credito', 'card_id' => $card->id,
        'recorrente' => true, 'periodicidade' => 'mensal', 'dia_recorrencia' => 10,
    ])->assertOk();

    expect(Transaction::count())->toBe(0)
        ->and(Recurrence::sole()->card_id)->toBe($card->id)
        ->and(RecurrenceOccurrence::sole()->card_id)->toBe($card->id);
});

it('a conversão não acontece quando a validação recusa (parcelado + recorrente)', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, forma: PaymentMethod::PIX, venc: '2026-07-10');

    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
        'descricao' => 'Aluguel', 'valor' => '450,00', 'forma' => 'pix', 'vencimento' => '2026-07-10',
        'parcelas' => 2, 'recorrente' => true, 'periodicidade' => 'mensal', 'dia_recorrencia' => 10,
    ])->assertStatus(422)->assertJsonValidationErrors(['recorrente']);

    expect(Recurrence::count())->toBe(0)
        ->and(Transaction::count())->toBe(1)
        ->and($tx->fresh()->descricao)->toBe('Aluguel');
});

it('rejeita um escopo_recorrencia inválido', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, forma: PaymentMethod::PIX, venc: '2026-07-10');

    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
        'descricao' => 'X', 'valor' => '10,00', 'forma' => 'pix', 'vencimento' => '2026-07-10',
        'escopo_recorrencia' => 'tudo',
    ])->assertStatus(422)->assertJsonValidationErrors(['escopo_recorrencia']);
});

it('devolve 404 ao atualizar lançamento de outro usuário', function () {
    $user = User::factory()->create();
    $tx = criarLancamento(User::factory()->create());

    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
        'descricao' => 'Invadido', 'valor' => '1,00', 'forma' => 'pix', 'vencimento' => '2026-07-15',
    ])->assertNotFound();
});

it('bloqueia a edição quando há parcela paga (422)', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, statusCodigo: StatusPagamento::PAGO);

    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
        'descricao' => 'Tentativa', 'valor' => '10,00', 'forma' => 'pix', 'vencimento' => '2026-07-15',
    ])->assertStatus(422)
        ->assertJsonPath('errors.geral.0', 'Não é possível editar: há parcela já paga.');

    expect($tx->fresh()->descricao)->toBe('Aluguel'); // inalterado
});

/* --------------------------------- F7: switch de recorrência na tela (spec 12) --- */

it('mostra o switch de recorrência também no crédito (D3)', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    $tx = criarLancamento($user, forma: PaymentMethod::CREDITO, venc: '2026-07-10', card: $card);

    $this->actingAs($user)->get(route('lancamentos.edit', $tx))
        ->assertOk()
        ->assertSee('Repete todo mês?')
        ->assertSee('cartão também')
        // O switch não vive mais dentro do bloco "só à vista": ele é irmão dele.
        ->assertSee('Dia da cobrança');
});

it('avisa que ligar o switch SUBSTITUI o lançamento pela recorrência (D5)', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, forma: PaymentMethod::PIX, venc: '2026-07-10');

    $this->actingAs($user)->get(route('lancamentos.edit', $tx))
        ->assertOk()
        ->assertSee('deixa de existir como gasto avulso');
});

it('não oferece mais a escolha de escopo da edição recorrente', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, forma: PaymentMethod::PIX, venc: '2026-07-10');

    $this->actingAs($user)->get(route('lancamentos.edit', $tx))
        ->assertOk()
        ->assertDontSee('name="escopo_recorrencia"', false)
        ->assertDontSee('Este e os próximos');
});

it('limita o dia da cobrança à faixa 1..31 já na borda do campo', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, forma: PaymentMethod::PIX, venc: '2026-07-10');

    $this->actingAs($user)->get(route('lancamentos.edit', $tx))
        ->assertOk()
        ->assertSee('data-dia-do-mes', false);
});
