<?php

use App\Ai\Agents\SugeridorDeCategoria;
use Laravel\Ai\Ai;

/*
 * Papel de classificação da IA (doc 02 §3.1): escolher UMA categoria da lista do usuário
 * que melhor combina com a descrição do gasto. A IA nunca calcula dinheiro (regra 4) — só
 * interpreta/classifica. A resolução final (nome → id) e o guard anti-alucinação são camada
 * determinística nossa (ver SugerirCategoriaComIa). Aqui só o agente: texto + opções → nome.
 */

it('sugere uma categoria da lista a partir da descrição', function () {
    Ai::fakeAgent(SugeridorDeCategoria::class, [['categoria' => 'Mercado']]);

    $escolha = app(SugeridorDeCategoria::class)
        ->sugerir('compras no extra', ['Mercado', 'Transporte', 'Lazer']);

    expect($escolha)->toBe('Mercado');
});

it('devolve null quando a IA não acha categoria adequada', function () {
    Ai::fakeAgent(SugeridorDeCategoria::class, [['categoria' => null]]);

    expect(app(SugeridorDeCategoria::class)->sugerir('xpto indecifrável', ['Mercado']))
        ->toBeNull();
});

it('não chama a IA quando o usuário não tem categorias', function () {
    Ai::fakeAgent(SugeridorDeCategoria::class, [['categoria' => 'Mercado']]);

    expect(app(SugeridorDeCategoria::class)->sugerir('mercado', []))->toBeNull();

    Ai::assertAgentNeverPrompted(SugeridorDeCategoria::class);
});

it('encaminha a descrição íntegra ao agente', function () {
    Ai::fakeAgent(SugeridorDeCategoria::class, [['categoria' => 'Lazer']]);

    app(SugeridorDeCategoria::class)->sugerir('cinema no shopping', ['Lazer', 'Mercado']);

    Ai::assertAgentWasPrompted(
        SugeridorDeCategoria::class,
        fn ($prompt) => $prompt->prompt === 'cinema no shopping',
    );
});
