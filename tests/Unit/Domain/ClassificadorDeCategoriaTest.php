<?php

use App\Domain\Categoria\ClassificadorDeCategoria;
use App\Domain\Categoria\RegrasDeCategoria;

/*
 * Classificador determinístico de categoria (doc 08 §1).
 * Precedência: alias de estabelecimento > palavra-chave > nenhum (null).
 * Comparação normalizada (caixa, acentos e espaços). Sem IA.
 */

function regras(array $aliases, array $keywords): RegrasDeCategoria
{
    return new RegrasDeCategoria(aliases: $aliases, keywords: $keywords);
}

it('classifica por alias de estabelecimento', function () {
    $regras = regras(['uber' => 7], []);

    expect(ClassificadorDeCategoria::classificar('Uber *trip 123', $regras))->toBe(7);
});

it('classifica por palavra-chave quando não há alias', function () {
    $regras = regras([], [['palavra' => 'hotel', 'categoria_id' => 3]]);

    expect(ClassificadorDeCategoria::classificar('HOTEL Ibis Centro', $regras))->toBe(3);
});

it('dá precedência ao alias sobre a palavra-chave', function () {
    $regras = regras(
        ['uber' => 7],
        [['palavra' => 'uber', 'categoria_id' => 99]],
    );

    expect(ClassificadorDeCategoria::classificar('uber', $regras))->toBe(7);
});

it('retorna null quando nada casa', function () {
    $regras = regras(['uber' => 7], [['palavra' => 'hotel', 'categoria_id' => 3]]);

    expect(ClassificadorDeCategoria::classificar('padaria do zé', $regras))->toBeNull();
});

it('ignora caixa, acentos e espaços extras na comparação', function () {
    $regras = regras([], [['palavra' => 'futebol', 'categoria_id' => 5]]);

    expect(ClassificadorDeCategoria::classificar('  Ingresso  FUTEBÓL  ', $regras))->toBe(5);
});

it('casa alias normalizado mesmo com acento na descrição', function () {
    $regras = regras(['acai da praca' => 2], []);

    expect(ClassificadorDeCategoria::classificar('Açaí da Praça', $regras))->toBe(2);
});

it('prefere a palavra-chave mais longa em caso de múltiplos matches', function () {
    $regras = regras([], [
        ['palavra' => 'jogo', 'categoria_id' => 5],
        ['palavra' => 'jogo de futebol', 'categoria_id' => 8],
    ]);

    expect(ClassificadorDeCategoria::classificar('comprei jogo de futebol novo', $regras))->toBe(8);
});
