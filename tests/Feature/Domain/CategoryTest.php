<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * categories — categoria do gasto (doc 08 / doc 04).
 * Categoria única por gasto; sem subcategoria no MVP; cor e ícone; isolada por
 * user_id; pode ser arquivada sem perder histórico; soft delete (LGPD).
 * Unicidade do nome por usuário entre categorias não excluídas.
 */

uses(RefreshDatabase::class);

it('pertence a um usuário', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create();

    expect($category->user)->toBeInstanceOf(User::class)
        ->and($category->user->id)->toBe($user->id);
});

it('guarda nome, cor e ícone', function () {
    $category = Category::factory()->create([
        'nome' => 'Transporte',
        'cor' => '#FF8800',
        'icone' => 'car',
    ]);

    expect($category->fresh())
        ->nome->toBe('Transporte')
        ->cor->toBe('#FF8800')
        ->icone->toBe('car');
});

it('nasce não arquivada e pode ser arquivada sem perder histórico', function () {
    $category = Category::factory()->create();
    $transaction = Transaction::factory()->create(['categoria_id' => $category->id]);

    expect($category->fresh()->arquivada)->toBeFalse();

    $category->update(['arquivada' => true]);

    expect($category->fresh()->arquivada)->toBeTrue()
        ->and($transaction->fresh()->categoria_id)->toBe($category->id);
});

it('faz soft delete (exclusão lógica)', function () {
    $category = Category::factory()->create();

    $category->delete();

    expect(Category::find($category->id))->toBeNull()
        ->and(Category::withTrashed()->find($category->id))->not->toBeNull();
});

it('impede nome duplicado para o mesmo usuário', function () {
    $user = User::factory()->create();
    Category::factory()->for($user)->create(['nome' => 'Lazer']);

    expect(fn () => Category::factory()->for($user)->create(['nome' => 'Lazer']))
        ->toThrow(QueryException::class);
});

it('permite o mesmo nome para usuários diferentes', function () {
    $outra = Category::factory()->for(User::factory())->create(['nome' => 'Lazer']);
    $minha = Category::factory()->for(User::factory())->create(['nome' => 'Lazer']);

    expect($outra->exists)->toBeTrue()->and($minha->exists)->toBeTrue();
});

it('permite recriar a mesma categoria após soft delete', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create(['nome' => 'Lazer']);

    $category->delete();

    $nova = Category::factory()->for($user)->create(['nome' => 'Lazer']);

    expect($nova->exists)->toBeTrue();
});

it('tem palavras-chave e aliases associados', function () {
    $category = Category::factory()->create();
    $category->keywords()->create(['palavra_chave' => 'uber']);
    $category->aliases()->create(['user_id' => $category->user_id, 'alias' => 'uber trip']);

    expect($category->keywords)->toHaveCount(1)
        ->and($category->aliases)->toHaveCount(1);
});
