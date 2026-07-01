<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Saida;

use Illuminate\Support\Facades\Http;

/**
 * Implementação HTTP do cliente de saída: fala com a Bot API do Telegram
 * (api.telegram.org/bot<token>/<metodo>). Texto puro (sem parse_mode) para
 * robustez; o teclado request_contact é o único markup usado (vínculo). Lança em
 * resposta de erro (->throw()) para o chamador tratar/reenfileirar.
 */
final class ClienteTelegramHttp implements ClienteTelegram
{
    public function __construct(private readonly string $token) {}

    public function enviarMensagem(int $chatId, string $texto, bool $removerTeclado = false): void
    {
        $params = ['chat_id' => $chatId, 'text' => $texto];

        if ($removerTeclado) {
            $params['reply_markup'] = ['remove_keyboard' => true];
        }

        $this->chamar('sendMessage', $params);
    }

    public function pedirContato(int $chatId, string $texto, string $rotuloBotao): void
    {
        $this->chamar('sendMessage', [
            'chat_id' => $chatId,
            'text' => $texto,
            'reply_markup' => [
                'keyboard' => [[['text' => $rotuloBotao, 'request_contact' => true]]],
                'one_time_keyboard' => true,
                'resize_keyboard' => true,
            ],
        ]);
    }

    /** @param  array<string, mixed>  $params */
    private function chamar(string $metodo, array $params): void
    {
        Http::asJson()
            ->post("https://api.telegram.org/bot{$this->token}/{$metodo}", $params)
            ->throw();
    }
}
