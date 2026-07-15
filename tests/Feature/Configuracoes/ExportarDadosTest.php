<?php

declare(strict_types=1);

use App\Domain\Conta\ExportarDadosDoUsuario;
use App\Models\AuditLog;
use App\Models\Income;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/*
 * Portabilidade / acesso (LGPD art. 18). Exporta os dados estruturados APENAS do próprio user_id:
 * cadastro + registros financeiros do titular. Nunca vaza dado de terceiro; nunca inclui senha nem
 * dado sensível/efêmero (regra 6). Auditável.
 */

uses(RefreshDatabase::class);

it('exporta os dados estruturados do próprio usuário e audita', function () {
    $user = User::factory()->create(['name' => 'Lucas', 'email' => 'lucas@exemplo.com']);
    Income::create([
        'user_id' => $user->id,
        'descricao' => 'Salário do titular',
        'valor_cents' => 500000,
        'data' => '2026-07-05',
        'tipo' => Income::TIPO_FIXA,
    ]);

    $export = (new ExportarDadosDoUsuario)->exportar($user->id);

    expect($export)->toBeArray()->toHaveKey('cadastro');
    $json = json_encode($export, JSON_UNESCAPED_UNICODE);
    expect($json)
        ->toContain('lucas@exemplo.com')
        ->toContain('Salário do titular');

    expect(AuditLog::where('entidade', 'user')
        ->where('entidade_id', $user->id)
        ->where('acao', AuditLog::ACAO_EXPORTAR)
        ->exists())->toBeTrue();
});

it('inclui o histórico de chat do titular (portabilidade completa — auditoria P2-10)', function () {
    $user = User::factory()->create();
    \App\Models\ChatMessage::factory()->for($user)->create(['body' => 'quanto gastei em junho?']);

    $export = (new ExportarDadosDoUsuario)->exportar($user->id);

    expect($export)->toHaveKey('chat')
        ->and(json_encode($export, JSON_UNESCAPED_UNICODE))->toContain('quanto gastei em junho?');
});

it('nunca inclui chat de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    \App\Models\ChatMessage::factory()->for($outro)->create(['body' => 'mensagem alheia sigilosa']);

    $json = json_encode((new ExportarDadosDoUsuario)->exportar($user->id), JSON_UNESCAPED_UNICODE);

    expect($json)->not->toContain('mensagem alheia sigilosa');
});

it('nunca inclui dados de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create(['email' => 'terceiro@exemplo.com']);
    Income::create([
        'user_id' => $outro->id,
        'descricao' => 'Receita alheia secreta',
        'valor_cents' => 999,
        'data' => '2026-07-05',
        'tipo' => Income::TIPO_FIXA,
    ]);

    $json = json_encode((new ExportarDadosDoUsuario)->exportar($user->id), JSON_UNESCAPED_UNICODE);

    expect($json)
        ->not->toContain('Receita alheia secreta')
        ->and($json)->not->toContain('terceiro@exemplo.com');
});

it('nunca inclui a senha (hash) no export', function () {
    $user = User::factory()->create(['password' => Hash::make('super-secreta-123')]);

    $json = json_encode((new ExportarDadosDoUsuario)->exportar($user->id), JSON_UNESCAPED_UNICODE);

    expect($json)
        ->not->toContain($user->fresh()->password)
        ->and($json)->not->toContain('password');
});
