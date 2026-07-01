<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Tela de vínculo com o Telegram (apresentação — regra 3). Fica atrás do login;
 * o backend do vínculo seguro é etapa posterior. Aqui só garantimos que a tela
 * renderiza (Blade compila) e que os três estados de design respondem.
 */

uses(RefreshDatabase::class);

it('exige login para ver a tela de vínculo', function () {
    $this->get('/telegram')->assertRedirect(route('login'));
});

it('renderiza o estado pendente para o usuário autenticado', function () {
    $this->actingAs(User::factory()->create())
        ->get('/telegram')
        ->assertOk()
        ->assertSee('Conectar o Telegram')
        ->assertSee('Código de uso único')
        ->assertSee('noindex', escape: false);
});

it('alterna os estados de design por ?preview', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/telegram?preview=expirado')
        ->assertOk()->assertSee('Este código expirou');

    $this->actingAs($user)->get('/telegram?preview=vinculado')
        ->assertOk()->assertSee('Telegram conectado')->assertSee('Desconectar Telegram');
});
