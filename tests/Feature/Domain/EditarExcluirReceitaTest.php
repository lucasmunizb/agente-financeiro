<?php

declare(strict_types=1);

use App\Domain\Receita\DadosReceita;
use App\Domain\Receita\EditarReceita;
use App\Domain\Receita\ExcluirReceita;
use App\Domain\Receita\ListarReceitas;
use App\Models\AuditLog;
use App\Models\Income;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Editar e excluir receita (spec FE §7.10). Editar atualiza os campos e audita; excluir é
 * CANCELAMENTO LÓGICO (soft delete — a linha e o histórico ficam) e audita. Escopo estrito por
 * usuário (findOrFail → 404 para item alheio). Centavos (regra 5).
 */

uses(RefreshDatabase::class);

function receitaDe(User $user, array $over = []): Income
{
    return Income::create([
        'user_id' => $user->id,
        'descricao' => $over['descricao'] ?? 'Salário',
        'valor_cents' => $over['valor_cents'] ?? 500000,
        'data' => $over['data'] ?? '2026-07-05',
        'tipo' => $over['tipo'] ?? Income::TIPO_FIXA,
    ]);
}

it('edita os campos da receita e audita', function () {
    $user = User::factory()->create();
    $income = receitaDe($user);
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    $editada = (new EditarReceita)->editar($income->id, new DadosReceita(
        userId: $user->id,
        descricao: 'Salário líquido',
        valorCents: 550000,
        data: CarbonImmutable::parse('2026-07-06', 'America/Sao_Paulo'),
        tipo: Income::TIPO_VARIAVEL,
    ), $agora);

    expect($editada->descricao)->toBe('Salário líquido')
        ->and($editada->valor_cents)->toBe(550000)
        ->and($editada->tipo)->toBe(Income::TIPO_VARIAVEL)
        ->and($editada->data->toDateString())->toBe('2026-07-06');

    expect(AuditLog::where('entidade', 'income')->where('entidade_id', $income->id)
        ->where('acao', AuditLog::ACAO_EDITAR)->exists())->toBeTrue();
});

it('não edita receita de outro usuário', function () {
    $user = User::factory()->create();
    $alheia = receitaDe(User::factory()->create());
    $agora = CarbonImmutable::now('America/Sao_Paulo');

    (new EditarReceita)->editar($alheia->id, new DadosReceita(
        userId: $user->id, descricao: 'x', valorCents: 100, data: $agora, tipo: Income::TIPO_FIXA,
    ), $agora);
})->throws(ModelNotFoundException::class);

it('exclui a receita por cancelamento lógico (soft delete) e audita', function () {
    $user = User::factory()->create();
    $income = receitaDe($user);
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    (new ExcluirReceita)->excluir($income->id, $user->id, $agora);

    expect((new ListarReceitas)->para($user->id, '2026-07', null))->toHaveCount(0)
        ->and(Income::withTrashed()->find($income->id)->deleted_at)->not->toBeNull();

    expect(AuditLog::where('entidade', 'income')->where('entidade_id', $income->id)
        ->where('acao', AuditLog::ACAO_EXCLUIR)->exists())->toBeTrue();
});

it('não exclui receita de outro usuário', function () {
    $user = User::factory()->create();
    $alheia = receitaDe(User::factory()->create());

    (new ExcluirReceita)->excluir($alheia->id, $user->id, CarbonImmutable::now('America/Sao_Paulo'));
})->throws(ModelNotFoundException::class);
