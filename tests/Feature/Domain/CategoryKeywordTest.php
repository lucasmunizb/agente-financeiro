<?php

use App\Models\Category;
use App\Models\CategoryKeyword;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * category_keywords — palavras-chave do lookup determinístico (doc 08 §1).
 * Cada palavra pertence a uma categoria; a palavra é única por categoria.
 * Excluir a categoria remove as palavras (cascade).
 */

uses(RefreshDatabase::class);

it('pertence a uma categoria', function () {
    $category = Category::factory()->create();
    $keyword = CategoryKeyword::factory()->for($category, 'category')->create();

    expect($keyword->category)->toBeInstanceOf(Category::class)
        ->and($keyword->category->id)->toBe($category->id);
});

it('impede palavra-chave duplicada na mesma categoria', function () {
    $category = Category::factory()->create();
    CategoryKeyword::factory()->for($category, 'category')->create(['palavra_chave' => 'uber']);

    expect(fn () => CategoryKeyword::factory()->for($category, 'category')->create(['palavra_chave' => 'uber']))
        ->toThrow(QueryException::class);
});

it('permite a mesma palavra em categorias diferentes', function () {
    $a = CategoryKeyword::factory()->for(Category::factory(), 'category')->create(['palavra_chave' => 'jogo']);
    $b = CategoryKeyword::factory()->for(Category::factory(), 'category')->create(['palavra_chave' => 'jogo']);

    expect($a->exists)->toBeTrue()->and($b->exists)->toBeTrue();
});

it('é removida em cascata ao excluir a categoria de fato', function () {
    $category = Category::factory()->create();
    $keyword = CategoryKeyword::factory()->for($category, 'category')->create();

    $category->forceDelete();

    expect(CategoryKeyword::find($keyword->id))->toBeNull();
});
