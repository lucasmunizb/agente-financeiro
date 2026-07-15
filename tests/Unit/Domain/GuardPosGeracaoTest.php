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

/* -------- Formatos coloquiais — bypasses da auditoria P1-1 -------- */

it('bloqueia "1500 reais" divergente (dígitos com sufixo por extenso)', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Você gastou 1500 reais este mês.',
        payload(valoresEmCentavos: [3590]),
    );

    expect($resultado->aprovado)->toBeFalse();
});

it('aprova "1500 reais" quando o valor existe no payload', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Você gastou 1500 reais este mês.',
        payload(valoresEmCentavos: [150000]),
    );

    expect($resultado->aprovado)->toBeTrue();
});

it('bloqueia "1 real" divergente', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Sobrou 1 real na conta.',
        payload(valoresEmCentavos: [3590]),
    );

    expect($resultado->aprovado)->toBeFalse();
});

it('valida "R$ 35,9" (um dígito decimal) como 35,90 — não como 35,00', function () {
    // Com payload contendo só 3500, "R$ 35,9" (=3590) tem de reprovar.
    $resultado = (new GuardPosGeracao)->validar(
        'Foram R$ 35,9 na farmácia.',
        payload(valoresEmCentavos: [3500]),
    );

    expect($resultado->aprovado)->toBeFalse();

    $aprovado = (new GuardPosGeracao)->validar(
        'Foram R$ 35,9 na farmácia.',
        payload(valoresEmCentavos: [3590]),
    );

    expect($aprovado->aprovado)->toBeTrue();
});

it('bloqueia "R$ 1500" (sem centavos e sem ponto de milhar) divergente', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Sua fatura fechou em R$ 1500.',
        payload(valoresEmCentavos: [3590]),
    );

    expect($resultado->aprovado)->toBeFalse();
});

it('aprova "R$ 1500" quando 150000 centavos está no payload', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Sua fatura fechou em R$ 1500.',
        payload(valoresEmCentavos: [150000]),
    );

    expect($resultado->aprovado)->toBeTrue();
});

it('bloqueia valor por extenso "mil e quinhentos reais" divergente', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Você gastou mil e quinhentos reais este mês.',
        payload(valoresEmCentavos: [3590]),
    );

    expect($resultado->aprovado)->toBeFalse();
});

it('aprova valor por extenso quando o número existe no payload', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Você gastou mil e quinhentos reais este mês.',
        payload(valoresEmCentavos: [150000]),
    );

    expect($resultado->aprovado)->toBeTrue();
});

it('valida "dois reais e cinquenta centavos" como 250 centavos', function () {
    $reprova = (new GuardPosGeracao)->validar(
        'O café custou dois reais e cinquenta centavos.',
        payload(valoresEmCentavos: [200]),
    );

    $aprova = (new GuardPosGeracao)->validar(
        'O café custou dois reais e cinquenta centavos.',
        payload(valoresEmCentavos: [250]),
    );

    expect($reprova->aprovado)->toBeFalse()
        ->and($aprova->aprovado)->toBeTrue();
});

it('valida "cinquenta centavos" isolado como 50 centavos', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Faltaram cinquenta centavos.',
        payload(valoresEmCentavos: [3590]),
    );

    expect($resultado->aprovado)->toBeFalse();
});

it('não inventa valor a partir de "reais" sem número ("vários reais")', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Foram vários reais economizados.',
        payload(),
    );

    expect($resultado->aprovado)->toBeTrue();
});

it('bloqueia data por extenso "cinco de junho" ausente do payload', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'A conta vence em cinco de junho.',
        payload(datasIso: ['2026-07-10']),
    );

    expect($resultado->aprovado)->toBeFalse();
});

it('aprova "5 de junho de 2026" presente no payload', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'A conta vence em 5 de junho de 2026.',
        payload(datasIso: ['2026-06-05']),
    );

    expect($resultado->aprovado)->toBeTrue();
});

it('aprova "vinte e cinco de dezembro" presente no payload', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'O vencimento cai em vinte e cinco de dezembro.',
        payload(datasIso: ['2026-12-25']),
    );

    expect($resultado->aprovado)->toBeTrue();
});

it('não trata "depois de junho" como data', function () {
    $resultado = (new GuardPosGeracao)->validar(
        'Isso só muda depois de junho.',
        payload(),
    );

    expect($resultado->aprovado)->toBeTrue();
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
