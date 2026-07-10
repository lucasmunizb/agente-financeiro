<?php

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
 * Borda web do cadastro de gasto manual (modal §7.7b). A regra de negócio já vive
 * (e é testada) em App\Domain\Gasto\RegistrarGastoManual; aqui garantimos a fiação:
 * validação na borda (Form Request), tradução form→DTO, a PRÉVIA que não persiste
 * (regra 7) com valores calculados pelo backend (regra 4), a gravação atômica
 * (transaction + installments + auditoria) e o isolamento por usuário.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/* ------------------------------------------------------------------ auth --- */

it('exige login para a prévia e para gravar', function () {
    $this->postJson('/gastos/previa', [])->assertUnauthorized();
    $this->postJson('/gastos', [])->assertUnauthorized();
});

/* --------------------------------------------------------------- prévia ---- */

it('a prévia calcula as parcelas e NÃO persiste nada', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['dia_fechamento' => 28, 'dia_vencimento' => 5]);
    $cat = Category::factory()->for($user)->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo'));

    $resp = $this->actingAs($user)->postJson('/gastos/previa', [
        'descricao' => 'TV nova',
        'valor' => '450,00',
        'forma' => 'credito',
        'card_id' => $card->id,
        'parcelas' => 3,
        'categoria_id' => $cat->id,
    ]);

    $resp->assertOk()
        ->assertJsonPath('valorTotal', 'R$ 450,00')
        ->assertJsonPath('ehDuplicado', false)
        ->assertJsonCount(3, 'parcelas')
        ->assertJsonPath('parcelas.0.valor', 'R$ 150,00')
        ->assertJsonPath('parcelas.0.label', '1/3');

    expect(Transaction::count())->toBe(0)
        ->and(Installment::count())->toBe(0);
});

/* -------------------------------------------------------------- gravação ---- */

it('grava um gasto à vista (pix) com centavos, status e auditoria', function () {
    $user = User::factory()->create();
    $cat = Category::factory()->for($user)->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10', 'America/Sao_Paulo'));

    $resp = $this->actingAs($user)->postJson('/gastos', [
        'descricao' => 'Mercado do mês',
        'valor' => '1.234,56',
        'forma' => 'pix',
        'vencimento' => '2026-07-05',
        'categoria_id' => $cat->id,
    ]);

    $resp->assertOk()->assertJsonPath('ok', true);

    $tx = Transaction::with('installments')->first();
    expect($tx->user_id)->toBe($user->id)
        ->and($tx->descricao)->toBe('Mercado do mês')
        ->and($tx->valor_total_cents)->toBe(123456)
        ->and($tx->payment_method_id)->toBe(PaymentMethod::idFor(PaymentMethod::PIX))
        ->and($tx->card_id)->toBeNull()
        ->and($tx->categoria_id)->toBe($cat->id)
        ->and($tx->origem)->toBe('manual')
        ->and($tx->data_compra->toDateString())->toBe('2026-07-05')
        ->and($tx->installments)->toHaveCount(1);

    // Vencimento 05/07 < hoje 10/07 ⇒ parcela vencida (status derivado pela data).
    expect($tx->installments->first()->vencimento->toDateString())->toBe('2026-07-05')
        ->and($tx->installments->first()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::VENCIDO));

    expect(AuditLog::where('entidade', 'transaction')->where('entidade_id', $tx->id)->count())->toBe(1);
});

it('grava um gasto no crédito parcelado usando o vencimento do cartão', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['dia_fechamento' => 28, 'dia_vencimento' => 5]);
    $cat = Category::factory()->for($user)->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo'));

    $resp = $this->actingAs($user)->postJson('/gastos', [
        'descricao' => 'Geladeira',
        'valor' => '1.200,00',
        'forma' => 'credito',
        'card_id' => $card->id,
        'parcelas' => 3,
        'categoria_id' => $cat->id,
    ]);

    $resp->assertOk();

    $tx = Transaction::with('installments')->first();
    expect($tx->valor_total_cents)->toBe(120000)
        ->and($tx->card_id)->toBe($card->id)
        ->and($tx->installments)->toHaveCount(3);

    // Soma das parcelas derivadas = total (nenhum centavo perdido).
    $soma = $tx->installments->sum(fn ($p) => $p->valor()->cents());
    expect($soma)->toBe(120000);

    // 1ª parcela vence pelo ciclo do cartão: compra 10/06, fecha 28 ⇒ fatura de
    // junho, vence dia 5 do mês seguinte (05/07); depois +1 mês por parcela.
    expect($tx->installments->pluck('vencimento')->map->toDateString()->all())
        ->toBe(['2026-07-05', '2026-08-05', '2026-09-05']);
});

it('grava um gasto FORA DE CARTÃO parcelado (pix em 3x), com vencimentos mensais', function () {
    $user = User::factory()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01', 'America/Sao_Paulo'));

    // Combinado de pagar alguém em 3x via pix (não recorrente): 1 transação, 3 parcelas.
    $resp = $this->actingAs($user)->postJson('/gastos', [
        'descricao' => 'Combinado com o João',
        'valor' => '300,00',
        'forma' => 'pix',
        'vencimento' => '2026-07-05',
        'parcelas' => 3,
    ]);

    $resp->assertOk()->assertJsonPath('ok', true);

    $tx = Transaction::with('installments')->first();
    expect($tx->card_id)->toBeNull()
        ->and($tx->valor_total_cents)->toBe(30000)
        ->and($tx->installments)->toHaveCount(3);

    // Soma das parcelas derivadas = total (sem perder centavo).
    expect($tx->installments->sum(fn ($p) => $p->valor()->cents()))->toBe(30000);

    // Fora de cartão: 1ª vence na data informada; +1 mês por parcela.
    expect($tx->installments->sortBy('numero')->pluck('vencimento')->map->toDateString()->all())
        ->toBe(['2026-07-05', '2026-08-05', '2026-09-05']);
});

it('a prévia calcula as parcelas fora de cartão (pix em 3x) sem persistir', function () {
    $user = User::factory()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01', 'America/Sao_Paulo'));

    $this->actingAs($user)->postJson('/gastos/previa', [
        'descricao' => 'Combinado', 'valor' => '300,00', 'forma' => 'pix',
        'vencimento' => '2026-07-05', 'parcelas' => 3,
    ])->assertOk()
        ->assertJsonCount(3, 'parcelas')
        ->assertJsonPath('parcelas.0.valor', 'R$ 100,00')
        ->assertJsonPath('parcelas.0.label', '1/3');

    expect(Transaction::count())->toBe(0);
});

/* ------------------------------------------------------- recorrência (§7.7) --- */

it('gasto recorrente à vista: lança AGORA e cria a recorrência a partir do mês seguinte', function () {
    $user = User::factory()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-09 08:00', 'America/Sao_Paulo'));

    $resp = $this->actingAs($user)->postJson('/gastos', [
        'descricao' => 'Netflix',
        'valor' => '55,90',
        'forma' => 'pix',
        'vencimento' => '2026-07-05',
        'recorrente' => true,
        'periodicidade' => 'mensal',
        'dia_recorrencia' => 5,
    ]);

    $resp->assertOk()->assertJsonPath('ok', true);

    // 1) O gasto deste mês foi lançado normalmente (não vem da fila).
    $tx = Transaction::first();
    expect($tx->descricao)->toBe('Netflix')
        ->and($tx->valor_total_cents)->toBe(5590)
        ->and($tx->origem)->toBe('manual');

    // 2) A recorrência nasceu ativa, começando no mês seguinte (agosto), sem duplicar julho.
    $rec = Recurrence::sole();

    // 3) O gasto deste mês fica VINCULADO à recorrência — já aparece como recorrente na tela.
    expect($tx->fresh()->recurrence_id)->toBe($rec->id)
        ->and($tx->fresh()->ehRecorrente())->toBeTrue();
    expect($rec->user_id)->toBe($user->id)
        ->and($rec->status)->toBe(Recurrence::STATUS_ATIVO)
        ->and($rec->valor_cents)->toBe(5590)
        ->and($rec->payment_method_id)->toBe(PaymentMethod::idFor(PaymentMethod::PIX))
        ->and($rec->dia)->toBe(5)
        ->and($rec->proxima_em->toDateString())->toBe('2026-08-05');
});

it('não cria recorrência quando o switch está desligado', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/gastos', [
        'descricao' => 'Mercado', 'valor' => '10,00', 'forma' => 'pix', 'vencimento' => '2026-07-05',
    ])->assertOk();

    expect(Recurrence::count())->toBe(0)
        ->and(Transaction::count())->toBe(1);
});

it('ignora recorrência no crédito (crédito usa parcelas, não recorrência)', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)->postJson('/gastos', [
        'descricao' => 'Assinatura', 'valor' => '30,00', 'forma' => 'credito', 'card_id' => $card->id,
        'recorrente' => true, 'periodicidade' => 'mensal', 'dia_recorrencia' => 5,
    ])->assertOk();

    expect(Recurrence::count())->toBe(0)
        ->and(Transaction::count())->toBe(1);
});

it('recorrente exige o dia da recorrência', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/gastos', [
        'descricao' => 'Netflix', 'valor' => '55,90', 'forma' => 'pix', 'vencimento' => '2026-07-05',
        'recorrente' => true, 'periodicidade' => 'mensal',
    ])->assertStatus(422)->assertJsonValidationErrors(['dia_recorrencia']);

    expect(Transaction::count())->toBe(0);
});

it('recusa parcelamento combinado com recorrência (parcelas ≥ 2 + repete todo mês)', function () {
    $user = User::factory()->create();

    // Parcelar (dividir um gasto em N) e repetir todo mês são coisas diferentes: combinar
    // as duas não faz sentido de negócio (spec §7.7). A borda barra e nada é gravado.
    $this->actingAs($user)->postJson('/gastos', [
        'descricao' => 'Sofá', 'valor' => '1.200,00', 'forma' => 'pix', 'vencimento' => '2026-07-05',
        'parcelas' => 3,
        'recorrente' => true, 'periodicidade' => 'mensal', 'dia_recorrencia' => 5,
    ])->assertStatus(422)->assertJsonValidationErrors(['recorrente']);

    expect(Transaction::count())->toBe(0)
        ->and(Recurrence::count())->toBe(0);
});

it('a prévia também recusa parcelamento com recorrência', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/gastos/previa', [
        'descricao' => 'Sofá', 'valor' => '1.200,00', 'forma' => 'pix', 'vencimento' => '2026-07-05',
        'parcelas' => 2, 'recorrente' => true, 'periodicidade' => 'mensal', 'dia_recorrencia' => 5,
    ])->assertStatus(422)->assertJsonValidationErrors(['recorrente']);

    expect(Transaction::count())->toBe(0)
        ->and(Recurrence::count())->toBe(0);
});

it('a prévia de um gasto recorrente informa quando a recorrência começa', function () {
    $user = User::factory()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-09 08:00', 'America/Sao_Paulo'));

    $this->actingAs($user)->postJson('/gastos/previa', [
        'descricao' => 'Netflix', 'valor' => '55,90', 'forma' => 'pix', 'vencimento' => '2026-07-05',
        'recorrente' => true, 'periodicidade' => 'mensal', 'dia_recorrencia' => 5,
    ])->assertOk()
        ->assertJsonPath('recorrencia.dia', 5)
        ->assertJsonPath('recorrencia.primeiraEm', 'agosto de 2026');

    expect(Transaction::count())->toBe(0)
        ->and(Recurrence::count())->toBe(0);
});

/* ------------------------------------------------------------- validação ---- */

it('rejeita descrição e valor ausentes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/gastos', ['forma' => 'pix', 'vencimento' => '2026-07-05'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['descricao', 'valor']);
});

it('rejeita valor não monetário', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/gastos', [
        'descricao' => 'x', 'valor' => 'abc', 'forma' => 'pix', 'vencimento' => '2026-07-05',
    ])->assertStatus(422)->assertJsonValidationErrors(['valor']);
});

it('rejeita forma de pagamento inválida', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/gastos', [
        'descricao' => 'x', 'valor' => '10,00', 'forma' => 'cheque',
    ])->assertStatus(422)->assertJsonValidationErrors(['forma']);
});

it('crédito exige um cartão', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/gastos', [
        'descricao' => 'x', 'valor' => '10,00', 'forma' => 'credito',
    ])->assertStatus(422)->assertJsonValidationErrors(['card_id']);
});

it('não aceita cartão de outro usuário', function () {
    $user = User::factory()->create();
    $alheio = Card::factory()->for(User::factory()->create())->create();

    $this->actingAs($user)->postJson('/gastos', [
        'descricao' => 'x', 'valor' => '10,00', 'forma' => 'credito', 'card_id' => $alheio->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['card_id']);

    expect(Transaction::count())->toBe(0);
});

it('não aceita categoria de outro usuário', function () {
    $user = User::factory()->create();
    $alheia = Category::factory()->for(User::factory()->create())->create();

    $this->actingAs($user)->postJson('/gastos', [
        'descricao' => 'x', 'valor' => '10,00', 'forma' => 'pix', 'vencimento' => '2026-07-05',
        'categoria_id' => $alheia->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['categoria_id']);
});

it('rejeita parcelas fora do intervalo 1..24', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)->postJson('/gastos', [
        'descricao' => 'x', 'valor' => '10,00', 'forma' => 'credito', 'card_id' => $card->id, 'parcelas' => 25,
    ])->assertStatus(422)->assertJsonValidationErrors(['parcelas']);
});

it('permite gasto à vista sem categoria (categoria é opcional)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/gastos', [
        'descricao' => 'Pão', 'valor' => '8,50', 'forma' => 'dinheiro', 'vencimento' => '2026-07-05',
    ])->assertOk();

    expect(Transaction::first()->categoria_id)->toBeNull();
});

/* ------------------------------------------------------------ duplicidade --- */

it('a prévia sinaliza um gasto duplicado', function () {
    $user = User::factory()->create();
    $cat = Category::factory()->for($user)->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-05', 'America/Sao_Paulo'));

    $payload = [
        'descricao' => 'Aluguel', 'valor' => '1.800,00', 'forma' => 'boleto',
        'vencimento' => '2026-07-05', 'categoria_id' => $cat->id,
    ];

    $this->actingAs($user)->postJson('/gastos', $payload)->assertOk();

    $this->actingAs($user)->postJson('/gastos/previa', $payload)
        ->assertOk()
        ->assertJsonPath('ehDuplicado', true);
});

/* --------------------------------------------------- home carrega o modal --- */

it('a home carrega os cartões e categorias do próprio usuário no modal', function () {
    $user = User::factory()->create();
    Card::factory()->for($user)->create(['descricao' => 'Nubank do Lucas', 'final_4' => '1234']);
    Category::factory()->for($user)->create(['nome' => 'Feira']);

    $outro = User::factory()->create();
    Card::factory()->for($outro)->create(['descricao' => 'Cartao Alheio']);
    Category::factory()->for($outro)->create(['nome' => 'CategoriaAlheia']);

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('Registrar gasto')
        ->assertSee('Nubank do Lucas')
        ->assertSee('Feira')
        ->assertDontSee('Cartao Alheio')
        ->assertDontSee('CategoriaAlheia');
});
