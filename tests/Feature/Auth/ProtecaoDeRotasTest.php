<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Primeiro tratamento de acesso: o app fica atrás de login. Toda rota da
 * aplicação exige sessão autenticada; visitante não logado é redirecionado
 * automaticamente para /login. As únicas exceções são as rotas públicas por
 * natureza: a própria tela de login e o webhook do Telegram (que tem guarda
 * própria por segredo no header, não por sessão).
 */

uses(RefreshDatabase::class);

it('redireciona visitante não autenticado para o login ao acessar a raiz', function () {
    $this->get('/')
        ->assertRedirect(route('login'));
});

it('libera a raiz para o usuário autenticado', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk();
});

it('mantém a tela de login pública', function () {
    $this->get('/login')->assertOk();
});

it('mantém criar conta e onboarding públicos (guest precisa alcançá-los)', function () {
    $this->get('/criar-conta')->assertOk();
    $this->get('/onboarding')->assertOk();
});

it('não coloca o webhook do Telegram atrás do login de sessão', function () {
    // Sem o segredo no header a guarda própria responde 403 — o importante é
    // que NÃO há redirecionamento para a tela de login (302 → /login).
    $this->postJson('/telegram/webhook', [])
        ->assertStatus(403);
});
