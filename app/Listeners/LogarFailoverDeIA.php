<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\AgentFailedOver;

/**
 * Registra a indisponibilidade de um provedor de IA quando a SDK faz failover para o
 * próximo (doc 02 §3.6). Log estruturado, SEM dado sensível: só agente, provedor, modelo
 * e a CLASSE da exceção — nunca a mensagem (pode carregar payload) nem texto do usuário.
 */
class LogarFailoverDeIA
{
    public function handle(AgentFailedOver $evento): void
    {
        Log::warning('IA: failover de provedor', [
            'agente' => $evento->agent::class,
            'provedor' => $evento->provider->name(),
            'modelo' => $evento->model,
            'excecao' => $evento->exception::class,
        ]);
    }
}
