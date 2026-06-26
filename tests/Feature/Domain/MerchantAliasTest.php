<?php

use App\Models\Category;
use App\Models\MerchantAlias;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * merchant_aliases — regra fixa por estabelecimento (doc 08 §1/§2).
 * "Sempre Uber = transporte". Isolado por usuário; alias único por usuário
 * (uma regra por estabelecimento); aponta para uma categoria.
 */

uses(RefreshDatabase::class);

it('pertence a um usuário e a uma categoria', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create();
    $alias = MerchantAlias::factory()->for($user)->for($category, 'category')->create();

    expect($alias->user->id)->toBe($user->id)
        ->and($alias->category)->toBeInstanceOf(Category::class)
        ->and($alias->category->id)->toBe($category->id);
});

it('impede alias duplicado para o mesmo usuário', function () {
    $user = User::factory()->create();
    MerchantAlias::factory()->for($user)->create(['alias' => 'uber']);

    expect(fn () => MerchantAlias::factory()->for($user)->create(['alias' => 'uber']))
        ->toThrow(QueryException::class);
});

it('permite o mesmo alias para usuários diferentes', function () {
    $a = MerchantAlias::factory()->for(User::factory())->create(['alias' => 'uber']);
    $b = MerchantAlias::factory()->for(User::factory())->create(['alias' => 'uber']);

    expect($a->exists)->toBeTrue()->and($b->exists)->toBeTrue();
});
