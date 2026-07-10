<?php

declare(strict_types=1);

use App\Models\AuditLog;
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
    ?Recurrence $recorrencia = null,
): Transaction {
    $tx = Transaction::factory()->for($user)->create([
        'valor_total_cents' => $cents,
        'descricao' => $descricao,
        'payment_method_id' => PaymentMethod::idFor($forma),
        'card_id' => $card?->id,
        'categoria_id' => $cat?->id,
        'data_compra' => $venc,
        // Vínculo com a recorrência de origem (a VERDADE de "é recorrente") + procedência.
        'recurrence_id' => $recorrencia?->id,
        'origem' => $recorrencia ? 'recorrencia' : 'manual',
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

it('a edição de um lançamento recorrente mostra o quadro com o dia e a próxima data', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create(['dia' => 10, 'proxima_em' => '2026-08-10', 'status' => Recurrence::STATUS_ATIVO]);
    $tx = criarLancamento($user, forma: PaymentMethod::PIX, venc: '2026-07-10', recorrencia: $rec);

    $this->actingAs($user)->get(route('lancamentos.edit', $tx))
        ->assertOk()
        ->assertSee('Lançamento recorrente')            // quadro de recorrência
        ->assertSee('no dia 10')                        // dia da regra
        ->assertSee('10/08/2026')                       // próxima ocorrência (vem do backend)
        ->assertSee('name="escopo_recorrencia"', false) // escolha de alcance no confirmar
        ->assertSee('Este e os próximos', false)
        ->assertDontSee('Repete todo mês?');            // sem o switch (virou quadro)
});

it('a edição de um lançamento comum mostra o switch e não o quadro de recorrência', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, forma: PaymentMethod::PIX, venc: '2026-07-10');

    $this->actingAs($user)->get(route('lancamentos.edit', $tx))
        ->assertOk()
        ->assertSee('Repete todo mês?')                     // switch disponível
        ->assertDontSee('Lançamento recorrente')            // sem quadro de recorrência
        ->assertDontSee('name="escopo_recorrencia"', false);
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

/* ------------------------------------- edição + recorrência (spec 10) ------- */

it('editar com o switch de recorrência ligado cria a recorrência a partir do mês seguinte', function () {
    $user = User::factory()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-09 08:00', 'America/Sao_Paulo'));
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

    // A edição continua funcionando (parcelas regeneradas pelo domínio).
    expect($tx->fresh()->valor_total_cents)->toBe(45000);

    // E nasceu uma recorrência ativa, começando no mês seguinte (agosto) — como no cadastro.
    $rec = Recurrence::sole();
    expect($rec->user_id)->toBe($user->id)
        ->and($rec->status)->toBe(Recurrence::STATUS_ATIVO)
        ->and($rec->valor_cents)->toBe(45000)
        ->and($rec->payment_method_id)->toBe(PaymentMethod::idFor(PaymentMethod::PIX))
        ->and($rec->dia)->toBe(10)
        ->and($rec->proxima_em->toDateString())->toBe('2026-08-10');

    // O lançamento passa a ser recorrente: fica vinculado à recorrência recém-criada.
    expect($tx->fresh()->recurrence_id)->toBe($rec->id)
        ->and($tx->fresh()->ehRecorrente())->toBeTrue();
});

it('editar sem o switch não cria recorrência', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, forma: PaymentMethod::PIX, venc: '2026-07-10');

    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
        'descricao' => 'Aluguel', 'valor' => '450,00', 'forma' => 'pix', 'vencimento' => '2026-07-10',
    ])->assertOk();

    expect(Recurrence::count())->toBe(0);
});

it('editar no crédito ignora recorrência (crédito usa parcelas)', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    $tx = criarLancamento($user, forma: PaymentMethod::CREDITO, venc: '2026-07-10', card: $card);

    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
        'descricao' => 'Assinatura', 'valor' => '30,00', 'forma' => 'credito', 'card_id' => $card->id,
        'recorrente' => true, 'periodicidade' => 'mensal', 'dia_recorrencia' => 10,
    ])->assertOk();

    expect(Recurrence::count())->toBe(0);
});

it('edição bloqueada por parcela paga não cria recorrência (atômico)', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, forma: PaymentMethod::PIX, venc: '2026-07-10', statusCodigo: StatusPagamento::PAGO);

    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
        'descricao' => 'Tentativa', 'valor' => '10,00', 'forma' => 'pix', 'vencimento' => '2026-07-15',
        'recorrente' => true, 'periodicidade' => 'mensal', 'dia_recorrencia' => 15,
    ])->assertStatus(422);

    expect(Recurrence::count())->toBe(0)
        ->and($tx->fresh()->descricao)->toBe('Aluguel'); // edição não vazou
});

it('editar um lançamento que já é recorrente ignora o switch — não cria outra recorrência', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create(['valor_cents' => 4500, 'dia' => 10, 'proxima_em' => '2026-08-10']);
    $tx = criarLancamento($user, cents: 45000, descricao: 'Netflix', forma: PaymentMethod::PIX, venc: '2026-07-10', recorrencia: $rec);

    // Switch ligado + campos completos, mas SEM escolher escopo → padrão "só este mês": não cria
    // outra recorrência (não se recorre de novo o que já é recorrente) nem altera a de origem.
    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
        'descricao' => 'Netflix', 'valor' => '55,00', 'forma' => 'pix', 'vencimento' => '2026-07-10',
        'recorrente' => true, 'periodicidade' => 'mensal', 'dia_recorrencia' => 10,
    ])->assertOk()->assertJsonPath('ok', true);

    expect(Recurrence::count())->toBe(1)                     // só a de origem (nenhuma nova)
        ->and($tx->fresh()->valor_total_cents)->toBe(5500)   // edição do mês aplicada
        ->and($tx->fresh()->recurrence_id)->toBe($rec->id)   // segue vinculado
        ->and($rec->fresh()->valor_cents)->toBe(4500);       // recorrência intacta
});

it('editar um lançamento recorrente com o switch desmarcado apenas ignora e mantém o vínculo', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create(['valor_cents' => 4500]);
    $tx = criarLancamento($user, forma: PaymentMethod::PIX, venc: '2026-07-10', recorrencia: $rec);

    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
        'descricao' => 'Aluguel', 'valor' => '450,00', 'forma' => 'pix', 'vencimento' => '2026-07-10',
        'recorrente' => false,
    ])->assertOk();

    expect(Recurrence::count())->toBe(1)
        ->and($tx->fresh()->recurrence_id)->toBe($rec->id)
        ->and($rec->fresh()->valor_cents)->toBe(4500);
});

it('editar recorrente com escopo "este e os próximos" sincroniza a recorrência de origem', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create([
        'descricao' => 'Netflix', 'valor_cents' => 4500,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'dia' => 10, 'proxima_em' => '2026-08-10', 'status' => Recurrence::STATUS_ATIVO,
    ]);
    $tx = criarLancamento($user, cents: 45000, descricao: 'Netflix', forma: PaymentMethod::PIX, venc: '2026-07-10', recorrencia: $rec);

    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
        'descricao' => 'Netflix 4K', 'valor' => '55,00', 'forma' => 'pix', 'vencimento' => '2026-07-15',
        'escopo_recorrencia' => 'este_e_proximos',
    ])->assertOk();

    // O mês mudou E a regra passou a valer para os próximos (novo valor + novo dia).
    expect($tx->fresh()->valor_total_cents)->toBe(5500);
    $rec->refresh();
    expect($rec->descricao)->toBe('Netflix 4K')
        ->and($rec->valor_cents)->toBe(5500)
        ->and($rec->dia)->toBe(15)
        ->and($rec->proxima_em->format('Y-m-d'))->toBe('2026-08-15');
});

it('editar recorrente com escopo "só este mês" não altera a recorrência', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create(['descricao' => 'Netflix', 'valor_cents' => 4500, 'dia' => 10, 'proxima_em' => '2026-08-10']);
    $tx = criarLancamento($user, cents: 45000, descricao: 'Netflix', forma: PaymentMethod::PIX, venc: '2026-07-10', recorrencia: $rec);

    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
        'descricao' => 'Netflix caro', 'valor' => '55,00', 'forma' => 'pix', 'vencimento' => '2026-07-10',
        'escopo_recorrencia' => 'este',
    ])->assertOk();

    expect($tx->fresh()->valor_total_cents)->toBe(5500)  // mês mudou
        ->and($rec->fresh()->valor_cents)->toBe(4500)     // recorrência intacta
        ->and($rec->fresh()->descricao)->toBe('Netflix');
});

it('rejeita um escopo_recorrencia inválido', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create();
    $tx = criarLancamento($user, forma: PaymentMethod::PIX, venc: '2026-07-10', recorrencia: $rec);

    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
        'descricao' => 'X', 'valor' => '10,00', 'forma' => 'pix', 'vencimento' => '2026-07-10',
        'escopo_recorrencia' => 'tudo',
    ])->assertStatus(422)->assertJsonValidationErrors(['escopo_recorrencia']);
});

it('recusa a edição que combina parcelamento com recorrência (parcelas ≥ 2 + switch)', function () {
    $user = User::factory()->create();
    $tx = criarLancamento($user, forma: PaymentMethod::PIX, venc: '2026-07-10');

    $this->actingAs($user)->putJson(route('lancamentos.update', $tx), [
        'descricao' => 'Aluguel', 'valor' => '450,00', 'forma' => 'pix', 'vencimento' => '2026-07-10',
        'parcelas' => 2, 'recorrente' => true, 'periodicidade' => 'mensal', 'dia_recorrencia' => 10,
    ])->assertStatus(422)->assertJsonValidationErrors(['recorrente']);

    expect(Recurrence::count())->toBe(0)
        ->and($tx->fresh()->descricao)->toBe('Aluguel'); // edição não vazou
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
