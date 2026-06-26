<?php

use App\Domain\Receita\ReceitasDoMes;
use App\Models\Income;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * ReceitasDoMes — soma determinística das receitas recebidas no mês (doc 03 §4.5).
 * Escopo estrito por usuário; só conta o que foi recebido dentro do mês civil.
 */

uses(RefreshDatabase::class);

it('soma as receitas recebidas no mês do usuário', function () {
    $user = User::factory()->create();
    Income::factory()->for($user)->create(['valor_cents' => 400000, 'data' => '2026-06-05']);
    Income::factory()->for($user)->create(['valor_cents' => 150000, 'data' => '2026-06-28']);

    expect(app(ReceitasDoMes::class)->para($user->id, '2026-06'))->toBe(550000);
});

it('ignora receitas de outros meses', function () {
    $user = User::factory()->create();
    Income::factory()->for($user)->create(['valor_cents' => 400000, 'data' => '2026-06-30']);
    Income::factory()->for($user)->create(['valor_cents' => 999900, 'data' => '2026-07-01']);

    expect(app(ReceitasDoMes::class)->para($user->id, '2026-06'))->toBe(400000);
});

it('ignora receitas de outro usuário', function () {
    $user = User::factory()->create();
    Income::factory()->for(User::factory())->create(['valor_cents' => 777700, 'data' => '2026-06-10']);

    expect(app(ReceitasDoMes::class)->para($user->id, '2026-06'))->toBe(0);
});
