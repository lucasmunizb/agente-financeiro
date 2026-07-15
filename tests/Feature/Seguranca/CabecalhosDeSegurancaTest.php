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

it('não permite estilo inline arbitrário: style-src usa nonce, não unsafe-inline (pentest L6)', function () {
    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    // Em testes/produção, style-src é 'self' + nonce por request — sem 'unsafe-inline'.
    expect($csp)->toMatch("/style-src 'self' 'nonce-[A-Za-z0-9+\/=]+'/")
        ->and($csp)->not->toContain("style-src 'self' 'unsafe-inline'");
});

it('o nonce de estilo muda a cada requisição (não é fixo)', function () {
    $extrai = fn (?string $csp): ?string => preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", (string) $csp, $m) ? $m[1] : null;

    $n1 = $extrai($this->get('/login')->headers->get('Content-Security-Policy'));
    $n2 = $extrai($this->get('/login')->headers->get('Content-Security-Policy'));

    expect($n1)->not->toBeNull()->and($n2)->not->toBeNull()->and($n1)->not->toBe($n2);
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

it('nega APIs sensíveis do navegador via Permissions-Policy (pentest L7)', function () {
    $policy = $this->get('/login')->headers->get('Permissions-Policy');

    expect($policy)->not->toBeNull()
        ->toContain('geolocation=()')
        ->toContain('camera=()')
        ->toContain('microphone=()');
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
