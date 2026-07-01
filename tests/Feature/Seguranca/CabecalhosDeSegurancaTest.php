<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Cabeçalhos de segurança (OWASP Secure Headers). Fail closed para XSS: a CSP
 * restringe scripts à própria origem (sem 'unsafe-inline' em script-src), nega
 * enquadramento (clickjacking), impede sniffing de MIME e vaza o mínimo no
 * Referer. HSTS só faz sentido sob HTTPS (produção), não no HTTP de teste/dev.
 */

uses(RefreshDatabase::class);

it('define uma Content-Security-Policy que trava scripts na origem (anti-XSS)', function () {
    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->not->toBeNull()
        ->toContain("default-src 'self'")
        ->toContain("script-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'");

    // Fail closed: script-src NÃO pode liberar inline (principal vetor de XSS).
    expect($csp)->not->toContain("script-src 'self' 'unsafe-inline'");
});

it('nega enquadramento e sniffing de MIME', function () {
    $resposta = $this->get('/login');

    expect($resposta->headers->get('X-Frame-Options'))->toBe('DENY');
    expect($resposta->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('restringe o vazamento pelo Referer', function () {
    expect($this->get('/login')->headers->get('Referrer-Policy'))
        ->toBe('strict-origin-when-cross-origin');
});

it('não envia HSTS sob HTTP (só em HTTPS/produção)', function () {
    expect($this->get('/login')->headers->get('Strict-Transport-Security'))->toBeNull();
});

it('envia HSTS quando a requisição é HTTPS', function () {
    $hsts = $this->get('https://localhost/login')->headers->get('Strict-Transport-Security');

    expect($hsts)->not->toBeNull()
        ->toContain('max-age=')
        ->toContain('includeSubDomains');
});
