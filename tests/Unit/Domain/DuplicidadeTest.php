<?php

use App\Domain\Duplicidade\ChaveDeDuplicidade;
use App\Domain\Duplicidade\DetectorDeDuplicidade;
use Carbon\CarbonImmutable;

/*
 * Detecção de duplicidade (doc 07 §2) — determinística, sem DB.
 * Chave = valor + descrição + data + quantidade de parcelas.
 * A PARCELA ATUAL NUNCA entra no critério (é calculada na exibição).
 * A descrição é normalizada (caixa/acento/espaço) para robustez de comparação.
 */

function chave(int $valor, string $descricao, string $data, int $parcelas): ChaveDeDuplicidade
{
    return ChaveDeDuplicidade::de($valor, $descricao, CarbonImmutable::parse($data, 'America/Sao_Paulo'), $parcelas);
}

/* -------- Igualdade pela chave (valor + descrição + data + nº de parcelas) -------- */

it('considera duplicado quando valor, descrição, data e nº de parcelas batem', function () {
    $a = chave(15000, 'Mercado', '2026-06-10', 3);
    $b = chave(15000, 'Mercado', '2026-06-10', 3);

    expect($a->igualA($b))->toBeTrue()
        ->and($a->hash())->toBe($b->hash());
});

it('não é duplicado quando o valor difere', function () {
    expect(chave(15000, 'Mercado', '2026-06-10', 3)->igualA(chave(15001, 'Mercado', '2026-06-10', 3)))
        ->toBeFalse();
});

it('não é duplicado quando a data difere', function () {
    expect(chave(15000, 'Mercado', '2026-06-10', 3)->igualA(chave(15000, 'Mercado', '2026-06-11', 3)))
        ->toBeFalse();
});

it('não é duplicado quando a quantidade de parcelas difere', function () {
    expect(chave(15000, 'Mercado', '2026-06-10', 3)->igualA(chave(15000, 'Mercado', '2026-06-10', 6)))
        ->toBeFalse();
});

/* -------- Normalização da descrição -------- */

it('iguala descrições que diferem só em caixa, acento ou espaços', function () {
    $a = chave(15000, 'Padaria São João', '2026-06-10', 1);
    $b = chave(15000, '  padaria sao joao  ', '2026-06-10', 1);

    expect($a->igualA($b))->toBeTrue();
});

/* -------- A parcela atual NUNCA entra no critério -------- */

it('ignora a parcela atual: mesma compra em parcelas atuais diferentes é duplicada', function () {
    // A chave nem aceita "parcela atual" — só a quantidade total de parcelas.
    // Duas leituras da mesma compra (ex.: 2/5 e 3/5) produzem a MESMA chave.
    $leituraComoParcela2 = chave(50000, 'Notebook', '2026-01-05', 5);
    $leituraComoParcela3 = chave(50000, 'Notebook', '2026-01-05', 5);

    expect($leituraComoParcela2->igualA($leituraComoParcela3))->toBeTrue();
});

/* -------- Detector contra um conjunto existente -------- */

it('detecta duplicado contra um conjunto de lançamentos existentes', function () {
    $existentes = [
        chave(15000, 'Mercado', '2026-06-10', 3),
        chave(8000, 'Uber', '2026-06-11', 1),
    ];

    expect(DetectorDeDuplicidade::ehDuplicado(chave(8000, 'uber', '2026-06-11', 1), $existentes))->toBeTrue()
        ->and(DetectorDeDuplicidade::ehDuplicado(chave(9999, 'Farmácia', '2026-06-12', 1), $existentes))->toBeFalse();
});

it('com conjunto vazio, nada é duplicado', function () {
    expect(DetectorDeDuplicidade::ehDuplicado(chave(8000, 'Uber', '2026-06-11', 1), []))->toBeFalse();
});

/* -------- apenasNovos: filtra duplicados contra existentes e dentro do lote -------- */

it('retorna apenas os candidatos novos em relação aos existentes', function () {
    $existentes = [chave(15000, 'Mercado', '2026-06-10', 3)];
    $candidatos = [
        chave(15000, 'Mercado', '2026-06-10', 3), // duplicado
        chave(8000, 'Uber', '2026-06-11', 1),      // novo
    ];

    $novos = DetectorDeDuplicidade::apenasNovos($candidatos, $existentes);

    expect($novos)->toHaveCount(1)
        ->and($novos[0]->valorCents)->toBe(8000);
});

it('remove duplicados dentro do próprio lote, mantendo o primeiro', function () {
    $candidatos = [
        chave(8000, 'Uber', '2026-06-11', 1),
        chave(8000, 'uber', '2026-06-11', 1), // repetição no lote
        chave(9000, 'iFood', '2026-06-11', 1),
    ];

    $novos = DetectorDeDuplicidade::apenasNovos($candidatos, []);

    expect($novos)->toHaveCount(2)
        ->and(array_map(fn ($c) => $c->valorCents, $novos))->toBe([8000, 9000]);
});
