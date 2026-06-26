<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Models\User;

/**
 * Roteador determinístico de comandos (doc 06 §2). Para o usuário autenticado,
 * classifica a mensagem (ClassificadorDeComando) e a despacha
 * ao ManipuladorDeComando registrado para a intenção; sem manipulador específico,
 * usa o padrão. Adaptador fino — não contém regra financeira; a execução de cada
 * comando vive nos manipuladores (etapas posteriores).
 *
 * naoVinculado (fluxo de vínculo via bot: token + request_contact) é etapa de
 * frontend posterior (doc 06 §1) e permanece inerte aqui.
 */
final class RoteadorDeComandos implements RoteadorDeMensagem
{
    /**
     * @param  array<string, ManipuladorDeComando>  $manipuladores  intenção (Comando->value) → manipulador
     */
    public function __construct(
        private ClassificadorDeComando $classificador,
        private ManipuladorDeComando $padrao,
        private array $manipuladores = [],
    ) {}

    public function autenticado(User $user, array $update): void
    {
        $texto = (string) ($update['message']['text'] ?? '');

        $comando = $this->classificador->classificar($texto);

        $manipulador = $this->manipuladores[$comando->comando->value] ?? $this->padrao;

        $manipulador->manipular($user, $comando);
    }

    public function naoVinculado(int $telegramUserId, array $update): void
    {
        // Vínculo via bot é etapa de frontend posterior (doc 06 §1): no-op por ora.
    }
}
