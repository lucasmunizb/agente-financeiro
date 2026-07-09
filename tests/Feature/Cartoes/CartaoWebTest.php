<?php

declare(strict_types=1);

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Models\Card;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Tela de cartões & faturas (spec FE §7.13). Borda fina: lista os cartões, exibe a fatura do
 * selecionado por competência (total + extrato JÁ calculados por ConsultarFaturaCartao; a UI
 * nunca calcula, regra 4) e as datas/status do ciclo (CicloDaFatura, consistente com §4.2).
 * Cria cartão (CriarCartao). Escopo estrito por usuário; ids opacos.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('a tela exige login', function () {
    $this->get(route('cartoes'))->assertRedirect(route('login'));
});

it('mostra o estado sem cartão', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('cartoes'))
        ->assertOk()
        ->assertSee('Cadastre um cartão para acompanhar as faturas.')
        ->assertSee('Adicionar cartão');
});

it('lista os cartões e a fatura do selecionado, com ciclo e selo aberta', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create([
        'descricao' => 'Nubank', 'final_4' => '1234', 'dia_fechamento' => 28, 'dia_vencimento' => 5,
    ]);
    // Compra em crédito 10/07 em 3x de R$ 400,00 → 1ª parcela vence 05/08 (competência agosto).
    (new RegistrarGastoManual)->confirmar(new DadosGastoManual(
        userId: $user->id,
        descricao: 'Geladeira',
        valorTotalCents: 120000,
        dataCompra: CarbonImmutable::parse('2026-07-10', 'America/Sao_Paulo'),
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::CREDITO),
        parcelas: 3,
        cardId: $card->id,
    ));

    $this->actingAs($user)->get(route('cartoes', ['cartao' => $card->getRouteKey(), 'mes' => '2026-08']))
        ->assertOk()
        ->assertSee('Nubank')
        ->assertSee('1234')                 // 4 dígitos finais
        ->assertSee('R$ 400,00')            // total da fatura (1ª parcela) + item
        ->assertSee('Geladeira')
        ->assertSee('1/3')                  // fração da parcela
        ->assertSee('28 de julho')          // fecha
        ->assertSee('5 de agosto')          // vence
        ->assertSee('aberta');              // selo (hoje 09/07 ≤ fecha 28/07)
});

it('adiciona um cartão, salva e volta à tela com aviso', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('cartoes.store'), [
            'descricao' => 'Nubank', 'final_4' => '1234',
            'dia_fechamento' => 28, 'dia_vencimento' => 5, 'limite' => '5.000,00',
        ])
        ->assertRedirectContains('/cartoes')   // volta à tela com o novo cartão selecionado
        ->assertSessionHas('sucesso');

    $card = Card::where('user_id', $user->id)->sole();
    expect($card->descricao)->toBe('Nubank')
        ->and($card->final_4)->toBe('1234')
        ->and($card->limite_cents)->toBe(500000);
});

it('rejeita cartão inválido (4 dígitos e dias obrigatórios)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('cartoes.store'), ['descricao' => 'X', 'final_4' => '12', 'dia_fechamento' => 40])
        ->assertSessionHasErrors(['final_4', 'dia_fechamento', 'dia_vencimento']);

    expect(Card::count())->toBe(0);
});

it('não mostra nem seleciona cartão de outro usuário', function () {
    $user = User::factory()->create();
    $alheio = Card::factory()->for(User::factory()->create())->create(['descricao' => 'CartaoAlheio']);

    $this->actingAs($user)->get(route('cartoes', ['cartao' => $alheio->getRouteKey()]))
        ->assertOk()
        ->assertDontSee('CartaoAlheio');
});
