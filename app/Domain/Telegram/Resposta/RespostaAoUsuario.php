<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Resposta;

use App\Models\User;

/**
 * Porta de saída do processamento do bot: recebe o {@see ResultadoDaInteracao} já
 * calculado e o entrega ao usuário. É a costura entre o backend (esta etapa) e o
 * frontend (regra 3): a redação das mensagens curtas do bot e o envio à API do Telegram
 * são a implementação de frontend, posterior. Até lá, a {@see RespostaInerte} mantém o
 * fluxo completo e testável sem efeito colateral externo.
 */
interface RespostaAoUsuario
{
    public function entregar(User $user, ResultadoDaInteracao $resultado): void;
}
