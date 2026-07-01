<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Comando de operação do webhook (doc 06 §3): registra/inspeciona/remove o
 * webhook na Bot API. Passa o segredo (X-Telegram-Bot-Api-Secret-Token) no
 * setWebhook para o Telegram devolvê-lo no header — validado pelo middleware.
 */

beforeEach(function () {
    config()->set('services.telegram.bot_token', 'TOKEN123');
    config()->set('services.telegram.webhook_secret', 'segredo-de-teste');
});

it('registra o webhook com URL e o segredo', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $this->artisan('telegram:webhook', ['url' => 'https://exemplo.test/telegram/webhook'])
        ->assertSuccessful();

    Http::assertSent(function (Request $req) {
        return $req->url() === 'https://api.telegram.org/botTOKEN123/setWebhook'
            && $req['url'] === 'https://exemplo.test/telegram/webhook'
            && $req['secret_token'] === 'segredo-de-teste';
    });
});

it('consulta as informações do webhook com --info', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['url' => 'https://exemplo.test/telegram/webhook']])]);

    $this->artisan('telegram:webhook', ['--info' => true])->assertSuccessful();

    Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/getWebhookInfo'));
});

it('remove o webhook com --delete', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $this->artisan('telegram:webhook', ['--delete' => true])->assertSuccessful();

    Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/deleteWebhook'));
});

it('falha (sem tocar a rede) quando não há bot token configurado', function () {
    config()->set('services.telegram.bot_token', null);
    Http::fake();

    $this->artisan('telegram:webhook', ['url' => 'https://exemplo.test/telegram/webhook'])
        ->assertFailed();

    Http::assertNothingSent();
});

it('falha quando nem URL nem uma ação é informada', function () {
    Http::fake();

    $this->artisan('telegram:webhook')->assertFailed();

    Http::assertNothingSent();
});
