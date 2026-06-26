<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/*
 * audit_log — auditoria (doc 04 §audit_log).
 * Histórico de criação/edição/cancelamento/importação SEM dados sensíveis.
 * Registro append-only (imutável): tem created_at, não tem updated_at.
 * `antes`/`depois` em JSONB.
 */

uses(RefreshDatabase::class);

it('pertence a um usuário', function () {
    $log = AuditLog::factory()->create();

    expect($log->user)->toBeInstanceOf(User::class);
});

it('guarda entidade, id do registro, ação e origem', function () {
    $log = AuditLog::factory()->create([
        'entidade' => 'transaction',
        'entidade_id' => 42,
        'acao' => AuditLog::ACAO_CRIAR,
        'origem' => 'manual',
    ]);

    expect($log->fresh())
        ->entidade->toBe('transaction')
        ->entidade_id->toBe(42)
        ->acao->toBe('criar')
        ->origem->toBe('manual');
});

it('faz cast de antes/depois para array (JSONB)', function () {
    $log = AuditLog::factory()->create([
        'antes' => null,
        'depois' => ['descricao' => 'Mercado', 'valor_total_cents' => 15000],
    ]);

    expect($log->fresh()->antes)->toBeNull()
        ->and($log->fresh()->depois)->toBe(['descricao' => 'Mercado', 'valor_total_cents' => 15000]);
});

it('preenche created_at automaticamente e não gerencia updated_at (append-only)', function () {
    $log = AuditLog::factory()->create();

    expect($log->created_at)->toBeInstanceOf(Carbon::class)
        ->and($log->updated_at)->toBeNull();
});
