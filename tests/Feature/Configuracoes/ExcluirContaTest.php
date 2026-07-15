<?php

declare(strict_types=1);

use App\Domain\Conta\ExcluirConta;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/*
 * Exclusão de conta (LGPD art. 18 — direito ao esquecimento). Decisão do usuário: SOFT DELETE do
 * usuário (login bloqueado); os dados ficam intactos porém inacessíveis (escopo por user_id). A
 * trilha de auditoria é PRESERVADA e não reexpõe PII em claro.
 */

uses(RefreshDatabase::class);

it('faz soft delete do usuário e preserva a auditoria sem PII em claro', function () {
    $user = User::factory()->create(['name' => 'Lucas', 'email' => 'lucas@exemplo.com']);

    (new ExcluirConta)->excluir($user->id);

    // Soft delete: some das consultas normais, mas a linha permanece.
    expect(User::find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id))->not->toBeNull()
        ->and(User::withTrashed()->find($user->id)->deleted_at)->not->toBeNull();

    $audit = AuditLog::where('entidade', 'user')
        ->where('entidade_id', $user->id)
        ->where('acao', AuditLog::ACAO_EXCLUIR)
        ->first();

    expect($audit)->not->toBeNull();
    // A trilha registra o fato, mas não reexpõe o dado do titular em claro.
    $serial = json_encode([$audit->antes, $audit->depois]);
    expect($serial)
        ->not->toContain('lucas@exemplo.com')
        ->and($serial)->not->toContain('Lucas');
});

it('anonimiza a PII do titular na exclusão (nome, e-mail, telefone — auditoria P2-10)', function () {
    $user = User::factory()->create(['name' => 'Lucas', 'email' => 'lucas@exemplo.com']);
    \App\Models\TelegramLink::create([
        'user_id' => $user->id,
        'telegram_user_id' => 999002,
        'telefone' => '+5511988887777',
        'status' => \App\Models\TelegramLink::ATIVO,
        'vinculado_em' => now(),
    ]);

    (new ExcluirConta)->excluir($user->id);

    // Esquecimento de verdade: identificadores do titular não ficam retidos em claro.
    $anon = User::withTrashed()->find($user->id);
    expect($anon->name)->toBeNull()
        ->and($anon->email)->not->toBe('lucas@exemplo.com');

    $link = \App\Models\TelegramLink::where('user_id', $user->id)->first();
    expect($link->telefone)->toBeNull()
        ->and($link->telegram_user_id)->toBeNull()
        ->and($link->status)->toBe(\App\Models\TelegramLink::REVOGADO);
});

it('bloqueia o login de um usuário excluído', function () {
    $user = User::factory()->create([
        'email' => 'sai@exemplo.com',
        'password' => Hash::make('senha-correta-123'),
    ]);

    (new ExcluirConta)->excluir($user->id);

    $this->post('/login', [
        'email' => 'sai@exemplo.com',
        'password' => 'senha-correta-123',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});
