<?php

declare(strict_types=1);

use App\Domain\Receita\DadosReceita;
use App\Domain\Receita\RegistrarReceita;
use App\Models\Income;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Tela de receitas (spec FE §7.10). Borda fina: lista as receitas do mês (filtro por tipo) e o
 * total JÁ somado pelo domínio (ReceitasDoMes; a UI nunca soma, regra 4). Adicionar é em DOIS
 * passos (regra 7): "Revisar e confirmar" mostra o resumo sem gravar; "Confirmar" grava. Escopo
 * estrito por usuário.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function novaReceita(User $user, array $over = []): Income
{
    return (new RegistrarReceita)->registrar(new DadosReceita(
        userId: $user->id,
        descricao: $over['descricao'] ?? 'Salário',
        valorCents: $over['valorCents'] ?? 500000,
        data: CarbonImmutable::parse($over['data'] ?? '2026-07-05', 'America/Sao_Paulo'),
        tipo: $over['tipo'] ?? Income::TIPO_FIXA,
    ), CarbonImmutable::now('America/Sao_Paulo'));
}

it('a tela exige login', function () {
    $this->get(route('receitas'))->assertRedirect(route('login'));
});

it('lista as receitas do mês com o total e o filtro', function () {
    $user = User::factory()->create();
    novaReceita($user, ['descricao' => 'Salário', 'valorCents' => 500000, 'tipo' => Income::TIPO_FIXA, 'data' => '2026-07-05']);
    novaReceita($user, ['descricao' => 'Freela', 'valorCents' => 120000, 'tipo' => Income::TIPO_VARIAVEL, 'data' => '2026-07-12']);

    $this->actingAs($user)->get(route('receitas'))
        ->assertOk()
        ->assertSee('Receitas de julho')
        ->assertSee('R$ 6.200,00')       // total do mês (5.000 + 1.200)
        ->assertSee('Salário')
        ->assertSee('Freela')
        ->assertSee('Todas')
        ->assertSee('Fixa')
        ->assertSee('Variável');
});

it('mostra o estado vazio quando não há receitas', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('receitas'))
        ->assertOk()
        ->assertSee('Nenhuma receita neste mês.');
});

it('filtra a listagem por tipo', function () {
    $user = User::factory()->create();
    novaReceita($user, ['descricao' => 'Mesada', 'tipo' => Income::TIPO_FIXA]); // "Salário" é placeholder do form
    novaReceita($user, ['descricao' => 'Freela', 'tipo' => Income::TIPO_VARIAVEL, 'data' => '2026-07-12']);

    $this->actingAs($user)->get(route('receitas', ['tipo' => 'variavel']))
        ->assertOk()
        ->assertSee('Freela')
        ->assertDontSee('Mesada');
});

it('passo 1: "Revisar e confirmar" mostra o resumo SEM gravar (regra 7)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('receitas.store'), [
        'descricao' => 'Salário', 'valor' => '5.000,00', 'tipo' => 'fixa', 'data' => '2026-07-05',
    ])
        ->assertOk()
        ->assertSee('Confirme a receita')
        ->assertSee('R$ 5.000,00');

    expect(Income::count())->toBe(0); // nada gravado antes do "sim"
});

it('passo 2: "Confirmar" grava a receita e volta com aviso', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('receitas.store'), [
        'descricao' => 'Salário', 'valor' => '5.000,00', 'tipo' => 'fixa', 'data' => '2026-07-05', 'confirmado' => '1',
    ])
        ->assertRedirect(route('receitas'))
        ->assertSessionHas('sucesso');

    $income = Income::where('user_id', $user->id)->sole();
    expect($income->descricao)->toBe('Salário')
        ->and($income->valor_cents)->toBe(500000)
        ->and($income->tipo)->toBe('fixa');
});

it('rejeita receita inválida (valor e tipo)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('receitas.store'), [
        'descricao' => 'x', 'valor' => '0,00', 'tipo' => 'outro', 'data' => '2026-07-05', 'confirmado' => '1',
    ])->assertSessionHasErrors(['valor', 'tipo']);

    expect(Income::count())->toBe(0);
});

it('a lista mostra ações de editar/excluir por receita', function () {
    $user = User::factory()->create();
    novaReceita($user, ['descricao' => 'Freela']);

    $this->actingAs($user)->get(route('receitas'))
        ->assertOk()
        ->assertSee('Editar receita')
        ->assertSee('Excluir receita');
});

it('edita a receita, salva e volta com aviso', function () {
    $user = User::factory()->create();
    $income = novaReceita($user, ['descricao' => 'Salário', 'valorCents' => 500000]);

    $this->actingAs($user)
        ->put(route('receitas.update', $income->getRouteKey()), [
            'descricao' => 'Salário líquido', 'valor' => '5.500,00', 'tipo' => 'variavel', 'data' => '2026-07-06',
        ])
        ->assertRedirectContains('/receitas')
        ->assertSessionHas('sucesso');

    $income->refresh();
    expect($income->descricao)->toBe('Salário líquido')
        ->and($income->valor_cents)->toBe(550000)
        ->and($income->tipo)->toBe('variavel');
});

it('a edição rejeita dados inválidos (bag editarReceita)', function () {
    $user = User::factory()->create();
    $income = novaReceita($user);

    $this->actingAs($user)
        ->put(route('receitas.update', $income->getRouteKey()), ['descricao' => 'x', 'valor' => '0,00', 'tipo' => 'x', 'data' => ''])
        ->assertSessionHasErrors(['valor', 'tipo', 'data'], null, 'editarReceita');
});

it('não edita receita de outro usuário (404)', function () {
    $user = User::factory()->create();
    $alheia = novaReceita(User::factory()->create());

    $this->actingAs($user)
        ->put(route('receitas.update', $alheia->getRouteKey()), [
            'descricao' => 'x', 'valor' => '10,00', 'tipo' => 'fixa', 'data' => '2026-07-05',
        ])
        ->assertNotFound();
});

it('exclui a receita (cancelamento lógico) e volta com aviso', function () {
    $user = User::factory()->create();
    $income = novaReceita($user);

    $this->actingAs($user)
        ->delete(route('receitas.destroy', $income->getRouteKey()))
        ->assertRedirect(route('receitas'))
        ->assertSessionHas('sucesso');

    expect(Income::whereKey($income->id)->exists())->toBeFalse()
        ->and(Income::withTrashed()->find($income->id)->deleted_at)->not->toBeNull();
});

it('não exclui receita de outro usuário (404)', function () {
    $user = User::factory()->create();
    $alheia = novaReceita(User::factory()->create());

    $this->actingAs($user)->delete(route('receitas.destroy', $alheia->getRouteKey()))->assertNotFound();

    expect(Income::whereKey($alheia->id)->exists())->toBeTrue();
});

it('recusa o id REAL no path ao excluir (só token opaco)', function () {
    $user = User::factory()->create();
    $income = novaReceita($user);

    $this->actingAs($user)->delete("/receitas/{$income->id}")->assertNotFound();

    expect(Income::whereKey($income->id)->exists())->toBeTrue();
});
