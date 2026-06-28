<?php

use App\Domain\Telegram\Confirmacao\InterpretadorDeConfirmacao;
use App\Domain\Telegram\Confirmacao\RespostaDeConfirmacao;

/*
 * Spec 04b §6/§7 — interpretação DETERMINÍSTICA (não-IA) da resposta de confirmação.
 * Reconhece um conjunto pequeno e explícito de "sim"/"não" (com normalização: trim,
 * minúsculas, sem acento); qualquer outra coisa é INDEFINIDO — fallback seguro que nunca
 * vira chute (barreira 1). A IA não participa: nada de número/dinheiro aqui (regra 4).
 */

it('reconhece variações de "sim" como SIM', function (string $texto) {
    expect((new InterpretadorDeConfirmacao)->interpretar($texto))
        ->toBe(RespostaDeConfirmacao::SIM);
})->with([
    'sim', 'Sim', 'SIM', ' sim ', 'sim!', 's', 'confirmo', 'confirma', 'ok', 'isso', 'pode',
]);

it('reconhece variações de "não" como NAO', function (string $texto) {
    expect((new InterpretadorDeConfirmacao)->interpretar($texto))
        ->toBe(RespostaDeConfirmacao::NAO);
})->with([
    'não', 'nao', 'Não', 'NÃO', ' nao ', 'n', 'cancela', 'cancelar', 'negativo',
]);

it('cai em INDEFINIDO quando não é claramente sim/não (nunca chuta)', function (string $texto) {
    expect((new InterpretadorDeConfirmacao)->interpretar($texto))
        ->toBe(RespostaDeConfirmacao::INDEFINIDO);
})->with([
    'talvez', 'depois', 'quanto gastei?', '???', '', '   ', 'gastei 90 no mercado',
]);
