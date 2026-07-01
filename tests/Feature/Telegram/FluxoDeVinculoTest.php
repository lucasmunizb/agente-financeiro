<?php

use App\Domain\Telegram\AutenticarTelegram;
use App\Domain\Telegram\FluxoDeVinculo;
use App\Domain\Telegram\GerarTokenDeVinculo;
use App\Domain\Telegram\Saida\ClienteTelegramFake;
use App\Domain\Telegram\VincularTelegram;
use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Fluxo de vínculo via bot (doc 06 §1, passos 2–4 · frontend regra 3): o usuário
 * envia o token (deep link `/start <token>`), o bot pede o telefone via
 * request_contact e, ao receber o contato, confirma o vínculo. Testado ponta a
 * ponta com o ClienteTelegramFake — sem tocar a rede.
 */

uses(RefreshDatabase::class);

const TG_ID = 999001;

function fluxo(ClienteTelegramFake $cliente): FluxoDeVinculo
{
    return new FluxoDeVinculo(new VincularTelegram, $cliente);
}

function updateTexto(string $texto, int $tgId = TG_ID): array
{
    return ['message' => [
        'from' => ['id' => $tgId],
        'chat' => ['id' => $tgId],
        'text' => $texto,
    ]];
}

function updateContato(string $telefone, ?int $contactUserId = TG_ID, int $tgId = TG_ID): array
{
    return ['message' => [
        'from' => ['id' => $tgId],
        'chat' => ['id' => $tgId],
        'contact' => array_filter([
            'phone_number' => $telefone,
            'user_id' => $contactUserId,
        ], fn ($v) => $v !== null),
    ]];
}

it('ao receber /start com token válido, pede o contato (ainda não vincula)', function () {
    $user = User::factory()->create();
    $token = (new GerarTokenDeVinculo)->para($user->id);
    $cliente = new ClienteTelegramFake;

    fluxo($cliente)->tratar(TG_ID, updateTexto("/start {$token->token}"));

    expect($cliente->pedidosDeContato)->toHaveCount(1)
        ->and($cliente->pedidosDeContato[0]['chatId'])->toBe(TG_ID)
        ->and(TelegramLink::where('user_id', $user->id)->value('status'))->toBe(TelegramLink::PENDENTE);
});

it('ao receber o contato próprio, conclui o vínculo e remove o teclado', function () {
    $user = User::factory()->create();
    $token = (new GerarTokenDeVinculo)->para($user->id);
    $cliente = new ClienteTelegramFake;
    $f = fluxo($cliente);

    $f->tratar(TG_ID, updateTexto("/start {$token->token}"));
    $f->tratar(TG_ID, updateContato('+5511999998888'));

    expect((new AutenticarTelegram)->resolver(TG_ID)?->id)->toBe($user->id)
        ->and(TelegramLink::where('user_id', $user->id)->value('telefone'))->toBe('+5511999998888')
        ->and($cliente->ultimaMensagem()['removerTeclado'])->toBeTrue();
});

it('aceita um token cru (sem /start) enviado direto ao bot', function () {
    $user = User::factory()->create();
    $token = (new GerarTokenDeVinculo)->para($user->id);
    $cliente = new ClienteTelegramFake;

    fluxo($cliente)->tratar(TG_ID, updateTexto($token->token));

    expect($cliente->pedidosDeContato)->toHaveCount(1);
});

it('recusa /start com token inválido: avisa e não pede contato', function () {
    $cliente = new ClienteTelegramFake;

    fluxo($cliente)->tratar(TG_ID, updateTexto('/start naoexiste'));

    expect($cliente->pedidosDeContato)->toBeEmpty()
        ->and($cliente->mensagens)->toHaveCount(1)
        ->and($cliente->ultimaMensagem()['texto'])->toContain('código');
});

it('recusa contato quando nenhum token foi iniciado', function () {
    $cliente = new ClienteTelegramFake;

    fluxo($cliente)->tratar(TG_ID, updateContato('+5511999998888'));

    expect((new AutenticarTelegram)->resolver(TG_ID))->toBeNull()
        ->and($cliente->mensagens)->toHaveCount(1);
});

it('recusa contato de outra pessoa (user_id divergente)', function () {
    $user = User::factory()->create();
    $token = (new GerarTokenDeVinculo)->para($user->id);
    $cliente = new ClienteTelegramFake;
    $f = fluxo($cliente);

    $f->tratar(TG_ID, updateTexto("/start {$token->token}"));
    $f->tratar(TG_ID, updateContato('+5511777776666', contactUserId: 424242));

    expect((new AutenticarTelegram)->resolver(TG_ID))->toBeNull();
});

it('orienta quando um não vinculado manda texto que não é token', function () {
    $cliente = new ClienteTelegramFake;

    fluxo($cliente)->tratar(TG_ID, updateTexto('oi, quanto gastei?'));

    expect($cliente->pedidosDeContato)->toBeEmpty()
        ->and($cliente->mensagens)->toHaveCount(1)
        ->and($cliente->ultimaMensagem()['texto'])->toContain('código');
});
