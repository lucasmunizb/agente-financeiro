<?php

declare(strict_types=1);

use App\Domain\Disponivel\ConsultarDisponivelDoMes;
use App\Domain\FaturaCartao\ConsultarFaturaCartao;
use App\Domain\Shared\Money;
use App\Models\Card;
use App\Models\Category;
use App\Models\Income;
use App\Models\Installment;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Dashboard "Visão Geral" (spec 06 / FE §7.5). Fiação da tela com o domínio já testado
 * (ResumoDoMes + consultas determinísticas): valores reais, formatados na borda (regra
 * 5), sem cálculo no cliente (regra 4), escopo estrito por usuário. "Hoje" é congelado.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 09:00:00', 'America/Sao_Paulo'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function dashboardGasto(User $user, int $cents, string $venc, ?Card $card = null, ?Category $cat = null): Transaction
{
    $tx = Transaction::factory()->for($user)->create([
        'valor_total_cents' => $cents,
        'card_id' => $card?->id,
        'categoria_id' => $cat?->id,
    ]);
    Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $venc,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);

    return $tx;
}

it('exige login', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('mostra o estado vazio quando não há lançamentos', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('Seu livro de contas está limpo.')
        ->assertSee('Junho de 2026');
});

it('exibe os números reais do mês, já formatados', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234', 'dia_fechamento' => 28, 'dia_vencimento' => 5]);
    $mercado = Category::factory()->for($user)->create(['nome' => 'Mercado']);
    $transporte = Category::factory()->for($user)->create(['nome' => 'Transporte']);

    Income::factory()->for($user)->create(['valor_cents' => 400000, 'data' => '2026-06-05']);
    dashboardGasto($user, 120000, '2026-06-20', $card, $mercado); // no cartão + próxima conta + fatura
    dashboardGasto($user, 30000, '2026-06-18', null, $transporte); // à vista, a vencer em 7 dias
    dashboardGasto($user, 20000, '2026-06-10', null, $mercado);    // já venceu no mês
    dashboardGasto($user, 100000, '2026-05-10', null, $mercado);   // mês anterior (comparativo)

    $disponivel = app(ConsultarDisponivelDoMes::class)->para($user->id, '2026-06')
        ->disponivel->disponivel->formatBRL();
    $fatura = Money::fromCents(app(ConsultarFaturaCartao::class)->para($user->id, '1234', '2026-06')->totalCents)->formatBRL();

    $resp = $this->actingAs($user)->get('/')->assertOk();

    $resp->assertSee('Junho de 2026')
        ->assertSee($disponivel)              // disponível calculado pelo domínio
        ->assertSee('R$ 1.700,00')            // gastos do mês (120+30+20)
        ->assertSee('+70,0%')                 // comparativo vs. maio (100 → 170)
        ->assertSee('R$ 1.500,00')            // a vencer em 7 dias (120+30)
        ->assertSee('2 contas')
        ->assertSee($fatura)                  // fatura do cartão (domínio)
        ->assertSee('fecha 28 de junho')
        ->assertSee('Mercado')->assertSee('R$ 1.400,00')   // donut (120+20)
        ->assertSee('Transporte')->assertSee('R$ 300,00')
        ->assertSee('vence 20 de junho')      // próximas contas
        ->assertSee('vence 18 de junho');
});

it('é isolado por usuário (não vaza dados de terceiros)', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    dashboardGasto($user, 5000, '2026-06-12', null, Category::factory()->for($user)->create(['nome' => 'MinhaCategoria']));
    dashboardGasto($outro, 999900, '2026-06-12', null, Category::factory()->for($outro)->create(['nome' => 'CategoriaAlheia']));

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('MinhaCategoria')
        ->assertDontSee('CategoriaAlheia')
        ->assertDontSee('R$ 9.999,00');
});

it('respeita ?estado= como afordância de revisão', function () {
    $user = User::factory()->create();
    dashboardGasto($user, 5000, '2026-06-12');

    $this->actingAs($user)->get('/?estado=vazio')
        ->assertOk()
        ->assertSee('Seu livro de contas está limpo.');
});
