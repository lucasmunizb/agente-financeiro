<?php

use App\Ai\Agents\ExtratorDeGasto;
use Laravel\Ai\Ai;

/*
 * Comportamento do extrator com os fakes da SDK. O DTO sai CRU: valor e data como
 * texto, sem normalizar (Money/RelativeDate é o item 3). Barreira 1 (§3.3): campo
 * obrigatório ausente vira esclarecimento, NUNCA chute. Crédito sem cartão (§3.4)
 * também exige esclarecimento.
 */

it('extrai um gasto completo em DTO cru, sem normalizar valor nem data', function () {
    Ai::fakeAgent(ExtratorDeGasto::class, [[
        'descricao' => 'mercado',
        'valor' => '35 conto',
        'forma_pagamento' => 'pix',
        'data' => 'hoje',
        // Fora de cartão, o "já pagou?" é slot obrigatório: sem ele o gasto fica incompleto.
        'pago' => true,
    ]]);

    $resultado = app(ExtratorDeGasto::class)->extrair('gastei 35 conto no mercado hoje no pix');

    expect($resultado->precisaEsclarecer())->toBeFalse()
        ->and($resultado->gasto->pago)->toBeTrue()
        ->and($resultado->gasto->descricao)->toBe('mercado')
        ->and($resultado->gasto->valorTexto)->toBe('35 conto')
        ->and($resultado->gasto->dataTexto)->toBe('hoje')
        ->and($resultado->gasto->formaPagamento)->toBe('pix');
});

it('extrai parcelas como inteiro quando o usuário parcela', function () {
    Ai::fakeAgent(ExtratorDeGasto::class, [[
        'descricao' => 'geladeira',
        'valor' => '1200',
        'forma_pagamento' => 'credito',
        'cartao' => 'cartão nubank',
        'parcelas' => 3,
    ]]);

    $resultado = app(ExtratorDeGasto::class)->extrair('geladeira 1200 em 3x no nubank');

    expect($resultado->precisaEsclarecer())->toBeFalse()
        ->and($resultado->gasto->parcelas)->toBe(3)
        ->and($resultado->gasto->cartao)->toBe('cartão nubank');
});

it('sinaliza esclarecimento quando falta campo obrigatório (barreira 1)', function () {
    Ai::fakeAgent(ExtratorDeGasto::class, [['descricao' => 'uber']]);

    $resultado = app(ExtratorDeGasto::class)->extrair('paguei o uber');

    expect($resultado->precisaEsclarecer())->toBeTrue()
        ->and($resultado->gasto)->toBeNull()
        ->and($resultado->camposFaltantes)->toContain('valor')
        ->and($resultado->camposFaltantes)->toContain('forma_pagamento');
});

it('exige cartão quando a forma é crédito (§3.4)', function () {
    Ai::fakeAgent(ExtratorDeGasto::class, [[
        'descricao' => 'tv',
        'valor' => '2000',
        'forma_pagamento' => 'credito',
    ]]);

    $resultado = app(ExtratorDeGasto::class)->extrair('comprei uma tv de 2000 no crédito');

    expect($resultado->precisaEsclarecer())->toBeTrue()
        ->and($resultado->camposFaltantes)->toContain('cartao');
});

it('encaminha o texto original íntegro ao agente', function () {
    Ai::fakeAgent(ExtratorDeGasto::class, [[
        'descricao' => 'mercado', 'valor' => '35', 'forma_pagamento' => 'pix',
    ]]);

    app(ExtratorDeGasto::class)->extrair('gastei 35 no mercado no pix');

    Ai::assertAgentWasPrompted(
        ExtratorDeGasto::class,
        fn ($prompt) => $prompt->prompt === 'gastei 35 no mercado no pix'
    );
});
