<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * budgets — orçamento mensal geral (doc 04 / doc 08 §6).
 * limite_cents por (user, mes). categoria_id é nullable (geral = null). Apenas um
 * orçamento GERAL por usuário/mês. Isolado por usuário.
 */

uses(RefreshDatabase::class);

it('pertence a um usuário', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->for($user)->create();

    expect($budget->user->id)->toBe($user->id);
});

it('guarda mês e limite em centavos, categoria geral é nula', function () {
    $budget = Budget::factory()->create([
        'mes' => '2026-06',
        'limite_cents' => 800000,
        'categoria_id' => null,
    ]);

    expect($budget->fresh())
        ->mes->toBe('2026-06')
        ->limite_cents->toBe(800000)
        ->categoria_id->toBeNull();
});

it('impede dois orçamentos gerais no mesmo mês para o usuário', function () {
    $user = User::factory()->create();
    Budget::factory()->for($user)->create(['mes' => '2026-06', 'categoria_id' => null]);

    expect(fn () => Budget::factory()->for($user)->create(['mes' => '2026-06', 'categoria_id' => null]))
        ->toThrow(QueryException::class);
});

it('permite orçamento geral em meses diferentes', function () {
    $user = User::factory()->create();
    $junho = Budget::factory()->for($user)->create(['mes' => '2026-06', 'categoria_id' => null]);
    $julho = Budget::factory()->for($user)->create(['mes' => '2026-07', 'categoria_id' => null]);

    expect($junho->exists)->toBeTrue()->and($julho->exists)->toBeTrue();
});

it('pode referenciar uma categoria', function () {
    $user = User::factory()->create();
    $categoria = Category::factory()->for($user)->create();
    $budget = Budget::factory()->for($user)->create(['categoria_id' => $categoria->id]);

    expect($budget->categoria)->toBeInstanceOf(Category::class)
        ->and($budget->categoria->id)->toBe($categoria->id);
});
