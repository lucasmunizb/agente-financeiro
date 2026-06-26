<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Ajustes da tabela users para o domínio (início do bloco 3 da F1):
 * name nullable, timezone (fuso base SP), aceite_lgpd_em (consentimento LGPD)
 * e soft delete (exclusão lógica — regra inviolável nº 6 / LGPD).
 */

uses(RefreshDatabase::class);

it('permite criar usuário sem nome (name nullable, minimiza dado pessoal)', function () {
    $user = User::factory()->create(['name' => null]);

    expect($user->fresh()->name)->toBeNull();
});

it('usa America/Sao_Paulo como fuso padrão do usuário', function () {
    $user = User::create([
        'email' => 'sem-tz@example.com',
        'password' => 'secret-pass',
    ]);

    expect($user->fresh()->timezone)->toBe('America/Sao_Paulo');
});

it('persiste o aceite de LGPD como data/hora', function () {
    $user = User::factory()->create(['aceite_lgpd_em' => now()]);

    expect($user->fresh()->aceite_lgpd_em)
        ->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

it('faz soft delete do usuário (exclusão lógica LGPD)', function () {
    $user = User::factory()->create();

    $user->delete();

    expect(User::find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id))->not->toBeNull()
        ->and($user->fresh()->deleted_at)->not->toBeNull();
});
