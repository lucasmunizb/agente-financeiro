<?php

declare(strict_types=1);

namespace App\Domain\IA\Historico;

use Carbon\CarbonImmutable;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Expurgo do histórico de conversa (doc 02 §3.6): conversas com mais de 60 dias — e todas
 * as suas mensagens — são apagadas. O histórico do `RemembersConversations` (Laravel AI
 * SDK) serve a contexto, completar mensagens incompletas e auditoria; passados 60 dias,
 * deixa de ser retido (NFR/LGPD — doc 09).
 *
 * "Agora" é INJETADO (determinismo, regra inviolável 4/5): a borda do corte não depende do
 * relógio do processo. Mantém o que tiver EXATAMENTE 60 dias; só apaga o que for mais
 * antigo que isso.
 */
final class ExpurgarConversas
{
    /** Janela de retenção, em dias. */
    public const DIAS_DE_RETENCAO = 60;

    /**
     * Apaga conversas (e suas mensagens) mais antigas que a janela de retenção, contadas a
     * partir de `$agora`. Devolve quantas conversas foram apagadas.
     */
    public function executar(CarbonImmutable $agora): int
    {
        $corte = $agora->subDays(self::DIAS_DE_RETENCAO);

        $ids = Conversation::query()
            ->where('updated_at', '<', $corte)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        ConversationMessage::query()->whereIn('conversation_id', $ids)->delete();
        Conversation::query()->whereIn('id', $ids)->delete();

        return $ids->count();
    }
}
