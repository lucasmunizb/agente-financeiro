<?php

use App\Domain\IA\Intencao;

/*
 * Intenção interpretada pela IA (doc 02 §3.1): registrar / consultar / editar /
 * cancelar / importar. DESCONHECIDO é o fallback seguro — qualquer saída que não
 * corresponda a uma intenção conhecida NUNCA vira chute. Distinto do enum `Comando`
 * (roteamento determinístico por slash-command); aqui é o vocabulário da IA. Puro.
 */

it('converte texto em intenção, caindo em DESCONHECIDO no que não reconhece', function () {
    expect(Intencao::tentar('registrar'))->toBe(Intencao::REGISTRAR)
        ->and(Intencao::tentar('importar'))->toBe(Intencao::IMPORTAR)
        ->and(Intencao::tentar('xpto'))->toBe(Intencao::DESCONHECIDO)
        ->and(Intencao::tentar(''))->toBe(Intencao::DESCONHECIDO)
        ->and(Intencao::tentar(null))->toBe(Intencao::DESCONHECIDO);
});

it('lista os valores para alimentar o schema do classificador', function () {
    expect(Intencao::valores())
        ->toEqualCanonicalizing(['registrar', 'pagar', 'consultar', 'editar', 'cancelar', 'importar', 'desconhecido']);
});
