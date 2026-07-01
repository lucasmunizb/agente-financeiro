<?php

use App\Domain\Telegram\AutenticarTelegram;
use App\Domain\Telegram\GerarTokenDeVinculo;
use App\Domain\Telegram\TokenDeVinculo;
use App\Domain\Telegram\TokenInvalidoException;
use App\Domain\Telegram\VincularTelegram;
use App\Domain\Telegram\VinculoInexistenteException;
use App\Models\TelegramLink;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Vínculo seguro do Telegram (doc 06 §1): web gera token único de expiração
 * curta → usuário envia ao bot → bot valida, captura telegram_user_id + telefone
 * → sistema vincula e consome o token. Um vínculo ativo por conta. Autenticação
 * resolve telegram_user_id → user; recusa sem vínculo ativo.
 */

uses(RefreshDatabase::class);

/* -------- geração do token (web) -------- */

it('gera um token único e cria vínculo pendente com hash e expiração', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-06-26 12:00:00', 'America/Sao_Paulo');

    $token = (new GerarTokenDeVinculo)->para($user->id, $agora);

    $link = TelegramLink::where('user_id', $user->id)->first();

    expect($token)->toBeInstanceOf(TokenDeVinculo::class)
        ->and($token->token)->toHaveLength(32)
        ->and($link->status)->toBe(TelegramLink::PENDENTE)
        ->and($link->token_hash)->toBe(hash('sha256', $token->token))
        ->and($link->telegram_user_id)->toBeNull()
        // persistido como instante UTC; 12:15 SP == 15:15 UTC
        ->and($link->token_expira_em->equalTo($agora->addMinutes(15)))->toBeTrue();
});

it('o token em claro nunca é persistido', function () {
    $user = User::factory()->create();

    $token = (new GerarTokenDeVinculo)->para($user->id);

    expect(TelegramLink::where('token_hash', $token->token)->exists())->toBeFalse();
});

it('gerar novamente substitui a pendência anterior (uma pendência por conta)', function () {
    $user = User::factory()->create();
    $svc = new GerarTokenDeVinculo;

    $svc->para($user->id);
    $svc->para($user->id);

    expect(TelegramLink::where('user_id', $user->id)->where('status', TelegramLink::PENDENTE)->count())->toBe(1);
});

/* -------- vínculo (bot) -------- */

it('vincula capturando telegram_user_id + telefone e consome o token', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-06-26 12:00:00', 'America/Sao_Paulo');
    $token = (new GerarTokenDeVinculo)->para($user->id, $agora);

    $link = (new VincularTelegram)->confirmar($token->token, 999001, '+5511999998888', $agora->addMinutes(2));

    expect($link->user_id)->toBe($user->id)
        ->and($link->telegram_user_id)->toBe(999001)
        ->and($link->telefone)->toBe('+5511999998888')
        ->and($link->status)->toBe(TelegramLink::ATIVO)
        ->and($link->vinculado_em)->not->toBeNull()
        ->and($link->token_hash)->toBeNull();
});

it('recusa token inexistente', function () {
    (new VincularTelegram)->confirmar('naoexiste', 1, '+5511999998888');
})->throws(TokenInvalidoException::class);

it('recusa token expirado', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-06-26 12:00:00', 'America/Sao_Paulo');
    $token = (new GerarTokenDeVinculo)->para($user->id, $agora);

    (new VincularTelegram)->confirmar($token->token, 1, '+5511999998888', $agora->addMinutes(16));
})->throws(TokenInvalidoException::class);

it('recusa token já consumido', function () {
    $user = User::factory()->create();
    $svc = new VincularTelegram;
    $token = (new GerarTokenDeVinculo)->para($user->id);

    $svc->confirmar($token->token, 999001, '+5511999998888');
    $svc->confirmar($token->token, 999002, '+5511999997777');
})->throws(TokenInvalidoException::class);

it('garante apenas um vínculo ativo por conta (novo vínculo revoga o anterior)', function () {
    $user = User::factory()->create();

    $t1 = (new GerarTokenDeVinculo)->para($user->id);
    (new VincularTelegram)->confirmar($t1->token, 999001, '+5511999998888');

    $t2 = (new GerarTokenDeVinculo)->para($user->id);
    (new VincularTelegram)->confirmar($t2->token, 999002, '+5511999997777');

    expect(TelegramLink::where('user_id', $user->id)->where('status', TelegramLink::ATIVO)->count())->toBe(1)
        ->and(TelegramLink::where('user_id', $user->id)->where('status', TelegramLink::ATIVO)->value('telegram_user_id'))->toBe(999002);
});

it('impede o mesmo telegram_user_id de vincular a duas contas (índice parcial)', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $tA = (new GerarTokenDeVinculo)->para($userA->id);
    (new VincularTelegram)->confirmar($tA->token, 999001, '+5511999998888');

    // userB tenta vincular o MESMO telegram_user_id, ainda ativo em userA.
    $tB = (new GerarTokenDeVinculo)->para($userB->id);
    expect(fn () => (new VincularTelegram)->confirmar($tB->token, 999001, '+5511999997777'))
        ->toThrow(QueryException::class);

    // userB não fica com vínculo ativo; o telegram_user_id continua resolvendo para userA.
    expect(TelegramLink::where('user_id', $userB->id)->where('status', TelegramLink::ATIVO)->exists())->toBeFalse()
        ->and((new AutenticarTelegram)->usuario(999001)->id)->toBe($userA->id);
});

/* -------- vínculo em dois passos (bot: /start então contato) -------- */

it('iniciar guarda o telegram_user_id no pendente sem ativar ainda', function () {
    $user = User::factory()->create();
    $token = (new GerarTokenDeVinculo)->para($user->id);

    $link = (new VincularTelegram)->iniciar($token->token, 999001);

    expect($link->status)->toBe(TelegramLink::PENDENTE)
        ->and($link->telegram_user_id)->toBe(999001)
        ->and($link->telefone)->toBeNull();
});

it('iniciar recusa token inexistente ou expirado', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-06-26 12:00:00', 'America/Sao_Paulo');
    $token = (new GerarTokenDeVinculo)->para($user->id, $agora);

    expect(fn () => (new VincularTelegram)->iniciar('naoexiste', 1))
        ->toThrow(TokenInvalidoException::class);
    expect(fn () => (new VincularTelegram)->iniciar($token->token, 1, $agora->addMinutes(16)))
        ->toThrow(TokenInvalidoException::class);
});

it('iniciar de novo desvincula o telegram_user_id do pendente anterior (um por vez)', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $svc = new VincularTelegram;

    $tA = (new GerarTokenDeVinculo)->para($userA->id);
    $svc->iniciar($tA->token, 999001);

    // O mesmo telegram_user_id aponta o código de outra conta antes de confirmar.
    $tB = (new GerarTokenDeVinculo)->para($userB->id);
    $svc->iniciar($tB->token, 999001);

    expect(TelegramLink::where('user_id', $userA->id)->value('telegram_user_id'))->toBeNull()
        ->and(TelegramLink::where('user_id', $userB->id)->value('telegram_user_id'))->toBe(999001);
});

it('finalizar ativa capturando o telefone e consome o token', function () {
    $user = User::factory()->create();
    $svc = new VincularTelegram;
    $token = (new GerarTokenDeVinculo)->para($user->id);
    $svc->iniciar($token->token, 999001);

    $link = $svc->finalizar(999001, '+5511999998888');

    expect($link->user_id)->toBe($user->id)
        ->and($link->status)->toBe(TelegramLink::ATIVO)
        ->and($link->telefone)->toBe('+5511999998888')
        ->and($link->vinculado_em)->not->toBeNull()
        ->and($link->token_hash)->toBeNull();
});

it('finalizar recusa quando não há pendente aguardando aquele telegram_user_id', function () {
    (new VincularTelegram)->finalizar(424242, '+5511999998888');
})->throws(TokenInvalidoException::class);

it('finalizar recusa quando o token expira entre iniciar e finalizar', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-06-26 12:00:00', 'America/Sao_Paulo');
    $svc = new VincularTelegram;
    $token = (new GerarTokenDeVinculo)->para($user->id, $agora);
    $svc->iniciar($token->token, 999001, $agora->addMinutes(1));

    $svc->finalizar(999001, '+5511999998888', $agora->addMinutes(16));
})->throws(TokenInvalidoException::class);

it('finalizar revoga o vínculo ativo anterior da mesma conta', function () {
    $user = User::factory()->create();
    $svc = new VincularTelegram;

    $t1 = (new GerarTokenDeVinculo)->para($user->id);
    $svc->confirmar($t1->token, 999001, '+5511999998888');

    $t2 = (new GerarTokenDeVinculo)->para($user->id);
    $svc->iniciar($t2->token, 999002);
    $svc->finalizar(999002, '+5511999997777');

    expect(TelegramLink::where('user_id', $user->id)->where('status', TelegramLink::ATIVO)->count())->toBe(1)
        ->and((new AutenticarTelegram)->usuario(999002)->id)->toBe($user->id);
});

/* -------- autenticação (middleware) -------- */

it('resolve o usuário a partir do telegram_user_id vinculado', function () {
    $user = User::factory()->create();
    $token = (new GerarTokenDeVinculo)->para($user->id);
    (new VincularTelegram)->confirmar($token->token, 999001, '+5511999998888');

    expect((new AutenticarTelegram)->usuario(999001)->id)->toBe($user->id);
});

it('recusa autenticação sem vínculo ativo', function () {
    (new AutenticarTelegram)->usuario(424242);
})->throws(VinculoInexistenteException::class);
