<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Telegram\AutenticarTelegram;
use App\Domain\Telegram\DedupeDeUpdate;
use App\Domain\Telegram\RoteadorDeMensagem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook do Telegram (doc 06 §3). Adaptador fino: NÃO contém regra de negócio.
 * Orquestra segredo (middleware) → dedupe (update_id) → autenticação
 * (telegram_user_id → vínculo ativo) e delega ao RoteadorDeMensagem. Sempre
 * responde 200 ao Telegram para evitar reentregas em loop; processamento pesado
 * (IA/PDF) e redação de respostas ficam fora do request (worker / frontend).
 */
final class TelegramWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        DedupeDeUpdate $dedupe,
        AutenticarTelegram $auth,
        RoteadorDeMensagem $roteador,
    ): Response {
        $updateId = $request->integer('update_id');

        // Sem update_id não há o que deduplicar nem rotear: ignora.
        if ($updateId === 0) {
            return $this->ok();
        }

        // Mantém a primeira entrega; reentregas (mesmo update_id) param aqui.
        if (! $dedupe->ehNovo($updateId)) {
            return $this->ok();
        }

        $from = $request->input('message.from.id');

        // Updates sem mensagem/remetente (edited_message, callbacks, etc.) são
        // registrados para dedupe, mas não roteados nesta etapa.
        if ($from === null) {
            return $this->ok();
        }

        $telegramUserId = (int) $from;
        $update = $request->all();

        if ($user = $auth->resolver($telegramUserId)) {
            $roteador->autenticado($user, $update);
        } else {
            $roteador->naoVinculado($telegramUserId, $update);
        }

        return $this->ok();
    }

    private function ok(): Response
    {
        return new Response('', Response::HTTP_OK);
    }
}
