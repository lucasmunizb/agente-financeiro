<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * accounts — conta bancária para PIX/débito (doc 04 / doc 03 §4.6).
 * Isolada por user_id; soft delete; identidade única entre contas ativas.
 */

uses(RefreshDatabase::class);

it('pertence a um usuário', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    expect($account->user)->toBeInstanceOf(User::class)
        ->and($account->user->id)->toBe($user->id);
});

it('guarda banco e descrição', function () {
    $account = Account::factory()->create([
        'banco' => 'Itaú',
        'descricao' => 'Conta corrente',
    ]);

    expect($account->fresh())
        ->banco->toBe('Itaú')
        ->descricao->toBe('Conta corrente');
});

it('faz soft delete (exclusão lógica)', function () {
    $account = Account::factory()->create();

    $account->delete();

    expect(Account::find($account->id))->toBeNull()
        ->and(Account::withTrashed()->find($account->id))->not->toBeNull();
});

it('impede conta ativa duplicada para o mesmo usuário', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['banco' => 'Itaú', 'descricao' => 'CC']);

    expect(fn () => Account::factory()->for($user)->create(['banco' => 'Itaú', 'descricao' => 'CC']))
        ->toThrow(QueryException::class);
});

it('permite recriar a mesma conta após soft delete (unicidade parcial)', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['banco' => 'Itaú', 'descricao' => 'CC']);

    $account->delete();

    $novo = Account::factory()->for($user)->create(['banco' => 'Itaú', 'descricao' => 'CC']);

    expect($novo->exists)->toBeTrue();
});
