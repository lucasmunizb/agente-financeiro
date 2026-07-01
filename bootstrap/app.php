<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
