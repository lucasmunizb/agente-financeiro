<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Recorrencia\CancelarRecorrencia;
use App\Events\PendenteRecorrenteRejeitado;

/**
 * Cascata "rejeitar → cancela a recorrência" (spec 10, C7 — decisão do usuário: rejeitar uma
 * ocorrência é "não quero mais isto"). Cancela a recorrência que produziu o pendente, escopada
 * pelo próprio usuário do pendente. Idempotente por baixo ({@see CancelarRecorrencia} devolve
 * false se já cancelada), então é seguro rodar síncrono na mesma transação do "não".
 */
class CancelarRecorrenciaAoRejeitar
{
    public function __construct(
        private readonly CancelarRecorrencia $cancelar = new CancelarRecorrencia,
    ) {}

    public function handle(PendenteRecorrenteRejeitado $evento): void
    {
        $pendente = $evento->pendente;

        if ($pendente->recurrence_id === null) {
            return;
        }

        $this->cancelar->cancelar($pendente->recurrence_id, $pendente->user_id, $evento->agora);
    }
}
