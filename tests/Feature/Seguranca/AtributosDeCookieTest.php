<?php

use App\Providers\SecurityServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Atributos do cookie de sessão (OWASP). HttpOnly bloqueia leitura por
 * JavaScript (defesa contra roubo de sessão via XSS); SameSite mitiga CSRF; em
 * produção o cookie é Secure (só trafega sob HTTPS) e o esquema é forçado para
 * https — "https only" no frontend, independente de o ops lembrar do env.
 */

uses(RefreshDatabase::class);

it('marca o cookie de sessão como HttpOnly e SameSite=lax', function () {
    $resposta = $this->get('/login');

    $cookie = collect($resposta->headers->getCookies())
        ->firstWhere('getName', config('session.cookie'))
        ?? collect($resposta->headers->getCookies())->first(
            fn ($c) => $c->getName() === config('session.cookie')
        );

    expect($cookie)->not->toBeNull();
    expect($cookie->isHttpOnly())->toBeTrue();
    expect($cookie->getSameSite())->toBe('lax');
});

it('força cookies Secure e esquema HTTPS em produção', function () {
    $this->app->detectEnvironment(fn () => 'production');

    (new SecurityServiceProvider($this->app))->boot();

    expect(config('session.secure'))->toBeTrue();
    expect(url('/login'))->toStartWith('https://');
});

it('não força HTTPS fora de produção (dev roda em HTTP)', function () {
    (new SecurityServiceProvider($this->app))->boot();

    expect(url('/login'))->toStartWith('http://');
});
