<?php

use App\Domain\Telegram\GerarTokenDeVinculo;
use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Tela de vínculo com o Telegram (fica atrás do login). O estado (pendente /
 * vinculado) vem do backend real: gerar token, mostrar o código de uso único,
 * gerar novo e desconectar. A apresentação é a mesma (regra 3); aqui garantimos
 * a fiação com o domínio já testado (GerarTokenDeVinculo / VincularTelegram).
 */

uses(RefreshDatabase::class);

it('exige login para ver a tela de vínculo', function () {
    $this->get('/telegram')->assertRedirect(route('login'));
});

it('gera um código de uso único ao abrir sem vínculo (estado pendente)', function () {
    $user = User::factory()->create();

    $resposta = $this->actingAs($user)->get('/telegram');

    $resposta->assertOk()
        ->assertSee('Conectar o Telegram')
        ->assertSee('Código de uso único')
        ->assertSee('noindex', escape: false)
        ->assertSee('start=', escape: false); // deep link com o token

    expect(TelegramLink::where('user_id', $user->id)->where('status', TelegramLink::PENDENTE)->count())->toBe(1)
        ->and(session('telegram.token'))->toHaveLength(32);
});

it('reaproveita o mesmo código ao recarregar (não gera um novo a cada visita)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/telegram');
    $primeiro = session('telegram.token');

    $this->actingAs($user)->get('/telegram');

    expect(session('telegram.token'))->toBe($primeiro)
        ->and(TelegramLink::where('user_id', $user->id)->where('status', TelegramLink::PENDENTE)->count())->toBe(1);
});

it('gerar novo código revoga o anterior e emite outro token', function () {
    $user = User::factory()->create();
    $anterior = (new GerarTokenDeVinculo)->para($user->id);

    $this->actingAs($user)->post('/telegram/gerar')->assertRedirect(route('telegram'));

    // Só uma pendência ativa; o hash do token anterior não vale mais.
    expect(TelegramLink::where('user_id', $user->id)->where('status', TelegramLink::PENDENTE)->count())->toBe(1)
        ->and(TelegramLink::where('token_hash', hash('sha256', $anterior->token))->where('status', TelegramLink::PENDENTE)->exists())->toBeFalse();
});

it('mostra o estado vinculado com o telefone mascarado e a opção de desconectar', function () {
    $user = User::factory()->create();
    TelegramLink::create([
        'user_id' => $user->id,
        'telegram_user_id' => 999001,
        'telefone' => '+5511999998888',
        'status' => TelegramLink::ATIVO,
        'vinculado_em' => now(),
    ]);

    $this->actingAs($user)->get('/telegram')
        ->assertOk()
        ->assertSee('Telegram conectado')
        ->assertSee('Desconectar Telegram')
        ->assertSee('8888')               // últimos dígitos visíveis
        ->assertDontSee('999998888');     // número completo nunca exposto
});

it('expõe o status do vínculo em JSON para o polling da tela', function () {
    $user = User::factory()->create();

    // Sem vínculo ativo → false.
    $this->actingAs($user)->getJson('/telegram/status')
        ->assertOk()->assertExactJson(['vinculado' => false]);

    // Com vínculo ativo → true.
    TelegramLink::create([
        'user_id' => $user->id,
        'telegram_user_id' => 999001,
        'telefone' => '+5511999998888',
        'status' => TelegramLink::ATIVO,
        'vinculado_em' => now(),
    ]);

    $this->actingAs($user)->getJson('/telegram/status')
        ->assertOk()->assertExactJson(['vinculado' => true]);
});

it('o status do vínculo exige autenticação', function () {
    $this->getJson('/telegram/status')->assertUnauthorized();
});

it('o status é isolado por usuário (não vaza vínculo de outra conta)', function () {
    $dono = User::factory()->create();
    TelegramLink::create([
        'user_id' => $dono->id,
        'telegram_user_id' => 999001,
        'telefone' => '+5511999998888',
        'status' => TelegramLink::ATIVO,
        'vinculado_em' => now(),
    ]);

    $outro = User::factory()->create();

    $this->actingAs($outro)->getJson('/telegram/status')
        ->assertOk()->assertExactJson(['vinculado' => false]);
});

it('desconectar revoga o vínculo ativo', function () {
    $user = User::factory()->create();
    TelegramLink::create([
        'user_id' => $user->id,
        'telegram_user_id' => 999001,
        'telefone' => '+5511999998888',
        'status' => TelegramLink::ATIVO,
        'vinculado_em' => now(),
    ]);

    $this->actingAs($user)->post('/telegram/desconectar')->assertRedirect(route('telegram'));

    expect(TelegramLink::where('user_id', $user->id)->where('status', TelegramLink::ATIVO)->exists())->toBeFalse();
});
