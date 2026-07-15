<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consentimento LGPD obrigatório (auditoria P1-7 / doc 09). O aceite registrado no
 * onboarding (aceite_lgpd_em) é a base do tratamento de dados: sem ele, nenhuma rota
 * do app (inclusive a IA sobre dados financeiros) pode ser usada. Quem ainda não
 * consentiu é levado ao onboarding; chamadas JSON recebem 403 (um redirect de HTML
 * quebraria o fetch silenciosamente).
 */
final class ExigeConsentimentoLgpd
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->aceite_lgpd_em === null) {
            abort_if($request->expectsJson(), Response::HTTP_FORBIDDEN, 'Consentimento LGPD pendente.');

            return redirect()->route('onboarding');
        }

        return $next($request);
    }
}
