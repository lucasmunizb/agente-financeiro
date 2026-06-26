<?php

use App\Ai\Agents\RedatorDeResposta;
use Laravel\Ai\Ai;

/*
 * Comportamento do redator com os fakes da SDK. Recebe um payload textual já calculado
 * pelo motor financeiro e devolve a redação. O guard pós-geração (barreira 4) que valida
 * cada número contra o payload é camada nossa do Bloco 6 — não entra aqui.
 */

it('redige a partir do payload já calculado', function () {
    Ai::fakeAgent(RedatorDeResposta::class, ['Você gastou R$ 1.234,56 em junho.']);

    $texto = app(RedatorDeResposta::class)->redigir('total_junho=R$ 1.234,56');

    expect($texto)->toBe('Você gastou R$ 1.234,56 em junho.');
});

it('encaminha o payload ao agente para redação', function () {
    Ai::fakeAgent(RedatorDeResposta::class, ['ok']);

    app(RedatorDeResposta::class)->redigir('total_junho=R$ 1.234,56');

    Ai::assertAgentWasPrompted(
        RedatorDeResposta::class,
        fn ($prompt) => str_contains($prompt->prompt, 'total_junho=R$ 1.234,56')
    );
});
