<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use App\Domain\IA\Rotacao\RotacionadorDeProvedores;

/**
 * Resiliência de provedores para um agente da Laravel AI SDK (doc 02 §3.6 / regra
 * inviolável 8): expõe a lista de failover (config `ai.failover`) em provider() — a SDK
 * tenta os provedores em ordem e cai no próximo em caso de indisponibilidade — e um
 * timeout curto por request (config `ai.request_timeout`), para que um provedor pendurado
 * falhe rápido e o failover mantenha a resposta ao usuário quase instantânea.
 *
 * Com a rotação ligada (`ai.rotacao.enabled`, spec 04c), provider() devolve a ordem
 * ROTACIONADA (cabeça = escolha LRU; cauda = cadeia de failover); a SDK segue fazendo o
 * failover nativo sobre essa lista. Desligada, mantém o comportamento estático da spec 04.
 */
trait UsaFailoverDeProvedores
{
    /**
     * @return array<int, string>
     */
    public function provider(): array
    {
        return config('ai.rotacao.enabled')
            ? app(RotacionadorDeProvedores::class)->ordenar()
            : config('ai.failover');
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
