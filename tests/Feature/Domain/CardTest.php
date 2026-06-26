<?php

use App\Models\Card;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * cards — cartão identificado por 4 dígitos finais + descrição (doc 03 §4.6).
 * Limite opcional (em centavos). Isolado por user_id; soft delete;
 * identidade única (user_id, final_4, descricao) entre cartões ativos.
 */

uses(RefreshDatabase::class);

it('pertence a um usuário', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    expect($card->user)->toBeInstanceOf(User::class)
        ->and($card->user->id)->toBe($user->id);
});

it('guarda os 4 dígitos finais e a descrição', function () {
    $card = Card::factory()->create([
        'final_4' => '1234',
        'descricao' => 'cartão pai',
    ]);

    expect($card->fresh())
        ->final_4->toBe('1234')
        ->descricao->toBe('cartão pai');
});

it('trata limite como centavos inteiros e aceita ausência (opcional)', function () {
    $comLimite = Card::factory()->create(['limite_cents' => 500000]);
    $semLimite = Card::factory()->create(['limite_cents' => null]);

    expect($comLimite->fresh()->limite_cents)->toBe(500000)
        ->and($semLimite->fresh()->limite_cents)->toBeNull();
});

it('guarda dia de fechamento e de vencimento como inteiros', function () {
    $card = Card::factory()->create([
        'dia_fechamento' => 10,
        'dia_vencimento' => 17,
    ]);

    expect($card->fresh())
        ->dia_fechamento->toBe(10)
        ->dia_vencimento->toBe(17);
});

it('faz soft delete (exclusão lógica)', function () {
    $card = Card::factory()->create();

    $card->delete();

    expect(Card::find($card->id))->toBeNull()
        ->and(Card::withTrashed()->find($card->id))->not->toBeNull();
});

it('impede cartão ativo duplicado para o mesmo usuário', function () {
    $user = User::factory()->create();
    Card::factory()->for($user)->create(['final_4' => '1234', 'descricao' => 'cartão pai']);

    expect(fn () => Card::factory()->for($user)->create(['final_4' => '1234', 'descricao' => 'cartão pai']))
        ->toThrow(QueryException::class);
});

it('permite recriar o mesmo cartão após soft delete (unicidade parcial)', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['final_4' => '1234', 'descricao' => 'cartão pai']);

    $card->delete();

    $novo = Card::factory()->for($user)->create(['final_4' => '1234', 'descricao' => 'cartão pai']);

    expect($novo->exists)->toBeTrue();
});
