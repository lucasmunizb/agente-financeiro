<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança (OWASP Secure Headers), aplicados a toda resposta web.
 *
 * Fail closed para XSS: a CSP restringe scripts à própria origem — sem
 * 'unsafe-inline' em script-src, que é o principal vetor de XSS. Estilos toleram
 * inline (menor risco e o Tailwind/utilitários exigem menos disciplina de nonce).
 * Em desenvolvimento (local), o servidor de HMR do Vite roda em outra origem, então
 * afrouxamos APENAS no ambiente local; testes e produção recebem a política estrita.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ($this->headers($request) as $nome => $valor) {
            $response->headers->set($nome, $valor);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [
            'Content-Security-Policy' => $this->csp(),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        // HSTS só faz sentido sob HTTPS: instrui o navegador a nunca mais falar
        // HTTP com este host. Não emitir em HTTP evita travar o dev.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    private function csp(): string
    {
        $script = "'self'";
        $style = "'self' 'unsafe-inline'";
        $connect = "'self'";

        if (app()->environment('local')) {
            // Vite dev server (HMR) — outra origem; libera só no ambiente local.
            $vite = 'http://localhost:5173 http://[::1]:5173';
            $viteWs = 'ws://localhost:5173 ws://[::1]:5173';
            $script .= " {$vite} 'unsafe-inline'";
            $style .= " {$vite}";
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
