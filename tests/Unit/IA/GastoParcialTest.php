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
    $parcial = new GastoParcial(null, '263,52', 'pix', null, 'viagem', 'amanhã', null);

    expect($parcial->faltantes())->toBe(['descricao'])
        ->and($parcial->completo())->toBeFalse();
});

it('exige cartão quando a forma é crédito', function () {
    $parcial = new GastoParcial('tv', '2000', 'credito', null, null, null, null);

    expect($parcial->faltantes())->toContain('cartao');
});

it('é completo quando tem descrição, valor e forma (sem crédito)', function () {
    $parcial = new GastoParcial('mercado', '90', 'pix', null, null, 'hoje', null);

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
