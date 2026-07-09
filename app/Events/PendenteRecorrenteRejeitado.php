<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PendingConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Disparado quando o usuário REJEITA uma confirmação pendente que veio de uma recorrência
 * (spec 10, C7). Mantém o domínio de Confirmação desacoplado do de Recorrência: a fila só
 * anuncia o "não"; quem reage (cancelando a recorrência) é o listener do lado da recorrência.
 * `agora` é injetado para preservar o determinismo do cancelamento.
 */
final class PendenteRecorrenteRejeitado
{
    use Dispatchable;

    public function __construct(
        public readonly PendingConfirmation $pendente,
        public readonly CarbonImmutable $agora,
    ) {}
}
