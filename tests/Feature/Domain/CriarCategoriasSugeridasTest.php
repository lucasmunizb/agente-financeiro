<?php

declare(strict_types=1);

use App\Domain\Categoria\CriarCategoriasSugeridas;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Categorias sugeridas de um usuário novo (doc 08 §5). Idempotente e escopo por usuário.
 */

uses(RefreshDatabase::class);

it('cria as 11 categorias sugeridas com cor e ícone', function () {
    $user = User::factory()->create();

    app(CriarCategoriasSugeridas::class)->para($user->id);

    $categorias = Category::where('user_id', $user->id)->get();
    expect($categorias)->toHaveCount(11)
        ->and($categorias->pluck('nome'))->toContain('Alimentação', 'Transporte', 'Outros')
        ->and($categorias->firstWhere('nome', 'Transporte')->icone)->toBe('car')
        ->and($categorias->every(fn ($c) => filled($c->cor)))->toBeTrue();
});

it('é idempotente: rodar de novo não duplica', function () {
    $user = User::factory()->create();

    app(CriarCategoriasSugeridas::class)->para($user->id);
    app(CriarCategoriasSugeridas::class)->para($user->id);

    expect(Category::where('user_id', $user->id)->count())->toBe(11);
});

it('é isolado por usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();

    app(CriarCategoriasSugeridas::class)->para($user->id);

    expect(Category::where('user_id', $user->id)->count())->toBe(11)
        ->and(Category::where('user_id', $outro->id)->count())->toBe(0);
});

it('o registro de conta já cria as categorias sugeridas', function () {
    $this->post('/criar-conta', [
        'name' => 'Maria', 'email' => 'maria@example.com',
        'password' => 'senha-super-secreta', 'password_confirmation' => 'senha-super-secreta',
        'terms' => '1',
    ])->assertRedirect(route('onboarding'));

    $user = User::where('email', 'maria@example.com')->first();
    expect(Category::where('user_id', $user->id)->count())->toBe(11);
});
