<?php

declare(strict_types=1);

use App\Domain\Conta\AlterarSenha;
use App\Domain\Conta\AtualizarPerfil;
use App\Domain\Conta\DadosPerfil;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/*
 * Perfil e senha da tela Configurações (spec FE §7.17). Escrita determinística: persiste os campos
 * já validados na borda e registra auditoria SEM conteúdo sensível (a senha nunca vai para o log).
 * O fuso é preferência do usuário (default America/Sao_Paulo, regra 5); o motor segue SP.
 */

uses(RefreshDatabase::class);

it('atualiza nome, e-mail e fuso e audita (sem senha)', function () {
    $user = User::factory()->create([
        'name' => 'Lucas',
        'email' => 'lucas@exemplo.com',
        'timezone' => 'America/Sao_Paulo',
    ]);

    $atualizado = (new AtualizarPerfil)->atualizar(new DadosPerfil(
        userId: $user->id,
        name: 'Lucas Braga',
        email: 'novo@exemplo.com',
        timezone: 'America/Bahia',
    ));

    expect($atualizado->name)->toBe('Lucas Braga')
        ->and($atualizado->email)->toBe('novo@exemplo.com')
        ->and($atualizado->timezone)->toBe('America/Bahia');

    $audit = AuditLog::where('entidade', 'user')
        ->where('entidade_id', $user->id)
        ->where('acao', AuditLog::ACAO_EDITAR)
        ->first();

    expect($audit)->not->toBeNull();
    // A senha JAMAIS aparece na trilha (LGPD/segurança).
    expect(json_encode([$audit->antes, $audit->depois]))->not->toContain('password');
});

it('altera a senha (hash) e audita sem expor a senha', function () {
    $user = User::factory()->create([
        'password' => Hash::make('senha-antiga-123'),
    ]);

    (new AlterarSenha)->alterar($user->id, 'senha-nova-456');

    $user->refresh();
    expect(Hash::check('senha-nova-456', $user->password))->toBeTrue();

    $audit = AuditLog::where('entidade', 'user')
        ->where('entidade_id', $user->id)
        ->where('acao', AuditLog::ACAO_EDITAR)
        ->latest('id')->first();

    expect($audit)->not->toBeNull();
    $serial = json_encode([$audit->antes, $audit->depois]);
    expect($serial)
        ->not->toContain('senha-nova-456')
        ->and($serial)->not->toContain($user->password);
});
