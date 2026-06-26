<?php

use App\Models\Income;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * incomes — receitas, base do "disponível do mês" (doc 04 / doc 03 §4.5).
 * Dinheiro em centavos; tipo fixa/variável; isolada por usuário; soft delete (LGPD).
 */

uses(RefreshDatabase::class);

it('pertence a um usuário', function () {
    $user = User::factory()->create();
    $income = Income::factory()->for($user)->create();

    expect($income->user)->toBeInstanceOf(User::class)
        ->and($income->user->id)->toBe($user->id);
});

it('trata valor como centavos inteiros e guarda a data', function () {
    $income = Income::factory()->create([
        'valor_cents' => 500000,
        'data' => '2026-06-05',
    ]);

    expect($income->fresh())
        ->valor_cents->toBe(500000)
        ->and($income->fresh()->data->toDateString())->toBe('2026-06-05');
});

it('aceita tipo fixa e variável', function () {
    $fixa = Income::factory()->create(['tipo' => 'fixa']);
    $variavel = Income::factory()->create(['tipo' => 'variavel']);

    expect($fixa->fresh()->tipo)->toBe('fixa')
        ->and($variavel->fresh()->tipo)->toBe('variavel');
});

it('faz soft delete (exclusão lógica)', function () {
    $income = Income::factory()->create();

    $income->delete();

    expect(Income::find($income->id))->toBeNull()
        ->and(Income::withTrashed()->find($income->id))->not->toBeNull();
});
