<?php

declare(strict_types=1);

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\Orcamento\DefinirOrcamento;
use App\Models\Budget;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Tela de orçamento do mês (spec FE §7.11). Borda fina: exibe limite/consumo/estouro JÁ
 * avaliados pelo domínio (OrcamentoMensal/ConsumoDoMes; a UI nunca calcula, regra 4) e define o
 * limite geral (DefinirOrcamento). Seletor de mês (?mes=YYYY-MM). Escopo estrito por usuário.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function gastoOrcamento(User $user, int $cents, ?int $categoriaId = null): void
{
    (new RegistrarGastoManual)->confirmar(new DadosGastoManual(
        userId: $user->id,
        descricao: 'Gasto',
        valorTotalCents: $cents,
        dataCompra: CarbonImmutable::parse('2026-07-05', 'America/Sao_Paulo'),
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
        parcelas: 1,
        categoriaId: $categoriaId,
    ));
}

it('a tela exige login', function () {
    $this->get(route('orcamento'))->assertRedirect(route('login'));
});

it('mostra o card geral com limite, consumido, percentual e resta', function () {
    $user = User::factory()->create();
    (new DefinirOrcamento)->definir($user->id, '2026-07', 400000, CarbonImmutable::now('America/Sao_Paulo'));
    gastoOrcamento($user, 80000); // R$ 800,00 → 20% de R$ 4.000,00

    $this->actingAs($user)->get(route('orcamento'))
        ->assertOk()
        ->assertSee('Orçamento do mês')
        ->assertSee('R$ 4.000,00')      // limite
        ->assertSee('R$ 800,00')        // consumido
        ->assertSee('20%')              // percentual
        ->assertSee('Resta R$ 3.200,00');
});

it('mostra o estado sem orçamento quando não há limite', function () {
    $user = User::factory()->create();
    gastoOrcamento($user, 80000);

    $this->actingAs($user)->get(route('orcamento'))
        ->assertOk()
        ->assertSee('Defina um limite para acompanhar o consumo.')
        ->assertSee('Definir limite do mês');
});

it('sinaliza estouro quando o consumo passa do limite', function () {
    $user = User::factory()->create();
    (new DefinirOrcamento)->definir($user->id, '2026-07', 100000, CarbonImmutable::now('America/Sao_Paulo'));
    gastoOrcamento($user, 130000); // R$ 1.300,00 sobre R$ 1.000,00

    $this->actingAs($user)->get(route('orcamento'))
        ->assertOk()
        ->assertSee('Acima do limite em R$ 300,00')
        ->assertSee('130%');
});

it('lista o consumo por categoria com a etiqueta "sem limite"', function () {
    $user = User::factory()->create();
    $mercado = Category::factory()->for($user)->create(['nome' => 'Mercado']);
    (new DefinirOrcamento)->definir($user->id, '2026-07', 400000, CarbonImmutable::now('America/Sao_Paulo'));
    gastoOrcamento($user, 82000, $mercado->id);

    $this->actingAs($user)->get(route('orcamento'))
        ->assertOk()
        ->assertSee('Mercado')
        ->assertSee('R$ 820,00')
        ->assertSee('sem limite');
});

it('define o limite do mês, salva e volta à tela com aviso', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('orcamento.definir'), ['mes' => '2026-07', 'valor' => '4.000,00'])
        ->assertRedirect(route('orcamento', ['mes' => '2026-07']))
        ->assertSessionHas('sucesso');

    expect(Budget::where('user_id', $user->id)->where('mes', '2026-07')->whereNull('categoria_id')->value('limite_cents'))
        ->toBe(400000);
});

it('rejeita limite não positivo', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('orcamento.definir'), ['mes' => '2026-07', 'valor' => '0,00'])
        ->assertSessionHasErrors(['valor']);

    expect(Budget::where('user_id', $user->id)->count())->toBe(0);
});

it('respeita a competência do ?mes e não vaza de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    (new DefinirOrcamento)->definir($user->id, '2026-08', 500000, CarbonImmutable::now('America/Sao_Paulo'));
    (new DefinirOrcamento)->definir($outro->id, '2026-07', 999900, CarbonImmutable::now('America/Sao_Paulo'));

    // Agosto do próprio usuário: vê o limite de agosto; não vê o de julho do outro.
    $this->actingAs($user)->get(route('orcamento', ['mes' => '2026-08']))
        ->assertOk()
        ->assertSee('R$ 5.000,00')
        ->assertDontSee('R$ 9.999,00');
});
