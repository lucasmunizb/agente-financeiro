<?php

declare(strict_types=1);

use App\Domain\Chat\RedatorDoChat;
use App\Domain\Gasto\ParcelaPrevia;
use App\Domain\Gasto\PreviaGastoManual;
use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\IA\Consulta\RespostaDaConsulta;
use App\Domain\Recorrencia\DadosRecorrencia;
use App\Domain\Recorrencia\PreviaRecorrencia;
use App\Domain\Shared\Money;
use App\Domain\Telegram\Resposta\ResultadoDaInteracao;
use App\Models\Recurrence;
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

/*
 * Recorrência via bot/chat (spec 10c §8 — etapa de apresentação). O backend já grava o molde
 * mensal; aqui garantimos que o usuário LÊ uma recorrência, e não a mensagem de um gasto
 * avulso. Continua determinístico: só transcreve o que o domínio calculou (regra 4).
 */

function previaDeRecorrencia(?string $categoria = null): PreviaRecorrencia
{
    return new PreviaRecorrencia(
        descricao: 'ingles carol',
        valor: Money::fromCents(52000),
        dia: 10,
        formaPagamento: 'pix',
        categoria: $categoria,
    );
}

function textoDaRecorrencia(?string $categoria = null): string
{
    $confirmacao = new ConfirmacaoDeGasto(null, null, [], recorrencia: dadosDeRecorrencia(), previaRecorrencia: previaDeRecorrencia($categoria));

    return (new RedatorDoChat)->redigir(ResultadoDaInteracao::registro($confirmacao))->texto;
}

function dadosDeRecorrencia(): DadosRecorrencia
{
    return new DadosRecorrencia(
        userId: 1,
        descricao: 'ingles carol',
        valorCents: 52000,
        paymentMethodId: 1,
        dia: 10,
    );
}

it('prévia da recorrência: diz que REPETE todo mês, com valor, dia e forma', function () {
    $texto = textoDaRecorrencia();

    expect($texto)
        ->toContain('ingles carol')
        ->toContain('R$ 520,00')
        ->toContain('todo dia 10')
        ->toContain('pix')
        // Precisa ficar claro que é recorrência, não um gasto de hoje.
        ->toContain('recorrência')
        ->toContain('sim');
});

it('prévia da recorrência: não promete lançamento — ele só nasce no dia (spec 10)', function () {
    expect(textoDaRecorrencia())->not->toContain('Confirme o gasto');
});

it('prévia da recorrência: mostra a categoria quando há', function () {
    expect(textoDaRecorrencia('Estudos'))->toContain('Estudos');
});

it('recorrência gravada: confirma o cadastro sem dizer que registrou um gasto', function () {
    $recorrencia = new Recurrence([
        'descricao' => 'ingles carol', 'valor_cents' => 52000, 'dia' => 10,
    ]);

    $texto = (new RedatorDoChat)->redigir(ResultadoDaInteracao::recorrenciaGravada($recorrencia))->texto;

    expect($texto)
        ->toContain('ingles carol')
        ->toContain('R$ 520,00')
        ->toContain('todo dia 10')
        ->not->toContain('registrei:');
});

it('esclarecimento: pede o dia da recorrência em pt-BR, não o código do schema', function () {
    $confirmacao = new ConfirmacaoDeGasto(null, null, ['recorrencia_dia']);

    $texto = (new RedatorDoChat)->redigir(ResultadoDaInteracao::registro($confirmacao))->texto;

    expect($texto)->toContain('o dia do mês')
        ->and($texto)->not->toContain('recorrencia_dia');
});
