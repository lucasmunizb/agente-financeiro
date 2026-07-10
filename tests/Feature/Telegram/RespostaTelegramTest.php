<?php

declare(strict_types=1);

use App\Domain\Chat\RedatorDoChat;
use App\Domain\IA\Consulta\RespostaDaConsulta;
use App\Domain\Telegram\Resposta\RespostaTelegram;
use App\Domain\Telegram\Resposta\ResultadoDaInteracao;
use App\Domain\Telegram\Saida\ClienteTelegramFake;
use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Saída do bot (frontend, regra 3): a porta RespostaAoUsuario que entrega ao Telegram o
 * ResultadoDaInteracao já calculado pelo backend. Redige o texto curto (reaproveitando o
 * RedatorDoChat — mesma apresentação determinística do chat web, a IA nunca calcula
 * dinheiro) e envia via ClienteTelegram ao chat do vínculo ATIVO do usuário. Sem vínculo
 * ativo, nada é enviado. Determinístico via ClienteTelegramFake.
 */

uses(RefreshDatabase::class);

function linkAtivo(User $user, int $telegramUserId): TelegramLink
{
    return TelegramLink::create([
        'user_id' => $user->id,
        'telegram_user_id' => $telegramUserId,
        'telefone' => '+5531999999999',
        'status' => TelegramLink::ATIVO,
        'vinculado_em' => now(),
    ]);
}

function entregarPeloBot(User $user, ResultadoDaInteracao $resultado): ClienteTelegramFake
{
    $cliente = new ClienteTelegramFake;
    (new RespostaTelegram(new RedatorDoChat, $cliente))->entregar($user, $resultado);

    return $cliente;
}

it('envia a resposta redigida ao chat do vínculo ativo do usuário', function () {
    $user = User::factory()->create();
    linkAtivo($user, 999);

    $cliente = entregarPeloBot($user, ResultadoDaInteracao::consulta(
        new RespostaDaConsulta('Você gastou R$ 60,00 com futebol.', true, [], 1),
    ));

    expect($cliente->mensagens)->toHaveCount(1)
        ->and($cliente->ultimaMensagem()['chatId'])->toBe(999)
        ->and($cliente->ultimaMensagem()['texto'])->toBe('Você gastou R$ 60,00 com futebol.');
});

it('não envia nada quando o usuário não tem vínculo ativo', function () {
    $user = User::factory()->create();
    TelegramLink::create([
        'user_id' => $user->id,
        'telegram_user_id' => 111,
        'status' => TelegramLink::REVOGADO,
    ]);

    $cliente = entregarPeloBot($user, ResultadoDaInteracao::naoEntendi());

    expect($cliente->mensagens)->toBe([]);
});

it('entrega ao vínculo ATIVO, ignorando os revogados do mesmo usuário', function () {
    $user = User::factory()->create();
    TelegramLink::create([
        'user_id' => $user->id,
        'telegram_user_id' => 111,
        'status' => TelegramLink::REVOGADO,
    ]);
    linkAtivo($user, 222);

    $cliente = entregarPeloBot($user, ResultadoDaInteracao::naoEntendi());

    expect($cliente->ultimaMensagem()['chatId'])->toBe(222);
});

it('não vaza entre usuários: usa o vínculo do próprio usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    linkAtivo($user, 100);
    linkAtivo($outro, 200);

    $cliente = entregarPeloBot($user, ResultadoDaInteracao::naoEntendi());

    expect($cliente->ultimaMensagem()['chatId'])->toBe(100);
});

/* -------- indicador "digitando…" (sendChatAction) antes de processar -------- */

it('sinaliza "digitando…" ao chat do vínculo ativo', function () {
    $user = User::factory()->create();
    linkAtivo($user, 999);

    $cliente = new ClienteTelegramFake;
    (new RespostaTelegram(new RedatorDoChat, $cliente))->sinalizarProcessando($user);

    expect($cliente->acoes)->toBe([['chatId' => 999, 'acao' => 'typing']]);
});

it('não sinaliza "digitando…" sem vínculo ativo', function () {
    $user = User::factory()->create();

    $cliente = new ClienteTelegramFake;
    (new RespostaTelegram(new RedatorDoChat, $cliente))->sinalizarProcessando($user);

    expect($cliente->acoes)->toBe([]);
});
