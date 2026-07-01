<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Domain\Calendar\RelativeDate;
use App\Models\TelegramLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Consumo do token e vínculo (doc 06 §1, passos 3–4). Valida o token (hash,
 * pendente, não expirado), captura telegram_user_id + telefone, ativa o vínculo
 * e consome o token (hash nulo). Garante UM vínculo ativo por conta: o novo
 * vínculo revoga qualquer ativo anterior da mesma conta. Atômico.
 */
final class VincularTelegram
{
    public function confirmar(
        string $token,
        int $telegramUserId,
        string $telefone,
        ?CarbonImmutable $agora = null,
    ): TelegramLink {
        $agora ??= CarbonImmutable::now(RelativeDate::TIMEZONE);

        return DB::transaction(function () use ($token, $telegramUserId, $telefone, $agora) {
            $link = TelegramLink::where('token_hash', hash('sha256', $token))
                ->where('status', TelegramLink::PENDENTE)
                ->lockForUpdate()
                ->first();

            if ($link === null || $link->token_expira_em === null || $agora->greaterThan($link->token_expira_em)) {
                throw TokenInvalidoException::invalido();
            }

            $this->ativar($link, $telegramUserId, $telefone, $agora);

            return $link;
        });
    }

    /**
     * Passo 1 do vínculo via bot (doc 06 §1, passo 3): o `/start <token>` valida o
     * token e captura o `telegram_user_id`, mas o vínculo só ativa quando o telefone
     * chega (request_contact). Guarda o `telegram_user_id` no pendente sem ativar e
     * garante que só um pendente aguarda cada `telegram_user_id` (o novo /start vence).
     */
    public function iniciar(
        string $token,
        int $telegramUserId,
        ?CarbonImmutable $agora = null,
    ): TelegramLink {
        $agora ??= CarbonImmutable::now(RelativeDate::TIMEZONE);

        return DB::transaction(function () use ($token, $telegramUserId, $agora) {
            $link = TelegramLink::where('token_hash', hash('sha256', $token))
                ->where('status', TelegramLink::PENDENTE)
                ->lockForUpdate()
                ->first();

            if ($link === null || $link->token_expira_em === null || $agora->greaterThan($link->token_expira_em)) {
                throw TokenInvalidoException::invalido();
            }

            // Um pendente por telegram_user_id aguardando contato: solta o vínculo
            // do pendente anterior deste telegram_user_id (evita ambiguidade em finalizar).
            TelegramLink::where('telegram_user_id', $telegramUserId)
                ->where('status', TelegramLink::PENDENTE)
                ->whereKeyNot($link->getKey())
                ->update(['telegram_user_id' => null]);

            $link->update(['telegram_user_id' => $telegramUserId]);

            return $link;
        });
    }

    /**
     * Passo 2 do vínculo via bot (doc 06 §1, passo 4): chega o telefone via
     * request_contact. Localiza o pendente que já capturou este `telegram_user_id`,
     * ainda válido, ativa-o consumindo o token e revoga qualquer ativo anterior da
     * mesma conta. Sem pendente válido (nunca iniciou ou expirou) → token inválido.
     */
    public function finalizar(
        int $telegramUserId,
        string $telefone,
        ?CarbonImmutable $agora = null,
    ): TelegramLink {
        $agora ??= CarbonImmutable::now(RelativeDate::TIMEZONE);

        return DB::transaction(function () use ($telegramUserId, $telefone, $agora) {
            $link = TelegramLink::where('telegram_user_id', $telegramUserId)
                ->where('status', TelegramLink::PENDENTE)
                ->lockForUpdate()
                ->first();

            if ($link === null || $link->token_expira_em === null || $agora->greaterThan($link->token_expira_em)) {
                throw TokenInvalidoException::invalido();
            }

            $this->ativar($link, $telegramUserId, $telefone, $agora);

            return $link;
        });
    }

    /** Ativa o pendente: captura identidade, consome o token e garante um ativo por conta. */
    private function ativar(TelegramLink $link, int $telegramUserId, string $telefone, CarbonImmutable $agora): void
    {
        // Apenas um vínculo ativo por conta.
        TelegramLink::where('user_id', $link->user_id)
            ->where('status', TelegramLink::ATIVO)
            ->update(['status' => TelegramLink::REVOGADO]);

        $link->update([
            'telegram_user_id' => $telegramUserId,
            'telefone' => $telefone,
            'status' => TelegramLink::ATIVO,
            'vinculado_em' => $agora->setTimezone('UTC'),
            'token_hash' => null,
            'token_expira_em' => null,
        ]);
    }
}
