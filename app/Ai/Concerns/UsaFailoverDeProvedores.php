<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

/**
 * Resiliência de provedores para um agente da Laravel AI SDK (doc 02 §3.6 / regra
 * inviolável 8): expõe a lista de failover (config `ai.failover`) em provider() — a SDK
 * tenta os provedores em ordem e cai no próximo em caso de indisponibilidade — e um
 * timeout curto por request (config `ai.request_timeout`), para que um provedor pendurado
 * falhe rápido e o failover mantenha a resposta ao usuário quase instantânea.
 */
trait UsaFailoverDeProvedores
{
    /**
     * @return array<int, string>
     */
    public function provider(): array
    {
        return config('ai.failover');
    }

    /**
     * Timeout HTTP (segundos) por chamada de provedor. Curto de propósito: ver
     * config `ai.request_timeout`.
     */
    public function timeout(): int
    {
        return (int) config('ai.request_timeout', 8);
    }
}
