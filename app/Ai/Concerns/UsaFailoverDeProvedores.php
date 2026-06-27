<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

/**
 * Expõe a lista de provedores de failover (config `ai.failover`) para um agente da Laravel
 * AI SDK. Ao retornar um array em provider(), a SDK tenta os provedores em ordem e cai no
 * próximo em caso de indisponibilidade (doc 02 §3.6 / regra inviolável 8).
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
}
