<?php

use App\Domain\Categoria\LookupDeCategoria;
use App\Models\Category;
use App\Models\MerchantAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Lookup de categoria com base no banco (doc 08 §1).
 * Carrega aliases e palavras-chave do usuário e delega ao classificador puro.
 * Escopo estrito por usuário: regras de outro usuário não influenciam.
 */

uses(RefreshDatabase::class);

it('resolve a categoria pelo alias do próprio usuário', function () {
    $user = User::factory()->create();
    $transporte = Category::factory()->for($user)->create(['nome' => 'Transporte']);
    MerchantAlias::factory()->for($user)->for($transporte, 'category')->create(['alias' => 'uber']);

    $id = app(LookupDeCategoria::class)->para($user->id, 'Uber *trip 482');

    expect($id)->toBe($transporte->id);
});

it('resolve pela palavra-chave quando não há alias', function () {
    $user = User::factory()->create();
    $viagem = Category::factory()->for($user)->create(['nome' => 'Viagem']);
    $viagem->keywords()->create(['palavra_chave' => 'hotel']);

    $id = app(LookupDeCategoria::class)->para($user->id, 'Hotel Ibis');

    expect($id)->toBe($viagem->id);
});

it('retorna null quando nenhuma regra do usuário casa', function () {
    $user = User::factory()->create();
    Category::factory()->for($user)->create();

    expect(app(LookupDeCategoria::class)->para($user->id, 'algo desconhecido'))->toBeNull();
});

it('não usa regras de outro usuário', function () {
    $outro = User::factory()->create();
    $catOutro = Category::factory()->for($outro)->create();
    MerchantAlias::factory()->for($outro)->for($catOutro, 'category')->create(['alias' => 'uber']);

    $user = User::factory()->create();

    expect(app(LookupDeCategoria::class)->para($user->id, 'uber'))->toBeNull();
});

it('ignora regras de categorias arquivadas', function () {
    $user = User::factory()->create();
    $cat = Category::factory()->for($user)->create(['arquivada' => true]);
    MerchantAlias::factory()->for($user)->for($cat, 'category')->create(['alias' => 'uber']);

    expect(app(LookupDeCategoria::class)->para($user->id, 'uber'))->toBeNull();
});
