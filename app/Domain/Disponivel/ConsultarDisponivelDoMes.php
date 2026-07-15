<?php

declare(strict_types=1);

namespace App\Domain\Disponivel;

use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\Orcamento\ConsumoDoMes;
use App\Domain\Receita\ReceitasDoMes;
use App\Domain\Shared\PeriodoMensal;
use App\Models\Income;
use App\Models\Installment;
use App\Models\StatusPagamento;

/**
 * Camada de consulta do "disponível do mês" (doc 02 §3.2) — a varredura do banco
 * que alimenta o calculador puro {@see DisponivelDoMes}. Escopo ESTRITO por usuário.
 *
 * Reusa os building blocks já testados: receitas recebidas no mês
 * ({@see ReceitasDoMes}) e parcelas vencendo no mês ({@see ConsumoDoMes}, mesma base
 * do disponível: cada gasto pertence a um único mês de VENCIMENTO, cartão ou fora de
 * cartão). O disponível subtrai todos os gastos vencendo no mês; o previsto soma os
 * que vencem no mês seguinte. A IA não participa — só redige sobre estes números.
 */
final class ConsultarDisponivelDoMes
{

    public function __construct(
        private readonly ReceitasDoMes $receitas,
        private readonly ConsumoDoMes $consumo,
    ) {
    }

    public function para(int $userId, string $mes): ResultadoConsultaDisponivel
    {
        $periodo = PeriodoMensal::fromString($mes);
        $proximoMes = $periodo->inicio->addMonth()->format('Y-m');

        $receitasCents = $this->receitas->para($userId, $mes);
        $gastosDoMesCents = $this->consumo->para($userId, $mes)->totalCents;
        $previstoCents = $this->consumo->para($userId, $proximoMes)->totalCents;

        // O total já agrega cartão + fora de cartão (ambos atribuídos por vencimento);
        // a fórmula subtrai o conjunto, então entra como gasto único do mês.
        $disponivel = DisponivelDoMes::calcular(
            receitasCents: $receitasCents,
            cartaoVencendoNoMesCents: $gastosDoMesCents,
            foraDeCartaoCents: 0,
            cobrancasProximoMesCents: $previstoCents,
        );

        $trace = new TraceDaConsulta(
            ferramenta: 'consultar_disponivel_mes',
            filtros: ['mes' => $mes],
            registros: $this->contarReceitas($userId, $periodo) + $this->contarParcelas($userId, $periodo),
        );

        return new ResultadoConsultaDisponivel(
            receitasCents: $receitasCents,
            gastosDoMesCents: $gastosDoMesCents,
            disponivel: $disponivel,
            trace: $trace,
        );
    }

    private function contarReceitas(int $userId, PeriodoMensal $periodo): int
    {
        return Income::query()
            ->where('user_id', $userId)
            ->whereBetween('data', [$periodo->inicio->toDateString(), $periodo->fim->toDateString()])
            ->count();
    }

    private function contarParcelas(int $userId, PeriodoMensal $periodo): int
    {
        $excluidos = StatusPagamento::query()
            ->whereIn('codigo', StatusPagamento::EXCLUIDOS)
            ->pluck('id')
            ->all();

        return Installment::query()
            ->whereBetween('vencimento', [$periodo->inicio->toDateString(), $periodo->fim->toDateString()])
            ->whereNotIn('status_id', $excluidos)
            ->whereHas('transaction', fn ($q) => $q->where('user_id', $userId))
            ->count();
    }
}
