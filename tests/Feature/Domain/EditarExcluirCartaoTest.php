<?php

declare(strict_types=1);

use App\Domain\Cartao\DadosCartao;
use App\Domain\Cartao\EditarCartao;
use App\Domain\Cartao\ExcluirCartao;
use App\Domain\Cartao\ListarCartoes;
use App\Models\AuditLog;
use App\Models\Card;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Editar e remover cartão (spec FE §7.13). Editar atualiza os campos (inclusive o limite) e
 * audita; remover é CANCELAMENTO LÓGICO (soft delete — a linha e o histórico ficam) e audita.
 * Escopo estrito por usuário (findOrFail → 404 para item alheio). Centavos (regra 5).
 */

uses(RefreshDatabase::class);

it('edita os campos do cartão (inclusive o limite) e audita', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create([
        'descricao' => 'Nubank', 'final_4' => '1234', 'dia_fechamento' => 28, 'dia_vencimento' => 5, 'limite_cents' => null,
    ]);
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    $editado = (new EditarCartao)->editar($card->id, new DadosCartao(
        userId: $user->id,
        descricao: 'Nubank Ultravioleta',
        final4: '4321',
        diaFechamento: 20,
        diaVencimento: 1,
        limiteCents: 800000,
    ), $agora);

    expect($editado->descricao)->toBe('Nubank Ultravioleta')
        ->and($editado->final_4)->toBe('4321')
        ->and($editado->dia_fechamento)->toBe(20)
        ->and($editado->dia_vencimento)->toBe(1)
        ->and($editado->limite_cents)->toBe(800000);

    expect(AuditLog::where('entidade', 'card')->where('entidade_id', $card->id)
        ->where('acao', AuditLog::ACAO_EDITAR)->exists())->toBeTrue();
});

it('não edita cartão de outro usuário', function () {
    $user = User::factory()->create();
    $alheio = Card::factory()->for(User::factory()->create())->create();
    $agora = CarbonImmutable::now('America/Sao_Paulo');

    (new EditarCartao)->editar($alheio->id, new DadosCartao(
        userId: $user->id, descricao: 'x', final4: '0000', diaFechamento: 1, diaVencimento: 1,
    ), $agora);
})->throws(ModelNotFoundException::class);

it('remove o cartão por cancelamento lógico (soft delete) e audita', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    (new ExcluirCartao)->excluir($card->id, $user->id, $agora);

    // Some da listagem, mas a linha permanece (soft delete — histórico preservado).
    expect((new ListarCartoes)->para($user->id))->toHaveCount(0)
        ->and(Card::withTrashed()->find($card->id)->deleted_at)->not->toBeNull();

    expect(AuditLog::where('entidade', 'card')->where('entidade_id', $card->id)
        ->where('acao', AuditLog::ACAO_EXCLUIR)->exists())->toBeTrue();
});

it('não remove cartão de outro usuário', function () {
    $user = User::factory()->create();
    $alheio = Card::factory()->for(User::factory()->create())->create();
    $agora = CarbonImmutable::now('America/Sao_Paulo');

    (new ExcluirCartao)->excluir($alheio->id, $user->id, $agora);
})->throws(ModelNotFoundException::class);
