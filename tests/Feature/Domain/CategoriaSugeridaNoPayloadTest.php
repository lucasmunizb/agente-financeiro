<?php

use App\Domain\Confirmacao\PayloadDoGasto;
use App\Domain\Gasto\DadosGastoManual;
use Carbon\CarbonImmutable;

/*
 * A flag "categoria sugerida pela IA" (pré-seleção a confirmar) precisa sobreviver ao
 * round-trip do payload de confirmação — senão a mensagem/tela (frontend, etapa depois) não
 * saberia distinguir a sugestão da IA de uma categoria resolvida por regra aprendida.
 */

function dadosComSugestao(bool $sugerida): DadosGastoManual
{
    return new DadosGastoManual(
        userId: 7,
        descricao: 'cinema',
        valorTotalCents: 3500,
        dataCompra: CarbonImmutable::parse('2026-06-26', 'America/Sao_Paulo'),
        paymentMethodId: 1,
        categoriaId: 42,
        categoriaSugeridaPorIa: $sugerida,
    );
}

it('preserva a flag de sugestão da IA no round-trip do PayloadDoGasto', function () {
    $reidratado = PayloadDoGasto::paraDados(
        PayloadDoGasto::paraArray(dadosComSugestao(true)),
        7,
    );

    expect($reidratado->categoriaId)->toBe(42)
        ->and($reidratado->categoriaSugeridaPorIa)->toBeTrue();
});

it('mantém a flag falsa quando a categoria não veio da IA', function () {
    $reidratado = PayloadDoGasto::paraDados(
        PayloadDoGasto::paraArray(dadosComSugestao(false)),
        7,
    );

    expect($reidratado->categoriaSugeridaPorIa)->toBeFalse();
});

it('assume flag falsa em payloads legados sem a chave', function () {
    $legado = PayloadDoGasto::paraArray(dadosComSugestao(true));
    unset($legado['categoriaSugeridaPorIa']);

    expect(PayloadDoGasto::paraDados($legado, 7)->categoriaSugeridaPorIa)->toBeFalse();
});
