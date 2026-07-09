<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\IA\Rotacao\RotacionadorDeProvedores;
use Laravel\Ai\Events\AgentFailedOver;

/**
 * Aplica cooldown ao provedor que caiu quando a SDK faz failover (spec 04c, C7).
 *
 * Complementa o {@see LogarFailoverDeIA} (que loga o evento) sem substituí-lo: aqui só
 * penalizamos o provedor na fila de rotação. Passa a CLASSE da exceção como motivo —
 * nunca a mensagem (pode carregar payload; o rotacionador tampouco a persiste). Só age
 * com a rotação ligada (`ai.rotacao.enabled`), mantendo a spec 04 intacta quando desligada.
 */
class PenalizarProvedorNaRotacao
{
    public function handle(AgentFailedOver $evento): void
    {
        if (! config('ai.rotacao.enabled')) {
            return;
        }

        app(RotacionadorDeProvedores::class)->penalizar(
            $evento->provider->name(),
            $evento->exception::class,
        );
    }
}
