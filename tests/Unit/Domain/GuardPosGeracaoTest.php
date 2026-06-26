<?php

use App\Domain\IA\Guard\GuardPosGeracao;
use App\Domain\IA\Guard\PayloadDeResposta;
use App\Domain\IA\Guard\ResultadoDoGuard;
use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;

/*
 * Guard pós-geração — barreira 4 (doc 02 §3.3). Camada NOSSA por cima da SDK:
 * antes de enviar, o sistema extrai TODOS os valores monetários e datas do texto
 * redigido pela IA e valida que CADA UM existe no payload já calculado pelo motor
 * determinístico. Qualquer divergência BLOQUEIA (o caller regenera/usa fallback).
 * Determinístico, sem DB, sem IA.
 */

/** Atalho: payload a partir de valores em reais e datas ISO (fuso SP). */
function payload(array $valoresEmCentavos = [], array $datasIso = []): PayloadDeResposta
{
    return new PayloadDeResposta(
        valoresEmCentavos: $valoresEmCentavos,
        datas: array_map(
            fn (string $iso) => CarbonImmutable::parse($iso, 'America/Sao_Paulo'),
            $datasIso,
        ),
    );
}

/* -------- Aprovação -------- */

it('aprova texto sem nenhum número', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Tudo certo por aqui, não há contas a vencer.',
        payload(),
    );

    expect($resultado)->toBeInstanceOf(ResultadoDoGuard::class)
        ->and($resultado->aprovado)->toBeTrue()
        ->and($resultado->valoresDivergentes)->toBe([])
        ->and($resultado->datasDivergentes)->toBe([]);
});

it('aprova quando todo valor citado existe no payload', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Você gastou R$ 35,90 no mercado.',
        payload(valoresEmCentavos: [3590]),
    );

    expect($resultado->aprovado)->toBeTrue();
});

it('aprova valor com separador de milhar formatado em pt-BR', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Sua fatura fechou em R$ 1.234,56.',
        payload(valoresEmCentavos: [123456]),
    );

    expect($resultado->aprovado)->toBeTrue();
});

it('aprova valor negativo presente no payload', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'O disponível do mês ficou em -R$ 50,00.',
        payload(valoresEmCentavos: [-5000]),
    );

    expect($resultado->aprovado)->toBeTrue();
});

it('aprova quando há vários valores e todos existem no payload', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Mercado R$ 35,90 e farmácia R$ 12,00, total R$ 47,90.',
        payload(valoresEmCentavos: [3590, 1200, 4790]),
    );

    expect($resultado->aprovado)->toBeTrue();
});

it('não trata contagens não-monetárias como valor', function () {
    // "3 parcelas" e "5 dias" são inteiros sem decimal/R$ — não são dinheiro.
    $resultado = (new GuardPosGeracao)->validar(
        'Comprei em 3 parcelas e a próxima vence em 5 dias.',
        payload(),
    );

    expect($resultado->aprovado)->toBeTrue();
});

/* -------- Bloqueio de valores -------- */

it('bloqueia valor que não existe no payload e o reporta', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Você gastou R$ 99,99 no mercado.',
        payload(valoresEmCentavos: [3590]),
    );

    expect($resultado->aprovado)->toBeFalse()
        ->and($resultado->valoresDivergentes)->toContain('R$ 99,99');
});

it('bloqueia mesmo quando só um dos valores diverge, reportando apenas o divergente', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Mercado R$ 35,90 e um total inventado de R$ 88,88.',
        payload(valoresEmCentavos: [3590]),
    );

    expect($resultado->aprovado)->toBeFalse()
        ->and($resultado->valoresDivergentes)->toBe(['R$ 88,88']);
});

/* -------- Datas -------- */

it('aprova data dd/mm/aaaa presente no payload', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'A fatura vence em 10/07/2026.',
        payload(datasIso: ['2026-07-10']),
    );

    expect($resultado->aprovado)->toBeTrue();
});

it('aprova data dd/mm (sem ano) quando dia e mês batem com o payload', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'A próxima conta vence em 10/07.',
        payload(datasIso: ['2026-07-10']),
    );

    expect($resultado->aprovado)->toBeTrue();
});

it('bloqueia data que não existe no payload e a reporta', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'A fatura vence em 15/08/2026.',
        payload(datasIso: ['2026-07-10']),
    );

    expect($resultado->aprovado)->toBeFalse()
        ->and($resultado->datasDivergentes)->toContain('15/08/2026');
});

it('bloqueia quando valor e data divergem ao mesmo tempo', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Você gastou R$ 1,00 e vence em 01/01/2030.',
        payload(valoresEmCentavos: [3590], datasIso: ['2026-07-10']),
    );

    expect($resultado->aprovado)->toBeFalse()
        ->and($resultado->valoresDivergentes)->toContain('R$ 1,00')
        ->and($resultado->datasDivergentes)->toContain('01/01/2030');
});
