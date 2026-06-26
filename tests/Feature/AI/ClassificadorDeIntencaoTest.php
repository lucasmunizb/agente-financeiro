<?php

use App\Ai\Agents\ClassificadorDeIntencao;
use App\Domain\IA\Intencao;
use Laravel\Ai\Ai;

/*
 * Comportamento do classificador com os fakes nativos da SDK (InteractsWithFakeAgents).
 * Determinístico, sem rede: a SDK devolve a saída estruturada que injetamos e o wrapper
 * mapeia para o enum `Intencao`. Saída fora do vocabulário NUNCA vira chute → DESCONHECIDO.
 */

it('mapeia a saída estruturada para a intenção correspondente', function () {
    Ai::fakeAgent(ClassificadorDeIntencao::class, [['intencao' => 'registrar']]);

    $intencao = app(ClassificadorDeIntencao::class)->classificar('gastei 35 no mercado');

    expect($intencao)->toBe(Intencao::REGISTRAR);
});

it('cai em DESCONHECIDO quando a saída não corresponde a uma intenção conhecida', function () {
    Ai::fakeAgent(ClassificadorDeIntencao::class, [['intencao' => 'xpto']]);

    expect(app(ClassificadorDeIntencao::class)->classificar('???'))->toBe(Intencao::DESCONHECIDO);
});

it('encaminha o texto original íntegro ao agente', function () {
    Ai::fakeAgent(ClassificadorDeIntencao::class, [['intencao' => 'consultar']]);

    app(ClassificadorDeIntencao::class)->classificar('quanto gastei em junho?');

    Ai::assertAgentWasPrompted(
        ClassificadorDeIntencao::class,
        fn ($prompt) => $prompt->prompt === 'quanto gastei em junho?'
    );
});
