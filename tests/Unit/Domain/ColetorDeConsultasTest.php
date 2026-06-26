<?php

use App\Domain\IA\Consulta\ColetorDeConsultas;
use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\IA\Guard\PayloadDeResposta;
use Carbon\CarbonImmutable;

/*
 * Coletor do conjunto-verdade (Bloco 6). Durante uma consulta, cada tool de IA registra
 * aqui o que calculou — o `payload()` (valores/datas permitidos para o guard, barreira 4)
 * e o `trace` (fonte, barreira 5). O orquestrador lê o payload COMBINADO e as fontes ao
 * final. `combinar()` de payloads é o que permite validar o texto contra TODAS as tools
 * chamadas. Determinístico, sem DB, sem IA.
 */

it('combina vários payloads num único conjunto-verdade (valores e datas)', function () {
    $a = new PayloadDeResposta([100, 200], [CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo')]);
    $b = new PayloadDeResposta([300], [CarbonImmutable::parse('2026-07-05', 'America/Sao_Paulo')]);

    $combinado = PayloadDeResposta::combinar($a, $b);

    expect($combinado->permiteValor(100))->toBeTrue()
        ->and($combinado->permiteValor(300))->toBeTrue()
        ->and($combinado->permiteData(5, 7, 2026))->toBeTrue()
        ->and($combinado->permiteValor(999))->toBeFalse();
});

it('combinar sem argumentos devolve um payload vazio que não permite nada', function () {
    $vazio = PayloadDeResposta::combinar();

    expect($vazio->permiteValor(100))->toBeFalse()
        ->and($vazio->permiteData(10, 6, 2026))->toBeFalse();
});

it('acumula payloads e fontes e combina o conjunto-verdade', function () {
    $coletor = new ColetorDeConsultas;
    $coletor->registrar(
        new PayloadDeResposta([150000]),
        new TraceDaConsulta('consultar_gastos', ['periodo' => '2026-06'], 3),
    );
    $coletor->registrar(
        new PayloadDeResposta([5000]),
        new TraceDaConsulta('consultar_proximas_contas', ['janela_dias' => 30], 1),
    );

    expect($coletor->payloadCombinado()->permiteValor(150000))->toBeTrue()
        ->and($coletor->payloadCombinado()->permiteValor(5000))->toBeTrue()
        ->and($coletor->fontes())->toHaveCount(2)
        ->and($coletor->fontes()[0]->ferramenta)->toBe('consultar_gastos');
});

it('limpar zera os payloads e as fontes acumulados', function () {
    $coletor = new ColetorDeConsultas;
    $coletor->registrar(
        new PayloadDeResposta([150000]),
        new TraceDaConsulta('consultar_gastos', [], 1),
    );

    $coletor->limpar();

    expect($coletor->payloadCombinado()->permiteValor(150000))->toBeFalse()
        ->and($coletor->fontes())->toBe([]);
});
