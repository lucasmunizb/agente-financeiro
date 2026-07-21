<?php

use App\Domain\IA\GastoParcial;

/*
 * Slot-filling determinístico entre turnos de esclarecimento (bug: o bot pedia de novo o
 * que o usuário já dissera na 1ª mensagem). A mescla é NOSSA, determinística (regra 4):
 * a IA extrai cada mensagem isolada; nós acumulamos campo-a-campo. "Novo não-nulo vence"
 * cobre tanto preenchimento (slot vazio) quanto correção ("na verdade foi 264").
 */

it('mescla preenchendo os campos que faltavam, sem apagar os já conhecidos', function () {
    $acumulado = new GastoParcial(
        descricao: null, valorTexto: '263,52', formaPagamento: 'pix',
        cartao: null, categoria: 'viagem', dataTexto: 'amanhã', parcelas: null,
    );

    // 2º turno: o usuário só respondeu a descrição; o resto sai nulo do extrator.
    $novo = new GastoParcial(
        descricao: 'airbnb mauricio', valorTexto: null, formaPagamento: null,
        cartao: null, categoria: null, dataTexto: null, parcelas: null,
    );

    $mesclado = $acumulado->mesclar($novo);

    expect($mesclado->descricao)->toBe('airbnb mauricio')
        ->and($mesclado->valorTexto)->toBe('263,52')
        ->and($mesclado->formaPagamento)->toBe('pix')
        ->and($mesclado->categoria)->toBe('viagem')
        ->and($mesclado->dataTexto)->toBe('amanhã');
});

it('deixa o novo não-nulo vencer (correção de um campo já preenchido)', function () {
    $acumulado = new GastoParcial('airbnb', '263,52', 'pix', null, 'viagem', 'amanhã', null);
    $novo = new GastoParcial(null, '264', null, null, null, null, null);

    expect($acumulado->mesclar($novo)->valorTexto)->toBe('264');
});

it('sinaliza os campos obrigatórios que faltam (descricao, valor, forma)', function () {
    $parcial = new GastoParcial(null, '263,52', 'pix', null, 'viagem', 'amanhã', null, null, false);

    expect($parcial->faltantes())->toBe(['descricao'])
        ->and($parcial->completo())->toBeFalse();
});

it('exige cartão quando a forma é crédito', function () {
    $parcial = new GastoParcial('tv', '2000', 'credito', null, null, null, null);

    expect($parcial->faltantes())->toContain('cartao');
});

it('é completo quando tem descrição, valor, forma e o "já pagou?" respondido (sem crédito)', function () {
    $parcial = new GastoParcial('mercado', '90', 'pix', null, null, 'hoje', null, null, false);

    expect($parcial->completo())->toBeTrue()
        ->and($parcial->faltantes())->toBe([]);
});

it('converte para o DTO cru de extração quando completo', function () {
    $parcial = new GastoParcial('mercado', '90', 'pix', null, 'alimentacao', 'hoje', 3);

    $gasto = $parcial->paraExtraido();

    expect($gasto->descricao)->toBe('mercado')
        ->and($gasto->valorTexto)->toBe('90')
        ->and($gasto->formaPagamento)->toBe('pix')
        ->and($gasto->categoria)->toBe('alimentacao')
        ->and($gasto->dataTexto)->toBe('hoje')
        ->and($gasto->parcelas)->toBe(3);
});

/*
 * Recorrência via bot (spec 10c). O dia-do-mês é só mais um slot CRU: a IA copia o número,
 * o domínio valida e resolve. Precisa sobreviver ao multi-turno — o incidente de produção
 * (2026-07-16) nasceu de "todo dia 10" não ter para onde ir.
 */

it('mescla o dia da recorrência preservando-o entre turnos (C12 da spec 10c)', function () {
    // 1º turno: o usuário disse o que repete e quando, mas não o valor nem a forma.
    $acumulado = new GastoParcial(
        descricao: 'ingles carol', valorTexto: null, formaPagamento: null,
        cartao: null, categoria: 'estudos', dataTexto: null, parcelas: null,
        recorrenciaDiaTexto: '10',
    );

    // 2º turno: "520 no pix" — o extrator não repete o dia, e ele NÃO pode se perder.
    $novo = new GastoParcial(
        descricao: null, valorTexto: '520', formaPagamento: 'pix',
        cartao: null, categoria: null, dataTexto: null, parcelas: null,
        recorrenciaDiaTexto: null,
    );

    $mesclado = $acumulado->mesclar($novo);

    expect($mesclado->recorrenciaDiaTexto)->toBe('10')
        ->and($mesclado->valorTexto)->toBe('520')
        ->and($mesclado->formaPagamento)->toBe('pix')
        ->and($mesclado->completo())->toBeTrue();
});

it('deixa o dia novo corrigir o anterior', function () {
    $acumulado = new GastoParcial('ingles', '520', 'pix', null, null, null, null, '10');
    $novo = new GastoParcial(null, null, null, null, null, null, null, '15');

    expect($acumulado->mesclar($novo)->recorrenciaDiaTexto)->toBe('15');
});

it('não torna o dia da recorrência obrigatório (gasto avulso segue completo sem ele)', function () {
    $parcial = new GastoParcial('mercado', '90', 'pix', null, null, 'hoje', null, null, false);

    expect($parcial->completo())->toBeTrue();
});

it('propaga o dia da recorrência para o DTO cru', function () {
    $parcial = new GastoParcial('ingles carol', '520', 'pix', null, 'estudos', null, null, '10');

    expect($parcial->paraExtraido()->recorrenciaDiaTexto)->toBe('10');
});

/*
 * Já foi pago? (decisão 2026-07-21). O pagamento é mais um slot CRU: a IA só reporta que o
 * usuário disse ter pago e COPIA a data como texto — quem resolve a data é o domínio
 * (regra 4). Fora de cartão, o slot é OBRIGATÓRIO: sem ele o bot pergunta, em vez de
 * assumir "não pago". Crédito não pergunta (quita pela fatura), e recorrência é molde:
 * não há parcela a pagar no momento do cadastro.
 */

it('pergunta se já foi pago quando a forma é fora de cartão e o usuário não disse', function () {
    $parcial = new GastoParcial('mercado', '90', 'pix', null, null, 'hoje', null, null, null, null);

    expect($parcial->faltantes())->toBe(['pago'])
        ->and($parcial->completo())->toBeFalse();
});

it('não pergunta se já foi pago quando a forma é crédito (quita pela fatura)', function () {
    $parcial = new GastoParcial('tv', '2000', 'credito', 'cartão pai', null, 'hoje', null, null, null, null);

    expect($parcial->faltantes())->toBe([])
        ->and($parcial->completo())->toBeTrue();
});

it('não pergunta se já foi pago antes de saber a forma de pagamento', function () {
    $parcial = new GastoParcial('mercado', '90', null, null, null, 'hoje', null, null, null, null);

    expect($parcial->faltantes())->toBe(['forma_pagamento']);
});

it('não pergunta se já foi pago numa recorrência mensal (é molde, não lançamento)', function () {
    $parcial = new GastoParcial('ingles carol', '520', 'pix', null, null, null, null, '10', null, null);

    expect($parcial->faltantes())->toBe([])
        ->and($parcial->completo())->toBeTrue();
});

it('aceita o "não paguei ainda" como resposta válida do slot (false não é ausente)', function () {
    $parcial = new GastoParcial('mercado', '90', 'pix', null, null, 'hoje', null, null, false, null);

    expect($parcial->completo())->toBeTrue();
});

it('preserva o pago=false entre turnos (mesclar não confunde false com ausente)', function () {
    $acumulado = new GastoParcial('mercado', '90', 'pix', null, null, 'hoje', null, null, false, null);
    $novo = new GastoParcial(null, '95', null, null, null, null, null, null, null, null);

    expect($acumulado->mesclar($novo)->pago)->toBeFalse();
});

it('deixa o pago novo corrigir o anterior e preserva a data de pagamento crua', function () {
    $acumulado = new GastoParcial('mercado', '90', 'pix', null, null, 'hoje', null, null, false, null);
    $novo = new GastoParcial(null, null, null, null, null, null, null, null, true, 'ontem');

    $mesclado = $acumulado->mesclar($novo);

    expect($mesclado->pago)->toBeTrue()
        ->and($mesclado->dataPagamentoTexto)->toBe('ontem');
});

it('propaga pago e a data de pagamento crua para o DTO de extração', function () {
    $parcial = new GastoParcial('mercado', '90', 'pix', null, null, 'hoje', null, null, true, 'ontem');

    $gasto = $parcial->paraExtraido();

    expect($gasto->pago)->toBeTrue()
        ->and($gasto->dataPagamentoTexto)->toBe('ontem');
});
