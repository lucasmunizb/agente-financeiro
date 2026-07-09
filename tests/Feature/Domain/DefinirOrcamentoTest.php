<?php

declare(strict_types=1);

use App\Domain\Orcamento\DefinirOrcamento;
use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Definir o orçamento GERAL do mês (spec 10.5 / doc 08 §6). Escrita determinística: um limite
 * por (usuário, mês) — categoria_id nulo (MVP só tem o geral). updateOrCreate: redefinir o mesmo
 * mês atualiza a mesma linha (nunca duplica). Centavos (regra 5); escopo estrito por usuário.
 */

uses(RefreshDatabase::class);

it('define o orçamento geral do mês (linha nova, categoria nula) e audita', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    $budget = (new DefinirOrcamento)->definir($user->id, '2026-07', 400000, $agora);

    expect($budget->user_id)->toBe($user->id)
        ->and($budget->mes)->toBe('2026-07')
        ->and($budget->limite_cents)->toBe(400000)
        ->and($budget->categoria_id)->toBeNull();

    expect(Budget::where('user_id', $user->id)->count())->toBe(1)
        ->and(AuditLog::where('entidade', 'budget')->where('entidade_id', $budget->id)->exists())->toBeTrue();
});

it('redefinir o mesmo mês atualiza a mesma linha (updateOrCreate, não duplica)', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    (new DefinirOrcamento)->definir($user->id, '2026-07', 400000, $agora);
    $segundo = (new DefinirOrcamento)->definir($user->id, '2026-07', 550000, $agora);

    expect(Budget::where('user_id', $user->id)->where('mes', '2026-07')->count())->toBe(1)
        ->and($segundo->limite_cents)->toBe(550000)
        ->and(Budget::where('user_id', $user->id)->where('mes', '2026-07')->whereNull('categoria_id')->value('limite_cents'))
        ->toBe(550000);
});

it('mantém orçamentos de meses diferentes separados', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    (new DefinirOrcamento)->definir($user->id, '2026-07', 400000, $agora);
    (new DefinirOrcamento)->definir($user->id, '2026-08', 300000, $agora);

    expect(Budget::where('user_id', $user->id)->count())->toBe(2);
});

it('isola por usuário (não toca no orçamento de terceiros)', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    (new DefinirOrcamento)->definir($user->id, '2026-07', 400000, $agora);
    (new DefinirOrcamento)->definir($outro->id, '2026-07', 999900, $agora);

    expect(Budget::where('user_id', $user->id)->where('mes', '2026-07')->value('limite_cents'))->toBe(400000)
        ->and(Budget::where('user_id', $outro->id)->where('mes', '2026-07')->value('limite_cents'))->toBe(999900);
});
