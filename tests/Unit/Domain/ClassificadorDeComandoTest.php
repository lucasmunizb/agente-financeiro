<?php

use App\Domain\Telegram\ClassificadorDeComando;
use App\Domain\Telegram\Comando;
use App\Domain\Telegram\ComandoRecebido;

/*
 * Classificador determinístico de comandos do bot (doc 06 §2). Mapeia o texto da
 * mensagem para uma intenção (registrar/editar/cancelar/buscar/ajuda) e separa os
 * argumentos brutos. NÃO interpreta linguagem natural nem extrai valores — texto
 * livre vira DESCONHECIDO, com o texto íntegro preservado para o interpretador de
 * IA (Bloco 4). Puro: sem banco, sem app.
 */

function classificar(string $texto): ComandoRecebido
{
    return (new ClassificadorDeComando)->classificar($texto);
}

/* -------- slash-commands -------- */

it('reconhece cada slash-command básico', function (string $texto, Comando $esperado) {
    expect(classificar($texto)->comando)->toBe($esperado);
})->with([
    ['/registrar', Comando::REGISTRAR],
    ['/editar', Comando::EDITAR],
    ['/cancelar', Comando::CANCELAR],
    ['/buscar', Comando::BUSCAR],
    ['/ajuda', Comando::AJUDA],
    ['/start', Comando::AJUDA],
    ['/help', Comando::AJUDA],
]);

it('separa os argumentos após o slash-command', function () {
    $cmd = classificar('/buscar comida junho');

    expect($cmd->comando)->toBe(Comando::BUSCAR)
        ->and($cmd->argumentos)->toBe('comida junho');
});

it('ignora o sufixo @bot do comando (convenção de grupos do Telegram)', function () {
    $cmd = classificar('/cancelar@MeuFinBot 12');

    expect($cmd->comando)->toBe(Comando::CANCELAR)
        ->and($cmd->argumentos)->toBe('12');
});

it('é insensível a maiúsculas/minúsculas no comando', function () {
    expect(classificar('/REGISTRAR x')->comando)->toBe(Comando::REGISTRAR);
});

it('trata slash-command desconhecido como DESCONHECIDO', function () {
    expect(classificar('/foobar algo')->comando)->toBe(Comando::DESCONHECIDO);
});

/* -------- palavra-chave inicial (sem slash) -------- */

it('reconhece o verbo de comando como primeira palavra', function (string $texto, Comando $esperado) {
    expect(classificar($texto)->comando)->toBe($esperado);
})->with([
    ['registrar 50 no mercado', Comando::REGISTRAR],
    ['registra 50', Comando::REGISTRAR],
    ['editar 12', Comando::EDITAR],
    ['cancelar 12', Comando::CANCELAR],
    ['buscar comida', Comando::BUSCAR],
    ['ajuda', Comando::AJUDA],
]);

it('separa os argumentos após a palavra-chave inicial', function () {
    $cmd = classificar('cancelar 12');

    expect($cmd->comando)->toBe(Comando::CANCELAR)
        ->and($cmd->argumentos)->toBe('12');
});

it('exige a palavra inteira: não casa por prefixo', function () {
    // "registros" não é "registrar" — vira texto livre para a IA.
    expect(classificar('registros do mes')->comando)->toBe(Comando::DESCONHECIDO);
});

/* -------- texto livre / robustez -------- */

it('classifica texto livre como DESCONHECIDO preservando o texto íntegro para a IA', function () {
    $cmd = classificar('gastei 50 no mercado ontem');

    expect($cmd->comando)->toBe(Comando::DESCONHECIDO)
        ->and($cmd->argumentos)->toBe('gastei 50 no mercado ontem');
});

it('trata vazio e espaços como DESCONHECIDO com argumentos vazios', function (string $texto) {
    $cmd = classificar($texto);

    expect($cmd->comando)->toBe(Comando::DESCONHECIDO)
        ->and($cmd->argumentos)->toBe('');
})->with(['', '   ', "\n\t "]);

it('apara espaços ao redor antes de classificar', function () {
    $cmd = classificar('   /registrar  cafe  ');

    expect($cmd->comando)->toBe(Comando::REGISTRAR)
        ->and($cmd->argumentos)->toBe('cafe');
});

it('preserva o texto original na íntegra', function () {
    expect(classificar('  /buscar  Comida ')->textoOriginal)->toBe('  /buscar  Comida ');
});
