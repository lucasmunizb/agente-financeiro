<?php

declare(strict_types=1);

use App\Domain\Cartao\CriarCartao;
use App\Domain\Cartao\DadosCartao;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Cadastro de cartão (spec FE §7.13). Escrita determinística: descrição + 4 dígitos finais +
 * dias de ciclo (limite opcional), centavos (regra 5), escopo por usuário, auditoria. O cartão
 * é identificado só por descrição + final_4 (nada de número completo — LGPD/§4.6).
 */

uses(RefreshDatabase::class);

it('cria um cartão do usuário com os campos e audita', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    $card = (new CriarCartao)->criar(new DadosCartao(
        userId: $user->id,
        descricao: 'Nubank',
        final4: '1234',
        diaFechamento: 28,
        diaVencimento: 5,
        limiteCents: 500000,
    ), $agora);

    expect($card->user_id)->toBe($user->id)
        ->and($card->descricao)->toBe('Nubank')
        ->and($card->final_4)->toBe('1234')
        ->and($card->dia_fechamento)->toBe(28)
        ->and($card->dia_vencimento)->toBe(5)
        ->and($card->limite_cents)->toBe(500000);

    expect(AuditLog::where('entidade', 'card')->where('entidade_id', $card->id)
        ->where('acao', AuditLog::ACAO_CRIAR)->exists())->toBeTrue();
});

it('aceita cartão sem limite (limite é opcional)', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    $card = (new CriarCartao)->criar(new DadosCartao(
        userId: $user->id,
        descricao: 'Itaú',
        final4: '9876',
        diaFechamento: 20,
        diaVencimento: 1,
    ), $agora);

    expect($card->limite_cents)->toBeNull();
});
