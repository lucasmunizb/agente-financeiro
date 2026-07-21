<?php

declare(strict_types=1);

use App\Models\Card;
use App\Models\Installment;
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
 * Quadro "Contas" do dashboard (spec 06b): mostra TUDO que vence nos próximos 15 dias —
 * lançamentos, parcelas e contas fixas (ocorrência real ou projeção do molde) —, com as
 * cobranças de cada cartão condensadas numa linha só: o somatório da fatura e o dia em que
 * ela vence (quem paga fatura não paga compra a compra).
 *
 * Os valores chegam PRONTOS do domínio (regra 4) — a tela não soma nada. "Hoje" congelado
 * em 2026-06-15 ⇒ janela = [15/06, 30/06].
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 09:00:00', 'America/Sao_Paulo'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function quadroConta(User $user, int $cents, string $venc, string $descricao, ?Card $card = null): Transaction
{
    $tx = Transaction::factory()->for($user)->create([
        'descricao' => $descricao,
        'valor_total_cents' => $cents,
        'card_id' => $card?->id,
    ]);
    Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $venc,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);

    return $tx;
}

it('condensa as compras do cartão numa linha só, com o total e o vencimento da fatura', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234', 'dia_fechamento' => 28, 'dia_vencimento' => 25]);

    quadroConta($user, 12000, '2026-06-25', 'Mercado do Zé', $card);
    quadroConta($user, 3550, '2026-06-25', 'Farmácia Pague Bem', $card);
    quadroConta($user, 20000, '2026-06-25', 'Posto Ipiranga', $card);

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('Fatura Nubank')
        ->assertSee('R$ 355,50')            // 120 + 35,50 + 200, somado pelo domínio
        ->assertSee('vence 25 de junho')
        ->assertSee('3 compras')
        ->assertDontSee('Mercado do Zé')    // compra individual não polui o quadro
        ->assertDontSee('Posto Ipiranga');
});

it('mantém individualmente tudo que é fora de cartão, junto da linha da fatura', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);

    quadroConta($user, 12000, '2026-06-25', 'Mercado', $card);
    quadroConta($user, 150000, '2026-06-20', 'Aluguel');
    quadroConta($user, 9990, '2026-06-22', 'Internet');
    Recurrence::factory()->for($user)->create([
        'descricao' => 'Netflix', 'valor_cents' => 5590, 'dia' => 18,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-06-18',
    ]);

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('Aluguel')
        ->assertSee('Internet')
        ->assertSee('Netflix')              // conta fixa prevista entra no quadro
        ->assertSee('Fatura Nubank')
        ->assertSee('4 contas');            // 3 individuais + a fatura
});

it('limita a janela a 15 dias a partir de hoje', function () {
    $user = User::factory()->create();

    quadroConta($user, 10000, '2026-06-30', 'Dentro da janela');   // hoje + 15
    quadroConta($user, 99900, '2026-07-05', 'Fora da janela');     // hoje + 20

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('Dentro da janela')
        ->assertDontSee('Fora da janela');
});

it('lista TODAS as contas da janela, sem cortar em cinco', function () {
    $user = User::factory()->create();

    foreach (range(1, 8) as $i) {
        quadroConta($user, 1000 * $i, '2026-06-'.(15 + $i), 'Conta número '.$i);
    }

    $resp = $this->actingAs($user)->get('/')->assertOk();

    foreach (range(1, 8) as $i) {
        $resp->assertSee('Conta número '.$i);
    }
});

it('lista TODAS as contas em atraso, com o cartão também condensado', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['descricao' => 'Itaú', 'final_4' => '9876']);

    quadroConta($user, 12000, '2026-06-05', 'Livraria', $card);
    quadroConta($user, 8000, '2026-06-05', 'Cinema', $card);
    foreach (range(1, 6) as $i) {
        quadroConta($user, 1000 * $i, '2026-06-0'.$i, 'Atrasada '.$i);
    }

    $resp = $this->actingAs($user)->get('/')->assertOk()->assertSee('Em atraso');

    foreach (range(1, 6) as $i) {
        $resp->assertSee('Atrasada '.$i);
    }

    $resp->assertSee('Fatura Itaú')
        ->assertSee('R$ 200,00')        // 120 + 80 da fatura vencida
        ->assertDontSee('Livraria');
});

it('revela as ações da conta no fluxo da lista, não num painel flutuante', function () {
    $user = User::factory()->create();

    quadroConta($user, 15000, '2026-06-20', 'Aluguel');

    $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

    // Divulgação progressiva nativa: a linha vira o gatilho e o painel entra no fluxo,
    // empurrando as de baixo. Flutuante seria recortado pela caixa de rolagem do quadro.
    expect($html)->toContain('<summary')
        ->and($html)->toContain('Marcar como paga')
        ->and($html)->not->toContain('absolute right-0 z-20');
});

it('oferece "marcar como paga" na conta fixa já materializada do quadro', function () {
    $user = User::factory()->create();

    $rec = Recurrence::factory()->for($user)->create([
        'descricao' => 'Internet', 'valor_cents' => 9990, 'dia' => 20,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-07-01',
    ]);
    quadroConta($user, 15000, '2026-06-20', 'Aluguel');
    $ocorrencia = RecurrenceOccurrence::factory()->create([
        'user_id' => $user->id, 'recurrence_id' => $rec->id,
        'competencia' => '2026-06', 'descricao' => 'Internet', 'valor_cents' => 9990,
        'data_cobranca' => '2026-06-20', 'vencimento' => '2026-06-20',
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);

    // A ocorrência EXISTE no banco: o quadro tem alvo para a ação (id sempre opaco).
    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('Internet')
        ->assertSee('Marcar como paga')
        // Uma ação por linha pagável: a da parcela e a da ocorrência (id opaco, gerado na view).
        ->assertSeeInOrder(['Aluguel', 'Internet'])
        ->assertSee('/lancamentos/recorrencia/', false);
});

it('oferece "marcar como paga" também na conta fixa apenas PREVISTA', function () {
    $user = User::factory()->create();

    // Molde ativo cuja competência ainda não foi gerada ⇒ a linha do quadro é projeção. O alvo
    // da ação é o MOLDE + a competência: a rota materializa a ocorrência antes de pagar.
    $molde = Recurrence::factory()->for($user)->create([
        'descricao' => 'Academia', 'valor_cents' => 12000, 'dia' => 20,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-06-01',
    ]);

    $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

    expect($html)->toContain('Academia')
        ->and($html)->toContain('Marcar como paga')
        ->and($html)->toContain('/lancamentos/recorrencia-prevista/')
        ->and($html)->toContain('name="competencia" value="2026-06"');

    // Ler não grava (regra 7): o quadro só oferece a ação; nada é materializado no GET.
    expect($molde->refresh()->proxima_em->toDateString())->toBe('2026-06-01')
        ->and(RecurrenceOccurrence::query()->count())->toBe(0);
});

it('não oferece ação na conta fixa prevista EM CARTÃO (a fatura é que quita)', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234', 'dia_fechamento' => 28, 'dia_vencimento' => 25]);

    Recurrence::factory()->for($user)->create([
        'descricao' => 'Streaming', 'valor_cents' => 5590, 'dia' => 18,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-06-01',
        'card_id' => $card->id,
    ]);

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('Fatura Nubank')
        ->assertDontSee('Marcar como paga');
});

it('não oferece ação de pagamento na linha condensada da fatura', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);

    quadroConta($user, 12000, '2026-06-25', 'Mercado', $card);

    // Cartão se quita pela fatura (§4.3) — a linha da fatura não tem "marcar como paga".
    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('Fatura Nubank')
        ->assertDontSee('Marcar como paga');
});
