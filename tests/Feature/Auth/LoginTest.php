<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/*
 * Login real (guard de sessão). Antes o POST /login era um placeholder que
 * SEMPRE devolvia erro. Agora autentica de verdade com Auth::attempt, regenera
 * a sessão (defesa contra fixation) e, em caso de falha, devolve mensagem
 * genérica (não revela se o e-mail existe — OWASP). Tentativas são limitadas
 * por rate limit (brute force).
 */

uses(RefreshDatabase::class);

it('autentica com credenciais corretas e leva ao app', function () {
    $user = User::factory()->create([
        'email' => 'valido@example.com',
        'password' => Hash::make('senha-correta-123'),
    ]);

    $resposta = $this->post('/login', [
        'email' => 'valido@example.com',
        'password' => 'senha-correta-123',
    ]);

    $resposta->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
});

it('recusa senha incorreta sem autenticar e com mensagem genérica', function () {
    User::factory()->create([
        'email' => 'valido@example.com',
        'password' => Hash::make('senha-correta-123'),
    ]);

    $this->from('/login')->post('/login', [
        'email' => 'valido@example.com',
        'password' => 'senha-errada',
    ])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('usa a MESMA mensagem genérica para senha errada e e-mail inexistente (anti-enumeração)', function () {
    User::factory()->create([
        'email' => 'existe@example.com',
        'password' => Hash::make('senha-correta-123'),
    ]);

    $this->from('/login')->post('/login', [
        'email' => 'existe@example.com',
        'password' => 'senha-errada',
    ]);
    $msgSenhaErrada = session('errors')->first('email');

    session()->flush();

    $this->from('/login')->post('/login', [
        'email' => 'naoexiste@example.com',
        'password' => 'qualquer-coisa',
    ]);
    $msgEmailInexistente = session('errors')->first('email');

    // OWASP: a resposta não pode distinguir "conta não existe" de "senha
    // errada". A mensagem genérica é idêntica nos dois casos.
    expect($msgSenhaErrada)
        ->toBe('E-mail ou senha incorretos.')
        ->toBe($msgEmailInexistente);

    $this->assertGuest();
});

it('regenera o id de sessão ao autenticar (anti session fixation)', function () {
    $user = User::factory()->create([
        'email' => 'valido@example.com',
        'password' => Hash::make('senha-correta-123'),
    ]);

    $this->startSession();
    $idAntigo = session()->getId();

    $this->post('/login', [
        'email' => 'valido@example.com',
        'password' => 'senha-correta-123',
    ]);

    expect(session()->getId())->not->toBe($idAntigo);
    $this->assertAuthenticatedAs($user);
});

it('bloqueia por rate limit após tentativas repetidas (brute force)', function () {
    User::factory()->create([
        'email' => 'alvo@example.com',
        'password' => Hash::make('senha-correta-123'),
    ]);

    foreach (range(1, 5) as $tentativa) {
        $this->post('/login', [
            'email' => 'alvo@example.com',
            'password' => 'errada',
        ]);
    }

    // A 6ª tentativa deve ser barrada pelo throttle, mesmo com senha correta.
    $this->from('/login')->post('/login', [
        'email' => 'alvo@example.com',
        'password' => 'senha-correta-123',
    ])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(session('errors')->first('email'))->toContain('Muitas tentativas');
});
