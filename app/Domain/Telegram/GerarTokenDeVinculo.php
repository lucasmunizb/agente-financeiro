<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Domain\Calendar\RelativeDate;
use App\Models\TelegramLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Geração do token de vínculo (doc 06 §1, passo 1). A web pede um token único,
 * aleatório e de expiração curta. Persistimos apenas o HASH (sha-256) numa linha
 * `pendente`; o token em claro vai só para o usuário. Uma pendência por conta:
 * gerar de novo revoga a pendência anterior.
 */
final class GerarTokenDeVinculo
{
    /** Validade do token (minutos). */
    public const EXPIRACAO_MINUTOS = 15;

    private const TAMANHO = 32;

    public function para(int $userId, ?CarbonImmutable $agora = null): TokenDeVinculo
    {
        $agora ??= CarbonImmutable::now(RelativeDate::TIMEZONE);
        $expiraEm = $agora->addMinutes(self::EXPIRACAO_MINUTOS);

        TelegramLink::where('user_id', $userId)
            ->where('status', TelegramLink::PENDENTE)
            ->update(['status' => TelegramLink::REVOGADO, 'token_hash' => null]);

        $token = Str::random(self::TAMANHO);

        TelegramLink::create([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            // Persistido em UTC (app.timezone=UTC): preserva o instante absoluto.
            'token_expira_em' => $expiraEm->setTimezone('UTC'),
            'status' => TelegramLink::PENDENTE,
        ]);

        return new TokenDeVinculo($token, $expiraEm);
    }
}
