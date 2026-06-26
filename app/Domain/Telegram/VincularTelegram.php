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

            return $link;
        });
    }
}
