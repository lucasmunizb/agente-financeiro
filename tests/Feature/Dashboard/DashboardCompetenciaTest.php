<?php

declare(strict_types=1);

use App\Models\Income;
use App\Models\Installment;
use App\Models\Recurrence;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Navegação por competência no dashboard (FE §7.5, pendência do bloco B). O mês é escolhido
 * por ?mes=YYYY-MM (default = mês atual). "Hoje" congelado em 2026-06-15 → mês atual = junho.
 *
 * Regra "mesmomês" (decisão do usuário): os blocos RELATIVOS AO HOJE — "a vencer (7 dias)",
 * o tick "hoje" da régua e os quadros 06b (Próximas contas / Em atraso) — aparecem SÓ no
 * mês atual. Em meses históricos o dashboard é o retrato fechado do mês (disponível, gastos,
 * comparativo, fatura, donut). Os números vêm PRONTOS do domínio (regras 4/5).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 09:00:00', 'America/Sao_Paulo'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function competenciaGasto(User $user, int $cents, string $venc): Transaction
{
    $tx = Transaction::factory()->for($user)->create(['valor_total_cents' => $cents]);
    Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $venc,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);

    return $tx;
}

it('sem ?mes ancora no mês atual (junho)', function () {
    $user = User::factory()->create();
    competenciaGasto($user, 50000, '2026-06-12');

    $this->actingAs($user)->get('/')->assertOk()->assertSee('Junho de 2026');
});

it('navega para o mês pedido e mostra os números daquele mês', function () {
    $user = User::factory()->create();
    competenciaGasto($user, 50000, '2026-05-12');   // maio → R$ 500,00
    competenciaGasto($user, 120000, '2026-06-20');  // junho → R$ 1.200,00

    // Junho só aparece na visão de maio pelo card de previsão, rotulado como tal — nunca
    // somado ao retrato do mês navegado.
    $this->actingAs($user)->get('/?mes=2026-05')
        ->assertOk()
        ->assertSee('Maio de 2026')
        ->assertSee('R$ 500,00')            // gastos de maio
        ->assertSee('Previsto para junho')
        ->assertSee('R$ 1.200,00');
});

it('cai no mês atual quando ?mes é inválido/forjado', function () {
    $user = User::factory()->create();
    competenciaGasto($user, 50000, '2026-06-12');

    $this->actingAs($user)->get('/?mes=nao-e-mes')
        ->assertOk()
        ->assertSee('Junho de 2026');
});

it('em mês histórico oculta os blocos relativos ao hoje (a vencer, contas)', function () {
    $user = User::factory()->create();
    competenciaGasto($user, 50000, '2026-05-12'); // dá estado "pronto" e gasto em maio

    $this->actingAs($user)->get('/?mes=2026-05')
        ->assertOk()
        ->assertSee('Maio de 2026')
        ->assertSee('R$ 500,00')   // retrato do mês continua
        ->assertDontSee('A vencer') // some o card "A vencer (7 dias)" e o quadro "A vencer"
        ->assertDontSee('Em atraso');
});

it('no mês atual mostra os blocos relativos ao hoje', function () {
    $user = User::factory()->create();
    competenciaGasto($user, 30000, '2026-06-18'); // futura dentro de 7 dias

    // O quadro "Contas" (a vencer / em atraso) é relativo ao hoje real: só existe no mês atual.
    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('A vencer')
        ->assertSee('vence 18 de junho');
});

it('oferece navegação para o mês anterior e o seguinte', function () {
    $user = User::factory()->create();
    competenciaGasto($user, 50000, '2026-06-12');

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('mes=2026-05', false)  // link para maio (mês anterior)
        ->assertSee('mes=2026-07', false); // link para julho (mês seguinte)
});

it('na visão de mês FUTURO abate o disponível com as recorrências previstas (spec 10b)', function () {
    $user = User::factory()->create();
    Income::factory()->for($user)->create(['valor_cents' => 400000, 'data' => '2026-08-05']);
    competenciaGasto($user, 50000, '2026-08-20'); // gasto real de agosto (dá estado "pronto")
    Recurrence::factory()->for($user)->create([
        'descricao' => 'Netflix', 'valor_cents' => 5590, 'dia' => 5,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-08-05',
    ]);

    $this->actingAs($user)->get('/?mes=2026-08')
        ->assertOk()
        ->assertSee('Agosto de 2026')
        ->assertSee('R$ 3.444,10')     // 4000 − 500 − 55,90 (prevista abatida)
        ->assertDontSee('R$ 3.500,00'); // sem a previsão seria só 4000 − 500
});

it('na visão de mês FUTURO lista as recorrências previstas no quadro de contas (spec 10b)', function () {
    $user = User::factory()->create();
    competenciaGasto($user, 50000, '2026-08-20'); // gasto real de agosto (estado "pronto")
    Recurrence::factory()->for($user)->create([
        'descricao' => 'Netflix', 'valor_cents' => 5590, 'dia' => 5,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-08-05',
    ]);

    $this->actingAs($user)->get('/?mes=2026-08')
        ->assertOk()
        ->assertSee('Netflix')    // ocorrência prevista aparece na listagem
        ->assertSee('Previsto');  // com o selo de projeção
});

it('no mês ATUAL lista a recorrência a vencer e abate o disponível', function () {
    $user = User::factory()->create();
    Income::factory()->for($user)->create(['valor_cents' => 400000, 'data' => '2026-06-05']);
    competenciaGasto($user, 50000, '2026-06-20');
    Recurrence::factory()->for($user)->create([
        'descricao' => 'Netflix', 'valor_cents' => 5590, 'dia' => 20,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-06-20',
    ]);

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('Junho de 2026')
        ->assertSee('Netflix')            // a conta fixa aparece no quadro "a vencer"
        ->assertSee('R$ 3.444,10');       // 4000 − 500 − 55,90
});

it('mantém o isolamento por usuário ao navegar por competência', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    competenciaGasto($user, 50000, '2026-05-12');
    competenciaGasto($outro, 999900, '2026-05-12');

    $this->actingAs($user)->get('/?mes=2026-05')
        ->assertOk()
        ->assertSee('R$ 500,00')
        ->assertDontSee('R$ 9.999,00');
});
