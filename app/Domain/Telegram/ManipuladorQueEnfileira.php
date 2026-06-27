<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Domain\IA\Intencao;
use App\Jobs\ProcessarMensagemDoBot;
use App\Models\User;

/**
 * Manipulador que liga o roteamento determinístico aos Blocos 4/5 sem rodar IA no ciclo
 * do webhook (barreira §4: processamento pesado fica fora do request, no worker). Apenas
 * ENFILEIRA {@see ProcessarMensagemDoBot}, carregando o usuário, o comando recebido e,
 * quando o slash já fixa a intenção (`/registrar` → REGISTRAR, `/buscar` → CONSULTAR), a
 * intenção forçada. Para texto livre, vai sem intenção: a classificação por IA acontece
 * no worker. A mesma classe serve às três entradas, variando só a intenção forçada.
 */
final class ManipuladorQueEnfileira implements ManipuladorDeComando
{
    public function __construct(
        private readonly ?Intencao $intencaoForcada = null,
    ) {}

    public function manipular(User $user, ComandoRecebido $comando): void
    {
        ProcessarMensagemDoBot::dispatch($user->id, $comando, $this->intencaoForcada);
    }
}
