<?php

use App\Domain\Parcelamento\GeradorDeParcelas;
use App\Domain\Parcelamento\Parcela;
use App\Domain\Vencimento\CalculadoraDeVencimento;
use Carbon\CarbonImmutable;

/*
 * Cálculo do vencimento (doc 03 §4.2) — determinístico, sem DB.
 * - Fora de cartão (PIX/débito/dinheiro): vence na própria data da compra/parcela.
 * - Cartão: respeita SEMPRE o vencimento do cartão, via ciclo de fatura completo
 *   (compra até o fechamento entra na fatura do mês; depois, na próxima; vencimento
 *   no mesmo mês do fechamento se dia_vencimento >= dia_fechamento, senão no seguinte).
 * Este vencimento é o "primeiro vencimento" que alimenta o GeradorDeParcelas.
 */

function dia(string $data): CarbonImmutable
{
    return CarbonImmutable::parse($data, 'America/Sao_Paulo');
}

/* -------- Fora de cartão -------- */

it('fora de cartão vence na própria data da compra/parcela', function () {
    expect(CalculadoraDeVencimento::foraDeCartao(dia('2026-06-10'))->toDateString())
        ->toBe('2026-06-10');
});

it('fora de cartão normaliza para o início do dia (ignora hora)', function () {
    $venc = CalculadoraDeVencimento::foraDeCartao(dia('2026-06-10 23:45:00'));

    expect($venc->toDateString())->toBe('2026-06-10')
        ->and($venc->format('H:i:s'))->toBe('00:00:00');
});

/* -------- Cartão: vencimento >= fechamento (mesmo mês do fechamento) -------- */

it('compra antes do fechamento vence no mesmo mês', function () {
    // fechamento 20, vencimento 28
    expect(CalculadoraDeVencimento::cartao(dia('2026-01-15'), 20, 28)->toDateString())
        ->toBe('2026-01-28');
});

it('compra no dia do fechamento ainda entra na fatura que fecha no mês', function () {
    expect(CalculadoraDeVencimento::cartao(dia('2026-01-20'), 20, 28)->toDateString())
        ->toBe('2026-01-28');
});

it('compra depois do fechamento vai para a próxima fatura', function () {
    expect(CalculadoraDeVencimento::cartao(dia('2026-01-25'), 20, 28)->toDateString())
        ->toBe('2026-02-28');
});

/* -------- Cartão: vencimento < fechamento (mês seguinte ao fechamento) -------- */

it('quando o vencimento é antes do fechamento, vence no mês seguinte ao fechamento', function () {
    // fechamento 25, vencimento 05
    expect(CalculadoraDeVencimento::cartao(dia('2026-01-10'), 25, 5)->toDateString())
        ->toBe('2026-02-05');
});

it('compra após o fechamento com vencimento menor avança mais um mês', function () {
    expect(CalculadoraDeVencimento::cartao(dia('2026-01-28'), 25, 5)->toDateString())
        ->toBe('2026-03-05');
});

/* -------- Clamp de meses curtos -------- */

it('faz clamp do dia de fechamento em meses curtos', function () {
    // fechamento 31: em fevereiro o fechamento efetivo é 28
    expect(CalculadoraDeVencimento::cartao(dia('2026-02-28'), 31, 10)->toDateString())
        ->toBe('2026-03-10');
});

it('faz clamp do dia de vencimento em meses curtos', function () {
    // fechamento 10, vencimento 31; compra em fev → vencimento 28/fev
    expect(CalculadoraDeVencimento::cartao(dia('2026-02-05'), 10, 31)->toDateString())
        ->toBe('2026-02-28');
});

/* -------- Virada de ano -------- */

it('vira o ano quando a compra pós-fechamento cai em dezembro', function () {
    expect(CalculadoraDeVencimento::cartao(dia('2026-12-25'), 20, 28)->toDateString())
        ->toBe('2027-01-28');
});

/* -------- Composição com o motor de parcelas -------- */

it('o vencimento do cartão alimenta o primeiro vencimento das parcelas', function () {
    $primeiro = CalculadoraDeVencimento::cartao(dia('2026-01-25'), 20, 28); // 28/fev
    $parcelas = GeradorDeParcelas::gerar(90000, 3, $primeiro);

    expect(array_map(fn (Parcela $p) => $p->vencimento->toDateString(), $parcelas))
        ->toBe(['2026-02-28', '2026-03-28', '2026-04-28']);
});
