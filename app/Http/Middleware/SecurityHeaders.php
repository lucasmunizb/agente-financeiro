<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança (OWASP Secure Headers), aplicados a toda resposta web.
 *
 * Fail closed para XSS: a CSP restringe scripts à própria origem — sem 'unsafe-inline'
 * em script-src, que é o principal vetor de XSS. style-src também NÃO usa 'unsafe-inline'
 * (pentest 2026-07 L6): estilos dinâmicos (largura de barras) entram por <style nonce>;
 * o resto é classe estática. Um nonce por request é gerado antes da renderização e
 * compartilhado com as views (`$cspNonce`). Em desenvolvimento (local), o HMR do Vite
 * injeta estilos por JS e roda em outra origem, então afrouxamos APENAS no ambiente
 * local (mantendo 'unsafe-inline'); testes e produção recebem a política estrita.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Nonce gerado ANTES da renderização e exposto às views (barras de progresso).
        $nonce = base64_encode(random_bytes(16));
        View::share('cspNonce', $nonce);

        $response = $next($request);

        foreach ($this->headers($request, $nonce) as $nome => $valor) {
            $response->headers->set($nome, $valor);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request, string $nonce): array
    {
        $headers = [
            'Content-Security-Policy' => $this->csp($nonce),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            // Nega APIs sensíveis do navegador que o app não usa (pentest 2026-07 L7).
            'Permissions-Policy' => 'geolocation=(), camera=(), microphone=(), payment=(), usb=()',
        ];

        // HSTS só faz sentido sob HTTPS: instrui o navegador a nunca mais falar
        // HTTP com este host. Não emitir em HTTP evita travar o dev.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    private function csp(string $nonce): string
    {
        $script = "'self'";
        // style-src usa nonce (não 'unsafe-inline') — só o <style> das barras de progresso,
        // que carrega o nonce, é permitido inline (pentest 2026-07 L6).
        $style = "'self' 'nonce-{$nonce}'";
        $connect = "'self'";

        if (app()->environment('local')) {
            // Vite dev server (HMR) — outra origem e estilos injetados por JS; libera só no
            // ambiente local. Aqui 'unsafe-inline' é intencional (o HMR não carrega o nonce).
            $vite = 'http://localhost:5173 http://[::1]:5173';
            $viteWs = 'ws://localhost:5173 ws://[::1]:5173';
            $script .= " {$vite} 'unsafe-inline'";
            $style .= " {$vite} 'unsafe-inline'";
            $connect .= " {$vite} {$viteWs}";
        }

        return implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            "style-src {$style}",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src {$connect}",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
