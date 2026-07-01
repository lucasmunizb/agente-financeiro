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
 * naoVinculado delega ao {@see FluxoDeVinculo} (token + request_contact, doc 06 §1);
 * sem fluxo injetado, permanece inerte.
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
        private ?FluxoDeVinculo $vinculo = null,
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
        // Candidato a vínculo: token via /start + telefone via request_contact (doc 06 §1).
        $this->vinculo?->tratar($telegramUserId, $update);
    }
}
