<?php

use App\Domain\Telegram\Saida\ClienteTelegram;
use App\Domain\Telegram\Saida\ClienteTelegramFake;
use App\Domain\Telegram\Saida\ClienteTelegramHttp;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Cliente de saída do Telegram (frontend do bot — regra 3). Costura entre o
 * domínio e a Bot API: envia mensagens curtas e pede o contato via teclado
 * request_contact. O HTTP real fala com api.telegram.org; o fake registra as
 * chamadas para os testes do fluxo de vínculo, sem tocar a rede.
 */

/* -------- ClienteTelegramHttp (Bot API) -------- */

it('envia uma mensagem de texto via sendMessage', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    (new ClienteTelegramHttp('TOKEN123'))->enviarMensagem(555, 'olá');

    Http::assertSent(function (Request $req) {
        return $req->url() === 'https://api.telegram.org/botTOKEN123/sendMessage'
            && $req['chat_id'] === 555
            && $req['text'] === 'olá'
            && ! isset($req['reply_markup']);
    });
});

it('pede o contato com um teclado request_contact de uso único', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    (new ClienteTelegramHttp('TOKEN123'))->pedirContato(555, 'toque abaixo', 'Compartilhar contato');

    Http::assertSent(function (Request $req) {
        $botao = $req['reply_markup']['keyboard'][0][0] ?? [];

        return $req->url() === 'https://api.telegram.org/botTOKEN123/sendMessage'
            && $req['text'] === 'toque abaixo'
            && $botao['text'] === 'Compartilhar contato'
            && $botao['request_contact'] === true
            && $req['reply_markup']['one_time_keyboard'] === true;
    });
});

it('remove o teclado ao enviar quando solicitado', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    (new ClienteTelegramHttp('TOKEN123'))->enviarMensagem(555, 'pronto', removerTeclado: true);

    Http::assertSent(fn (Request $req) => $req['reply_markup']['remove_keyboard'] === true);
});

it('lança quando a Bot API responde com erro', function () {
    Http::fake(['*' => Http::response(['ok' => false, 'description' => 'ruim'], 400)]);

    (new ClienteTelegramHttp('TOKEN123'))->enviarMensagem(555, 'x');
})->throws(Illuminate\Http\Client\RequestException::class);

it('sinaliza "digitando…" via sendChatAction', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    (new ClienteTelegramHttp('TOKEN123'))->enviarAcao(555);

    Http::assertSent(function (Request $req) {
        return $req->url() === 'https://api.telegram.org/botTOKEN123/sendChatAction'
            && $req['chat_id'] === 555
            && $req['action'] === 'typing';
    });
});

/* -------- ClienteTelegramFake (testes) -------- */

it('o fake registra as mensagens e os pedidos de contato', function () {
    $fake = new ClienteTelegramFake;

    $fake->enviarMensagem(555, 'oi');
    $fake->enviarMensagem(555, 'tchau', removerTeclado: true);
    $fake->pedirContato(555, 'compartilhe', 'Contato');

    expect($fake->mensagens)->toHaveCount(2)
        ->and($fake->ultimaMensagem()['texto'])->toBe('tchau')
        ->and($fake->ultimaMensagem()['removerTeclado'])->toBeTrue()
        ->and($fake->pedidosDeContato)->toHaveCount(1)
        ->and($fake->pedidosDeContato[0]['rotulo'])->toBe('Contato');
});

it('o fake registra as ações de chat (digitando…)', function () {
    $fake = new ClienteTelegramFake;

    $fake->enviarAcao(555);

    expect($fake->acoes)->toBe([['chatId' => 555, 'acao' => 'typing']]);
});

it('o fake é injetável como o contrato ClienteTelegram', function () {
    $fake = new ClienteTelegramFake;
    app()->instance(ClienteTelegram::class, $fake);

    app(ClienteTelegram::class)->enviarMensagem(1, 'oi');

    expect($fake->mensagens)->toHaveCount(1);
});
