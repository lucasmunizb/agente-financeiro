<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Resposta;

use App\Domain\Chat\RedatorDoChat;
use App\Domain\Telegram\Saida\ClienteTelegram;
use App\Models\TelegramLink;
use App\Models\User;

/**
 * Saída real do bot (frontend, regra 3): entrega ao Telegram o {@see ResultadoDaInteracao}
 * já calculado pelo backend (Blocos 4/5). Substitui a {@see RespostaInerte}.
 *
 * A redação é DETERMINÍSTICA e reaproveita o {@see RedatorDoChat} — a mesma apresentação
 * curta em pt-BR do chat web, que só transcreve valores já calculados (a IA nunca calcula
 * dinheiro, regra 4). O destino é o `telegram_user_id` do vínculo ATIVO do usuário (em chat
 * privado, chat_id = telegram_user_id); sem vínculo ativo, nada é enviado. Escopo estrito
 * por usuário: o chat vem do vínculo do próprio usuário, nunca de terceiros.
 */
final class RespostaTelegram implements RespostaAoUsuario
{
    public function __construct(
        private readonly RedatorDoChat $redator,
        private readonly ClienteTelegram $cliente,
    ) {}

    public function entregar(User $user, ResultadoDaInteracao $resultado): void
    {
        $link = $this->linkAtivo($user);

        if ($link === null) {
            return;
        }

        $texto = $this->redator->redigir($resultado)->texto;

        $this->cliente->enviarMensagem((int) $link->telegram_user_id, $texto);
    }

    public function sinalizarProcessando(User $user): void
    {
        $link = $this->linkAtivo($user);

        if ($link === null) {
            return;
        }

        // "digitando…" — feedback imediato de que a mensagem está sendo processada. O
        // Telegram o descarta sozinho quando a resposta chega (ou em ~5s).
        $this->cliente->enviarAcao((int) $link->telegram_user_id);
    }

    /** Vínculo ATIVO (com chat) do próprio usuário — escopo estrito, nunca de terceiros. */
    private function linkAtivo(User $user): ?TelegramLink
    {
        $link = TelegramLink::query()
            ->where('user_id', $user->id)
            ->where('status', TelegramLink::ATIVO)
            ->first();

        return $link !== null && $link->telegram_user_id !== null ? $link : null;
    }
}
