<?php

use App\Domain\Importacao\ParserDeFatura;
use App\Domain\Importacao\ParserItau;
use App\Domain\Importacao\ParserNaoImplementadoException;
use App\Domain\Importacao\TextoExtraido;

/*
 * ParserItau (spec 07 §6) — a REGRA de identificar os lançamentos da fatura do Itaú
 * fica DELIBERADAMENTE pendente para depois do frontend. Por ora é um stub explícito:
 * cumpre o contrato ParserDeFatura, mas sinaliza claramente "não implementado" para
 * não fingir extração. Todo o pipeline é exercitado com um parser fake nos testes.
 */

it('implementa o contrato ParserDeFatura', function () {
    expect(new ParserItau)->toBeInstanceOf(ParserDeFatura::class);
});

it('ainda não interpreta a fatura — sinaliza pendência de forma explícita', function () {
    (new ParserItau)->interpretar(new TextoExtraido('qualquer texto', viaOcr: false));
})->throws(ParserNaoImplementadoException::class);
