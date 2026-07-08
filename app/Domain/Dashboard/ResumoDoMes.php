<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\ContasVencidas\ConsultarContasVencidas;
use App\Domain\Disponivel\ConsultarDisponivelDoMes;
use App\Domain\FaturaCartao\ConsultarFaturaCartao;
use App\Domain\Gastos\ConsultarGastos;
use App\Domain\ProximasContas\ConsultarProximasContas;
use App\Models\Card;
use Carbon\CarbonImmutable;

/**
 * Agregador do dashboard (spec 06): monta o resumo do mês corrente de um usuário
 * DELEGANDO às 4 consultas determinísticas já testadas. Read-only: não soma parcelas,
 * não aplica a fórmula do disponível nem replica SQL — só compõe números já calculados
 * (regra 4). A IA não participa desta etapa.
 *
 * "Hoje" é INJETADO (nunca o relógio global) — determinismo e testabilidade (regra 5); a
 * competência (YYYY-MM) é derivada dele no fuso de São Paulo. Escopo ESTRITO por usuário,
 * herdado de cada consulta. Todo valor sai em centavos inteiros; a formatação pt-BR é
 * frontend (regra 3).
 *
 * Decisões de regra (spec §10):
 *  - "cartão atual" = TODOS os cartões ativos do usuário, inclusive os de fatura zerada
 *    (lista previsível; o frontend decide o que esconder);
 *  - janela default de próximas contas = 30 dias (parâmetro injetável).
 */
final class ResumoDoMes
{
    public function __construct(
        private readonly ConsultarGastos $gastos,
        private readonly ConsultarProximasContas $proximasContas,
        private readonly ConsultarContasVencidas $contasVencidas,
        private readonly ConsultarFaturaCartao $faturaCartao,
        private readonly ConsultarDisponivelDoMes $disponivel,
    ) {}

    public function para(int $userId, CarbonImmutable $hoje, int $janelaProximasContas = 30): ResumoDoMesResultado
    {
        $mes = $hoje->setTimezone('America/Sao_Paulo')->format('Y-m');

        $gastos = $this->gastos->para($userId, $mes);
        $proximasContas = $this->proximasContas->para($userId, $hoje, $janelaProximasContas);
        // Sem janela: todas as vencidas em aberto (decisão spec 06b §10).
        $contasVencidas = $this->contasVencidas->para($userId, $hoje);
        $disponivel = $this->disponivel->para($userId, $mes);

        // Uma fatura por cartão ativo do usuário (escopo por user_id). Resolve pelo final_4
        // — o mais específico — e mantém os de total 0 (decisão §10).
        $faturas = Card::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get()
            ->map(fn (Card $card) => $this->faturaCartao->para($userId, $card->final_4, $mes))
            ->values()
            ->all();

        return new ResumoDoMesResultado(
            mes: $mes,
            gastos: $gastos,
            proximasContas: $proximasContas,
            contasVencidas: $contasVencidas,
            disponivel: $disponivel,
            faturas: $faturas,
        );
    }
}
