<?php

declare(strict_types=1);

use App\Domain\Chat\RedatorDoChat;
use App\Domain\Gasto\ParcelaPrevia;
use App\Domain\Gasto\PreviaGastoManual;
use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\IA\Consulta\RespostaDaConsulta;
use App\Domain\Shared\Money;
use App\Domain\Telegram\Resposta\ResultadoDaInteracao;
use Carbon\CarbonImmutable;

function confirmacaoComCategoria(?string $categoria, bool $sugeridaPorIa): ConfirmacaoDeGasto
{
    $previa = new PreviaGastoManual(
        descricao: 'cinema',
        valorTotal: Money::fromCents(3500),
        origem: 'manual',
        ehDuplicado: false,
        parcelas: [new ParcelaPrevia(1, 3500, CarbonImmutable::parse('2026-07-10'), Money::fromCents(3500), 'aberto')],
        categoria: $categoria,
        categoriaSugeridaPorIa: $sugeridaPorIa,
    );

    return new ConfirmacaoDeGasto($previa, null, []);
}

function textoDaPrevia(?string $categoria, bool $sugeridaPorIa): string
{
    return (new RedatorDoChat)
        ->redigir(ResultadoDaInteracao::registro(confirmacaoComCategoria($categoria, $sugeridaPorIa)))
        ->texto;
}

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

/* -------- prévia de gasto: categoria pré-selecionada + dica da IA -------- */

it('mostra a categoria como DICA quando foi sugerida pela IA (pré-seleção a confirmar)', function () {
    $texto = textoDaPrevia('Lazer', sugeridaPorIa: true);

    expect($texto)->toContain('Confirme o gasto:')
        ->and($texto)->toContain('Categoria sugerida: Lazer')
        ->and($texto)->toContain('sim');
});

it('mostra a categoria de forma afirmativa quando veio de regra aprendida (lookup)', function () {
    $texto = textoDaPrevia('Mercado', sugeridaPorIa: false);

    expect($texto)->toContain('Categoria: Mercado')
        ->and($texto)->not->toContain('sugerida');
});

it('omite a linha de categoria quando nenhuma foi identificada', function () {
    $texto = textoDaPrevia(null, sugeridaPorIa: false);

    expect($texto)->toContain('Confirme o gasto:')
        ->and($texto)->not->toContain('Categoria');
});
