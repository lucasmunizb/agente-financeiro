<?php

use App\Ai\Agents\SugeridorDeCategoria;
use App\Domain\Categoria\ResolvedorDeCategoria;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Ai;

/*
 * Resolução de categoria com precedência (doc 08 §1 + fallback de IA): o lookup determinístico
 * (aliases/keywords que o usuário treinou por correção) decide primeiro — barato, instantâneo e
 * de maior confiança. A IA só entra quando o lookup não classifica, e o resultado vem MARCADO
 * como sugerido (pré-seleção a confirmar). Sem lookup e sem sugestão → nenhuma categoria.
 */

uses(RefreshDatabase::class);

it('usa o lookup determinístico e NÃO chama a IA quando há regra', function () {
    $user = User::factory()->create();
    $cat = Category::factory()->for($user)->create(['nome' => 'Mercado']);
    $cat->keywords()->create(['palavra_chave' => 'mercado']);
    Ai::fakeAgent(SugeridorDeCategoria::class, [['categoria' => 'Mercado']]);

    $r = app(ResolvedorDeCategoria::class)->para($user->id, 'mercado extra');

    expect($r->categoriaId)->toBe($cat->id)
        ->and($r->sugeridaPorIa)->toBeFalse();

    Ai::assertAgentNeverPrompted(SugeridorDeCategoria::class);
});

it('cai na IA quando o lookup não classifica e marca como sugerida', function () {
    $user = User::factory()->create();
    $cat = Category::factory()->for($user)->create(['nome' => 'Lazer']);
    Ai::fakeAgent(SugeridorDeCategoria::class, [['categoria' => 'Lazer']]);

    $r = app(ResolvedorDeCategoria::class)->para($user->id, 'cinema no shopping');

    expect($r->categoriaId)->toBe($cat->id)
        ->and($r->sugeridaPorIa)->toBeTrue();
});

it('sem lookup e sem sugestão da IA, não resolve categoria', function () {
    $user = User::factory()->create();
    Category::factory()->for($user)->create(['nome' => 'Mercado']);
    Ai::fakeAgent(SugeridorDeCategoria::class, [['categoria' => null]]);

    $r = app(ResolvedorDeCategoria::class)->para($user->id, 'algo desconhecido');

    expect($r->categoriaId)->toBeNull()
        ->and($r->sugeridaPorIa)->toBeFalse();
});
