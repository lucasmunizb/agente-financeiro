<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\ContasVencidas\ConsultarContasVencidas;
use App\Domain\Disponivel\ConsultarDisponivelDoMes;
use App\Domain\Disponivel\DisponivelDoMes;
use App\Domain\Disponivel\ResultadoConsultaDisponivel;
use App\Domain\FaturaCartao\ConsultarFaturaCartao;
use App\Domain\Gastos\ConsultarGastos;
use App\Domain\ProximasContas\ConsultarProximasContas;
use App\Domain\ProximasContas\ResultadoConsultaProximasContas;
use App\Domain\Recorrencia\ProjetarRecorrencias;
use App\Domain\Recorrencia\ResultadoProjecaoRecorrencias;
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
 *
 * Previsão de recorrências (spec 10b): na visão de um mês FUTURO — quando `$agora` (hoje real)
 * é distinto da `$ancora` (mês navegado) e a competência é estritamente futura — as ocorrências
 * PREVISTAS das recorrências ({@see ProjetarRecorrencias}, read-only) são mescladas nas próximas
 * contas (flag `prevista`) e ABATEM o disponível projetado. No mês corrente/passado a previsão é
 * vazia e nada muda (retrocompatível: `$agora` omitido ⇒ igual à `$ancora`).
 */
final class ResumoDoMes
{
    public function __construct(
        private readonly ConsultarGastos $gastos,
        private readonly ConsultarProximasContas $proximasContas,
        private readonly ConsultarContasVencidas $contasVencidas,
        private readonly ConsultarFaturaCartao $faturaCartao,
        private readonly ConsultarDisponivelDoMes $disponivel,
        private readonly ProjetarRecorrencias $projetarRecorrencias = new ProjetarRecorrencias,
    ) {}

    /**
     * @param  CarbonImmutable  $ancora  âncora do mês navegado (deriva a competência YYYY-MM)
     * @param  CarbonImmutable|null  $agora  "hoje" real; omitido ⇒ igual à âncora (mês corrente, sem previsão)
     */
    public function para(int $userId, CarbonImmutable $ancora, int $janelaProximasContas = 30, ?CarbonImmutable $agora = null): ResumoDoMesResultado
    {
        $agora ??= $ancora;
        $mes = $ancora->setTimezone('America/Sao_Paulo')->format('Y-m');

        $gastos = $this->gastos->para($userId, $mes);
        $proximasContas = $this->proximasContas->para($userId, $ancora, $janelaProximasContas);
        // Sem janela: todas as vencidas em aberto (decisão spec 06b §10).
        $contasVencidas = $this->contasVencidas->para($userId, $ancora);
        $disponivel = $this->disponivel->para($userId, $mes);

        // Só em meses estritamente futuros a projeção é não-vazia; caso contrário as mesclas
        // abaixo são no-op e o resultado é idêntico ao das consultas reais (retrocompat).
        $previsao = $this->projetarRecorrencias->para($userId, $mes, $agora);
        $proximasContas = $this->mesclarPrevistas($proximasContas, $previsao);
        $disponivel = $this->abaterPrevistas($disponivel, $previsao);

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

    /**
     * Mescla as ocorrências PREVISTAS nas próximas contas reais: marca as reais com
     * `prevista=false`, concatena as previstas (já com `prevista=true`), reordena por
     * vencimento e soma os totais. Previsão vazia ⇒ só normaliza a flag (nada muda no total).
     */
    private function mesclarPrevistas(
        ResultadoConsultaProximasContas $reais,
        ResultadoProjecaoRecorrencias $previsao,
    ): ResultadoConsultaProximasContas {
        $contas = array_map(
            static fn (array $conta): array => $conta + ['prevista' => false],
            $reais->contas,
        );

        $contas = [...$contas, ...$previsao->ocorrencias];
        usort($contas, static fn (array $a, array $b): int => $a['vencimento'] <=> $b['vencimento']);

        return new ResultadoConsultaProximasContas(
            totalCents: $reais->totalCents + $previsao->totalCents,
            contas: array_values($contas),
            trace: $reais->trace,
        );
    }

    /**
     * Abate o total das previstas do disponível projetado, recompondo pelo calculador puro
     * {@see DisponivelDoMes} (regra 4 — a agregação só passa componentes já somados). As
     * recorrências são fora de cartão, então entram como gasto do mês vencendo. Previsão
     * vazia ⇒ resultado idêntico ao real.
     */
    private function abaterPrevistas(
        ResultadoConsultaDisponivel $real,
        ResultadoProjecaoRecorrencias $previsao,
    ): ResultadoConsultaDisponivel {
        if ($previsao->totalCents === 0) {
            return $real;
        }

        $gastosComPrevistas = $real->gastosDoMesCents + $previsao->totalCents;

        $disponivel = DisponivelDoMes::calcular(
            receitasCents: $real->receitasCents,
            cartaoVencendoNoMesCents: $gastosComPrevistas,
            foraDeCartaoCents: 0,
            cobrancasProximoMesCents: $real->disponivel->previstoProximoMes->cents(),
        );

        return new ResultadoConsultaDisponivel(
            receitasCents: $real->receitasCents,
            gastosDoMesCents: $gastosComPrevistas,
            disponivel: $disponivel,
            trace: $real->trace,
        );
    }
}
