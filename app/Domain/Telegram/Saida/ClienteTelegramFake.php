<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Saida;

/**
 * Fake do cliente de saída para os testes: registra o que teria sido enviado ao
 * Telegram, sem tocar a rede. Espelha o padrão dos fakes da SDK — o fluxo de
 * vínculo é testado ponta a ponta de forma determinística inspecionando estas
 * listas.
 */
final class ClienteTelegramFake implements ClienteTelegram
{
    /** @var list<array{chatId:int, texto:string, removerTeclado:bool}> */
    public array $mensagens = [];

    /** @var list<array{chatId:int, texto:string, rotulo:string}> */
    public array $pedidosDeContato = [];

    public function enviarMensagem(int $chatId, string $texto, bool $removerTeclado = false): void
    {
        $this->mensagens[] = ['chatId' => $chatId, 'texto' => $texto, 'removerTeclado' => $removerTeclado];
    }

    public function pedirContato(int $chatId, string $texto, string $rotuloBotao): void
    {
        $this->pedidosDeContato[] = ['chatId' => $chatId, 'texto' => $texto, 'rotulo' => $rotuloBotao];
    }

    /** @return array{chatId:int, texto:string, removerTeclado:bool}|null */
    public function ultimaMensagem(): ?array
    {
        return $this->mensagens === [] ? null : $this->mensagens[array_key_last($this->mensagens)];
    }
}
