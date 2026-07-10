<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Resposta;

use App\Models\User;

/**
 * Implementação inerte da porta de saída. A apresentação das mensagens do bot (redação
 * curta + envio à API do Telegram) é etapa de frontend (regra 3): até ela existir, o
 * resultado é calculado e validado normalmente, mas não há entrega externa. Mantém o
 * pipeline Blocos 4/5 ligado e testável de ponta a ponta sem tocar a rede.
 */
final class RespostaInerte implements RespostaAoUsuario
{
    public function entregar(User $user, ResultadoDaInteracao $resultado): void
    {
        // no-op até o frontend do bot (mensagens + envio ao Telegram).
    }

    public function sinalizarProcessando(User $user): void
    {
        // no-op: sem canal de saída, não há indicador de "digitando…".
    }
}
