<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Models\User;

/**
 * Ponto de extensão do webhook (doc 06 §2). O webhook resolve segredo, dedupe e
 * autenticação e delega o que fazer com a mensagem ao roteador. O roteamento de
 * comandos (registrar/editar/cancelar/buscar) e a redação das respostas do bot
 * são etapas posteriores; a implementação default é inerte.
 */
interface RoteadorDeMensagem
{
    /** Mensagem de um telegram_user_id com vínculo ativo. */
    public function autenticado(User $user, array $update): void;

    /** Mensagem de um telegram_user_id sem vínculo ativo (candidato a vínculo). */
    public function naoVinculado(int $telegramUserId, array $update): void;
}
