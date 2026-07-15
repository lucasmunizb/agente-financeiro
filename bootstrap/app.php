<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Atrás do proxy TLS (Caddy no Swarm / cloudflared em dev): sem isso o
        // request nunca é visto como https (HSTS não sai) e o IP do cliente é o
        // IP do proxy (rate limit e auditoria por IP quebram). A lista de proxies
        // vem de env porque o IP do serviço proxy na overlay network é dinâmico.
        $middleware->trustProxies(
            at: array_filter(explode(',', (string) env('TRUSTED_PROXIES', ''))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Host header fixado ao APP_URL em produção (pentest 2026-07 L11): impede que um
        // proxy mal configurado (X-Forwarded-Host confiado acima) influencie o Host. Só é
        // enforçado fora de local/testes (padrão do TrustHosts), então não trava o dev.
        $middleware->trustHosts(at: static function (): array {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);

            return is_string($host) && $host !== '' ? [$host] : [];
        });

        // Webhook do Telegram não tem sessão/CSRF (validado pelo segredo).
        $middleware->validateCsrfTokens(except: ['telegram/webhook']);

        // Cabeçalhos de segurança (CSP/anti-XSS, anti-clickjacking, HSTS) em
        // toda resposta web (OWASP Secure Headers).
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
