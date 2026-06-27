<?php

declare(strict_types=1);

use App\Domain\IA\Custo\RegistrarUsoDeIA;
use App\Domain\IA\Custo\TipoDeUsoIA;
use App\Domain\IA\Custo\UsoDeIA;
use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
 * Registro de custo de IA por chamada (doc 02 §3.6): tabela ai_usage_log com modelo,
 * tokens in/out, custo estimado (centavos), latência, tipo e usuário — SEM dados
 * sensíveis (nenhum conteúdo de mensagem). Consultável por SQL/admin.
 */

uses(RefreshDatabase::class);

it('persiste uma linha de uso com todos os campos', function () {
    $user = User::factory()->create();

    $registro = app(RegistrarUsoDeIA::class)->registrar(new UsoDeIA(
        provider: 'anthropic',
        model: 'claude-teste',
        tokensEntrada: 1200,
        tokensSaida: 300,
        custoEstimadoCents: 54,
        latenciaMs: 820,
        tipo: TipoDeUsoIA::MENSAGEM,
        userId: $user->id,
    ));

    expect($registro)->toBeInstanceOf(AiUsageLog::class);

    $linha = AiUsageLog::query()->sole();
    expect($linha->provider)->toBe('anthropic')
        ->and($linha->model)->toBe('claude-teste')
        ->and($linha->tokens_entrada)->toBe(1200)
        ->and($linha->tokens_saida)->toBe(300)
        ->and($linha->custo_estimado_cents)->toBe(54)
        ->and($linha->latencia_ms)->toBe(820)
        ->and($linha->tipo)->toBe(TipoDeUsoIA::MENSAGEM)
        ->and($linha->user_id)->toBe($user->id)
        ->and($linha->created_at)->not->toBeNull();
});

it('aceita uso sem usuário e sem latência (nulos)', function () {
    app(RegistrarUsoDeIA::class)->registrar(new UsoDeIA(
        provider: 'anthropic',
        model: 'claude-teste',
        tokensEntrada: 10,
        tokensSaida: 5,
        custoEstimadoCents: 0,
        latenciaMs: null,
        tipo: TipoDeUsoIA::RESUMO,
        userId: null,
    ));

    $linha = AiUsageLog::query()->sole();
    expect($linha->user_id)->toBeNull()
        ->and($linha->latencia_ms)->toBeNull()
        ->and($linha->tipo)->toBe(TipoDeUsoIA::RESUMO);
});

it('não retém nenhum dado sensível: a tabela não tem coluna de conteúdo', function () {
    foreach (['content', 'conteudo', 'mensagem', 'texto', 'prompt', 'resposta'] as $coluna) {
        expect(Schema::hasColumn('ai_usage_log', $coluna))->toBeFalse();
    }
});

it('é append-only: registra created_at e não tem updated_at', function () {
    expect(Schema::hasColumn('ai_usage_log', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('ai_usage_log', 'updated_at'))->toBeFalse();
});
