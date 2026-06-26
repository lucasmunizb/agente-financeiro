<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida o segredo do webhook do Telegram (doc 06 §1/§3). O Telegram envia o
 * valor configurado em setWebhook no header X-Telegram-Bot-Api-Secret-Token.
 * Sem segredo configurado, ou com segredo divergente, recusa com 403 — nenhuma
 * entrada não autenticada chega ao controller. Comparação em tempo constante.
 */
final class VerificaSegredoTelegram
{
    public function handle(Request $request, Closure $next): Response
    {
        $esperado = (string) config('services.telegram.webhook_secret');
        $recebido = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        abort_if($esperado === '' || ! hash_equals($esperado, $recebido), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
