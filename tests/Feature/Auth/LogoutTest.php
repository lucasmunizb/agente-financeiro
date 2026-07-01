<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Logout: encerra a sessão, invalida o id (a sessão antiga não pode ser
 * reaproveitada) e regenera o token CSRF. Só faz sentido para quem está logado.
 */

uses(RefreshDatabase::class);

it('desloga o usuário autenticado e volta ao login', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('invalida a sessão ao deslogar (id regenerado)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->startSession();
    $idAntigo = session()->getId();

    $this->post('/logout');

    expect(session()->getId())->not->toBe($idAntigo);
    $this->assertGuest();
});
