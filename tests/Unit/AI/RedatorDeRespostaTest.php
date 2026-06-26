<?php

use App\Ai\Agents\RedatorDeResposta;

/*
 * Agent de redação (doc 02 §3.1, papel 3). Recebe dados JÁ calculados pelo motor
 * financeiro e apenas redige em pt-BR. NÃO inventa números: o guard pós-geração
 * (barreira 4, §3.3) é camada nossa e fica para o Bloco 6 — aqui garantimos que as
 * instruções já proíbem inventar valores. Puro, sem rede.
 */

it('proíbe inventar números — só formata o payload recebido', function () {
    $instrucoes = mb_strtolower((string) (new RedatorDeResposta)->instructions());

    expect($instrucoes)
        ->toContain('não invente')
        ->toContain('payload');
});
