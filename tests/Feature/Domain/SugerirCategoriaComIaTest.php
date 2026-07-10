<?php

use App\Ai\Agents\SugeridorDeCategoria;
use App\Domain\Categoria\SugerirCategoriaComIa;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Ai;

/*
 * Sugestão de categoria via IA com guard determinístico (regra 4 / doc 02). Carrega as
 * categorias ATIVAS do usuário, pede à IA UMA opção e resolve o nome de volta ao id — mas só
 * confia no id se ele corresponder a uma categoria real e ativa daquele usuário (anti-
 * alucinação). Escopo estrito por user_id: categoria de terceiro/arquivada nunca é aceita.
 */

uses(RefreshDatabase::class);

it('mapeia o nome sugerido pela IA para o id da categoria ativa do usuário', function () {
    $user = User::factory()->create();
    $mercado = Category::factory()->for($user)->create(['nome' => 'Mercado']);
    Ai::fakeAgent(SugeridorDeCategoria::class, [['categoria' => 'Mercado']]);

    expect(app(SugerirCategoriaComIa::class)->sugerir($user->id, 'compras extra'))
        ->toBe($mercado->id);
});

it('casa o nome ignorando acento e caixa', function () {
    $user = User::factory()->create();
    $cat = Category::factory()->for($user)->create(['nome' => 'Alimentação']);
    Ai::fakeAgent(SugeridorDeCategoria::class, [['categoria' => 'alimentacao']]);

    expect(app(SugerirCategoriaComIa::class)->sugerir($user->id, 'almoço'))
        ->toBe($cat->id);
});

it('guard anti-alucinação: nome inexistente para o usuário vira null', function () {
    $user = User::factory()->create();
    Category::factory()->for($user)->create(['nome' => 'Mercado']);
    Ai::fakeAgent(SugeridorDeCategoria::class, [['categoria' => 'Viagem Interestelar']]);

    expect(app(SugerirCategoriaComIa::class)->sugerir($user->id, 'x'))->toBeNull();
});

it('não aceita categoria arquivada mesmo que a IA a devolva', function () {
    $user = User::factory()->create();
    Category::factory()->for($user)->create(['nome' => 'Arquivada', 'arquivada' => true]);
    Ai::fakeAgent(SugeridorDeCategoria::class, [['categoria' => 'Arquivada']]);

    expect(app(SugerirCategoriaComIa::class)->sugerir($user->id, 'x'))->toBeNull();
});

it('não aceita categoria de outro usuário (isolamento)', function () {
    $outro = User::factory()->create();
    Category::factory()->for($outro)->create(['nome' => 'DoOutro']);
    $user = User::factory()->create();
    Category::factory()->for($user)->create(['nome' => 'Minha']);
    Ai::fakeAgent(SugeridorDeCategoria::class, [['categoria' => 'DoOutro']]);

    expect(app(SugerirCategoriaComIa::class)->sugerir($user->id, 'x'))->toBeNull();
});

it('sem categorias ativas, não chama a IA e devolve null', function () {
    $user = User::factory()->create();
    Ai::fakeAgent(SugeridorDeCategoria::class, [['categoria' => 'Qualquer']]);

    expect(app(SugerirCategoriaComIa::class)->sugerir($user->id, 'x'))->toBeNull();

    Ai::assertAgentNeverPrompted(SugeridorDeCategoria::class);
});
