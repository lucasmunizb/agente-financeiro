<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Saida;

/**
 * Contrato de saída para a Bot API do Telegram (frontend do bot — regra 3). O
 * domínio conhece só estes verbos; a implementação HTTP fala com o Telegram e o
 * fake registra as chamadas nos testes. Sem botões inline (doc 06 §2): a única
 * exceção é o teclado nativo `request_contact`, usado no vínculo para o usuário
 * compartilhar o próprio telefone.
 */
interface ClienteTelegram
{
    /** Mensagem curta de texto; opcionalmente remove o teclado de contato. */
    public function enviarMensagem(int $chatId, string $texto, bool $removerTeclado = false): void;

    /** Pede o telefone via teclado nativo request_contact (uso único). */
    public function pedirContato(int $chatId, string $texto, string $rotuloBotao): void;

    /**
     * Indicador efêmero de status no chat (sendChatAction), ex.: "digitando…" enquanto
     * a mensagem é processada. Expira sozinho em ~5s no Telegram e some quando a resposta
     * chega — feedback puro, sem persistência.
     */
    public function enviarAcao(int $chatId, string $acao = 'typing'): void;
}
