<?php

use App\Domain\Categoria\AprendizadoPorCorrecao;
use App\Domain\Categoria\LookupDeCategoria;
use App\Models\Category;
use App\Models\MerchantAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Aprendizado por correção (doc 08 §2).
 * A correção do usuário vira ou atualiza um alias de estabelecimento — de forma
 * determinística, sem treino de modelo. Idempotente e escopo por usuário.
 */

uses(RefreshDatabase::class);

it('cria um alias a partir da correção e passa a classificar', function () {
    $user = User::factory()->create();
    $transporte = Category::factory()->for($user)->create(['nome' => 'Transporte']);

    app(AprendizadoPorCorrecao::class)->corrigirEstabelecimento($user->id, 'Uber *trip 91', $transporte->id);

    expect(MerchantAlias::where('user_id', $user->id)->where('alias', 'uber trip 91')->exists())->toBeTrue()
        ->and(app(LookupDeCategoria::class)->para($user->id, 'UBER *TRIP 91'))->toBe($transporte->id);
});

it('atualiza a categoria de um alias já existente (não duplica)', function () {
    $user = User::factory()->create();
    $lazer = Category::factory()->for($user)->create(['nome' => 'Lazer']);
    $transporte = Category::factory()->for($user)->create(['nome' => 'Transporte']);

    $aprendizado = app(AprendizadoPorCorrecao::class);
    $aprendizado->corrigirEstabelecimento($user->id, 'uber', $lazer->id);
    $aprendizado->corrigirEstabelecimento($user->id, 'uber', $transporte->id);

    expect(MerchantAlias::where('user_id', $user->id)->where('alias', 'uber')->count())->toBe(1)
        ->and(app(LookupDeCategoria::class)->para($user->id, 'uber'))->toBe($transporte->id);
});
