<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Ligação frontend ↔ backend (apresentação). As telas já existiam; aqui só
 * garantimos que os formulários apontam para os endpoints REAIS de auth e que
 * a home autenticada expõe o logout. É etapa separada do backend (regra 3).
 */

uses(RefreshDatabase::class);

it('a tela de login posta para o endpoint real de autenticação', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('action="'.route('login.attempt').'"', false)
        ->assertSee('name="email"', false)
        ->assertSee('name="password"', false);
});

it('a tela de criar conta posta para o endpoint real de registro', function () {
    $this->get('/criar-conta')
        ->assertOk()
        ->assertSee('action="'.route('register.attempt').'"', false)
        ->assertSee('name="password_confirmation"', false)
        ->assertSee('name="terms"', false);
});

it('exibe o erro de credenciais devolvido pelo backend na tela de login', function () {
    User::factory()->create([
        'email' => 'alguem@example.com',
        'password' => bcrypt('senha-correta-123'),
    ]);

    $this->from('/login')
        ->post('/login', ['email' => 'alguem@example.com', 'password' => 'errada'])
        ->assertRedirect('/login');

    $this->followingRedirects()
        ->from('/login')
        ->post('/login', ['email' => 'alguem@example.com', 'password' => 'errada'])
        ->assertSee('E-mail ou senha incorretos.');
});

it('a home autenticada expõe o logout apontando para o endpoint real', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('action="'.route('logout').'"', false)
        ->assertSee('Sair');
});
