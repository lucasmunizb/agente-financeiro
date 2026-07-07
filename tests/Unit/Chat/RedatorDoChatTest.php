<?php

declare(strict_types=1);

use App\Domain\Chat\RedatorDoChat;
use App\Domain\IA\Consulta\RespostaDaConsulta;
use App\Domain\Telegram\Resposta\ResultadoDaInteracao;

/*
 * Redação determinística do chat (apresentação, regra 3), compartilhada entre a web e o
 * Telegram. A resposta entregue é SÓ o corpo em linguagem natural: a fonte (barreira 5) é
 * registro interno, nunca faz parte do texto enviado. Aqui garantimos que um eventual eco
 * da linha técnica "fonte: ...; N registro(s)" — que o modelo às vezes cospe no corpo — é
 * removido na ORIGEM, para o texto sair pronto em qualquer canal (web e bot).
 */

it('consulta: entrega só o corpo, removendo a linha técnica de fonte (pronto para qualquer canal)', function () {
    $resp = new RespostaDaConsulta(
        texto: "Você gastou **R$ 90,00** em futebol.\nfonte: consultar_gastos (periodo=2026-07, categoria=futebol); 2 registro(s)",
        aprovado: true,
        fontes: [],
        tentativas: 1,
    );

    $texto = (new RedatorDoChat)->redigir(ResultadoDaInteracao::consulta($resp))->texto;

    expect($texto)->toContain('R$ 90,00')
        ->and($texto)->not->toContain('fonte:')
        ->and($texto)->not->toContain('registro(s)')
        ->and($texto)->not->toContain('consultar_gastos');
});

it('consulta: mantém intacto o corpo que não tem linha de fonte', function () {
    $resp = new RespostaDaConsulta(
        texto: 'Você gastou R$ 90,00 em futebol este mês.',
        aprovado: true,
        fontes: [],
        tentativas: 1,
    );

    $texto = (new RedatorDoChat)->redigir(ResultadoDaInteracao::consulta($resp))->texto;

    expect($texto)->toBe('Você gastou R$ 90,00 em futebol este mês.');
});
