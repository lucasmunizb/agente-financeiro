<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\ContasVencidas\ConsultarContasVencidas;
use App\Domain\ContasVencidas\ResultadoConsultaContasVencidas;
use App\Domain\Disponivel\ConsultarDisponivelDoMes;
use App\Domain\Disponivel\DisponivelDoMes;
use App\Domain\Disponivel\ResultadoConsultaDisponivel;
use App\Domain\FaturaCartao\ConsultarFaturaCartao;
use App\Domain\Gastos\ConsultarGastos;
use App\Domain\ProximasContas\ConsultarProximasContas;
use App\Domain\ProximasContas\ResultadoConsultaProximasContas;
use App\Domain\Recorrencia\ConsultarOcorrencias;
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
 * Recorrências nos quadros (spec 12): uma conta fixa é uma conta como outra qualquer e aparece
 * por UMA de duas fontes, disjuntas por COMPETÊNCIA — a OCORRÊNCIA real
 * ({@see ConsultarOcorrencias}) quando a competência já foi gerada, ou a PROJEÇÃO do molde
 * ({@see ProjetarRecorrencias}) quando ainda não. A projeção exclui por `NOT EXISTS` o que já é
 * real, então a mesma conta nunca entra duas vezes. Recorrência NÃO gera lançamento, então ela
 * nunca aparece pelas consultas de parcela. Mês passado é retrato fechado: só o que existe
 * (`$agora` omitido ⇒ igual à `$ancora`).
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
        private readonly ConsultarOcorrencias $ocorrencias = new ConsultarOcorrencias,
    ) {}

    /**
     * @param  CarbonImmutable  $ancora  âncora do mês navegado (deriva a competência YYYY-MM)
     * @param  CarbonImmutable|null  $agora  "hoje" real; omitido ⇒ igual à âncora (mês corrente, sem previsão)
     */
    public function para(int $userId, CarbonImmutable $ancora, int $janelaProximasContas = 30, ?CarbonImmutable $agora = null): ResumoDoMesResultado
    {
        $agora ??= $ancora;
        $mes = $ancora->setTimezone('America/Sao_Paulo')->format('Y-m');

        // Passa "agora" para o donut incluir as recorrências previstas em mês futuro (bate com o
        // extrato); em mês corrente/passado a projeção é vazia e o resultado é o das parcelas reais.
        $gastos = $this->gastos->para($userId, $mes, agora: $agora);
        $proximasContas = $this->proximasContas->para($userId, $ancora, $janelaProximasContas);
        // Sem janela: todas as vencidas em aberto (decisão spec 06b §10).
        $contasVencidas = $this->contasVencidas->para($userId, $ancora);
        $disponivel = $this->disponivel->para($userId, $mes);

        // Uma conta fixa aparece nos quadros por UMA de duas fontes, disjuntas por COMPETÊNCIA
        // (spec 12): a OCORRÊNCIA real, quando a competência já foi gerada, ou a PROJEÇÃO do
        // molde, quando ainda não — a projeção exclui por `NOT EXISTS` o que já é real, então
        // não há como a mesma conta entrar duas vezes.
        $inicioJanela = $ancora->setTimezone('America/Sao_Paulo')->startOfDay();
        $fimJanela = $inicioJanela->addDays($janelaProximasContas);
        $previsao = $this->projetarRecorrencias->para($userId, $mes, $agora);
        $aVencer = $this->naJanelaAPagar($userId, $inicioJanela, $fimJanela, $agora);
        // Sem limite inferior, espelhando a consulta de vencidas: tudo que venceu antes da âncora.
        $emAtraso = $this->naJanelaAPagar($userId, null, $inicioJanela->subDay(), $agora);

        $proximasContas = $this->mesclarPrevistas(
            $proximasContas,
            // A projeção é por COMPETÊNCIA (é assim que ela pesa no disponível), mas o quadro
            // "a vencer" é uma JANELA de datas: a conta fixa do dia 28 não é "próxima conta"
            // no dia 5, nem a do dia 5 continua a vencer no dia 15 (essa já é atraso). E a
            // janela ATRAVESSA a virada do mês — no dia 21 ela alcança o dia 5 do mês seguinte —,
            // por isso a previsão é buscada em todas as competências que a janela toca.
            $this->naJanela(
                $this->previstasDasCompetencias($userId, $previsao, $inicioJanela, $fimJanela, $agora),
                $inicioJanela,
                $fimJanela,
            ),
            $aVencer,
        );
        $contasVencidas = $this->mesclarVencidasPrevistas($contasVencidas, $emAtraso);
        // As ocorrências REAIS do mês já foram abatidas dentro do ConsultarDisponivelDoMes
        // (agregação por competência); aqui só entra o que ainda é projeção.
        $disponivel = $this->abaterPrevistas($disponivel, $previsao->totalCents);

        // Uma fatura por cartão ativo do usuário (escopo por user_id). Resolve pelo ID do
        // cartão já em mãos — final_4 não é único (dois cartões podem repetir o final) —
        // e mantém os de total 0 (decisão §10).
        $faturas = Card::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get()
            ->map(fn (Card $card) => $this->faturaCartao->paraCartao($userId, $card, $mes))
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
     * Mescla as recorrências do recorte (ocorrência real + projeção do molde) nas próximas
     * contas: marca as de lançamento com `prevista=false`, concatena, reordena por vencimento e
     * soma os totais. Nada a mesclar ⇒ só normaliza a flag (nada muda no total).
     *
     * @param  list<array<string, mixed>>  $previstas  projeções do molde que vencem na janela
     * @param  list<array<string, mixed>>  $ocorrencias  ocorrências reais a pagar na janela
     */
    private function mesclarPrevistas(
        ResultadoConsultaProximasContas $reais,
        array $previstas,
        array $ocorrencias,
    ): ResultadoConsultaProximasContas {
        $contas = array_map(
            static fn (array $conta): array => $conta + ['prevista' => false],
            $reais->contas,
        );

        $contas = [...$contas, ...$previstas, ...$this->comoContas($ocorrencias)];
        usort($contas, static fn (array $a, array $b): int => $a['vencimento'] <=> $b['vencimento']);

        return new ResultadoConsultaProximasContas(
            totalCents: $reais->totalCents + $this->somar($previstas) + $this->somar($ocorrencias),
            contas: array_values($contas),
            trace: $reais->trace,
        );
    }

    /**
     * Espelho retrospectivo de {@see mesclarPrevistas()}: junta as ocorrências que já venceram e
     * seguem sem pagamento — a conta fixa esquecida é justamente o caso que o quadro "em atraso"
     * existe para mostrar. A projeção NÃO entra aqui: competência passada sem ocorrência só
     * existe se o agendador falhou, e projetá-la inventaria uma cobrança que nunca foi gerada.
     *
     * @param  list<array<string, mixed>>  $ocorrencias  ocorrências reais já vencidas e não pagas
     */
    private function mesclarVencidasPrevistas(
        ResultadoConsultaContasVencidas $reais,
        array $ocorrencias,
    ): ResultadoConsultaContasVencidas {
        $contas = array_map(
            static fn (array $conta): array => $conta + ['prevista' => false],
            $reais->contas,
        );

        $contas = [...$contas, ...$this->comoContas($ocorrencias)];
        usort($contas, static fn (array $a, array $b): int => $a['vencimento'] <=> $b['vencimento']);

        return new ResultadoConsultaContasVencidas(
            totalCents: $reais->totalCents + $this->somar($ocorrencias),
            contas: array_values($contas),
            trace: $reais->trace,
        );
    }

    /**
     * Ocorrências REAIS de recorrência vencendo na janela que ainda são CONTA A PAGAR — os
     * quadros mostram o que falta pagar, não extrato. Cartão liquida sozinho (D3) e sai daqui
     * assim que a cobrança acontece; fora de cartão só sai no "marcar como paga".
     *
     * @return list<array<string, mixed>>
     */
    private function naJanelaAPagar(int $userId, ?CarbonImmutable $de, CarbonImmutable $ate, CarbonImmutable $agora): array
    {
        return array_values(array_filter(
            $this->ocorrencias->naJanela($userId, $de, $ate, $agora),
            static fn (array $oc): bool => $oc['status'] !== 'pago',
        ));
    }

    /**
     * Normaliza a ocorrência para a forma de "conta" dos quadros. A ocorrência É real (existe
     * no banco), então `prevista` é false — o selo é etapa de frontend (regra 3).
     *
     * @param  list<array<string, mixed>>  $ocorrencias
     * @return list<array<string, mixed>>
     */
    private function comoContas(array $ocorrencias): array
    {
        return array_map(
            static fn (array $oc): array => $oc + ['prevista' => false, 'recorrente' => true],
            $ocorrencias,
        );
    }

    /**
     * Projeções de recorrência de TODAS as competências que a janela toca. A janela do quadro é
     * de dias, não de mês: no dia 21 com 15 dias ela chega ao dia 5 do mês seguinte, e a conta
     * fixa que vence lá é justamente uma "próxima conta". A competência da âncora já veio
     * calculada (é a mesma que abate o disponível) e é reaproveitada — as demais só alimentam o
     * quadro, nunca o disponível do mês navegado.
     *
     * Cada projeção é única por (recorrência, competência) e a real é excluída por `NOT EXISTS`
     * lá dentro, então não há dupla contagem ao concatenar meses.
     *
     * @return list<array<string, mixed>>
     */
    private function previstasDasCompetencias(
        int $userId,
        ResultadoProjecaoRecorrencias $daAncora,
        CarbonImmutable $inicio,
        CarbonImmutable $fim,
        CarbonImmutable $agora,
    ): array {
        $previstas = $daAncora->ocorrencias;
        $mesDaAncora = $inicio->format('Y-m');

        for ($mes = $inicio->startOfMonth()->addMonthNoOverflow(); $mes->format('Y-m') <= $fim->format('Y-m'); $mes = $mes->addMonthNoOverflow()) {
            if ($mes->format('Y-m') === $mesDaAncora) {
                continue;
            }

            $previstas = [...$previstas, ...$this->projetarRecorrencias->para($userId, $mes->format('Y-m'), $agora)->ocorrencias];
        }

        return $previstas;
    }

    /**
     * Recorte por data de vencimento (inclusive nas duas pontas) sobre linhas já normalizadas.
     *
     * @param  list<array<string, mixed>>  $contas
     * @return list<array<string, mixed>>
     */
    private function naJanela(array $contas, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $deData = $de->toDateString();
        $ateData = $ate->toDateString();

        return array_values(array_filter(
            $contas,
            static fn (array $conta): bool => $conta['vencimento'] >= $deData && $conta['vencimento'] <= $ateData,
        ));
    }

    /** @param  list<array<string, mixed>>  $ocorrencias */
    private function somar(array $ocorrencias): int
    {
        return (int) array_sum(array_column($ocorrencias, 'cents'));
    }

    /**
     * Abate o total das previstas DO MÊS (molde + fila) do disponível, recompondo pelo calculador
     * puro {@see DisponivelDoMes} (regra 4 — a agregação só passa componentes já somados). As
     * recorrências são fora de cartão, então entram como gasto do mês vencendo. O
     * {@see ConsultarDisponivelDoMes} soma só parcelas reais, então não há dupla contagem: o que
     * ainda não é lançamento nunca passou por lá. Nada previsto ⇒ resultado idêntico ao real.
     */
    private function abaterPrevistas(
        ResultadoConsultaDisponivel $real,
        int $previstasCents,
    ): ResultadoConsultaDisponivel {
        if ($previstasCents === 0) {
            return $real;
        }

        $gastosComPrevistas = $real->gastosDoMesCents + $previstasCents;

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
