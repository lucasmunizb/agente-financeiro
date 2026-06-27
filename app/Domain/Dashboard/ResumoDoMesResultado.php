<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Disponivel\ResultadoConsultaDisponivel;
use App\Domain\FaturaCartao\ResultadoConsultaFaturaCartao;
use App\Domain\Gastos\ResultadoConsultaGastos;
use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\ProximasContas\ResultadoConsultaProximasContas;

/**
 * VO imutável de saída do dashboard (spec 06): o resumo do mês corrente de um usuário,
 * agregando os resultados JÁ calculados pelas 4 consultas determinísticas. Não recalcula
 * nada (regra 4); todo valor é centavo inteiro (regra 5). A formatação pt-BR é frontend
 * (regra 3) — este VO não formata.
 */
final class ResumoDoMesResultado
{
    /**
     * @param  string  $mes  competência corrente no formato YYYY-MM (derivada do "hoje" injetado)
     * @param  list<ResultadoConsultaFaturaCartao>  $faturas  uma fatura por cartão do usuário (inclui zeradas)
     */
    public function __construct(
        public readonly string $mes,
        public readonly ResultadoConsultaGastos $gastos,
        public readonly ResultadoConsultaProximasContas $proximasContas,
        public readonly ResultadoConsultaDisponivel $disponivel,
        public readonly array $faturas,
    ) {}

    /** Total de gastos vencendo no mês (centavos). */
    public function totalGastosCents(): int
    {
        return $this->gastos->totalCents;
    }

    /** Total das contas a vencer na janela (centavos). */
    public function totalProximasContasCents(): int
    {
        return $this->proximasContas->totalCents;
    }

    /** Disponível do mês corrente (centavos). */
    public function disponivelCents(): int
    {
        return $this->disponivel->disponivel->disponivel->cents();
    }

    /** Soma das faturas de todos os cartões na competência (centavos). */
    public function totalFaturasCents(): int
    {
        return array_sum(array_map(
            static fn (ResultadoConsultaFaturaCartao $fatura): int => $fatura->totalCents,
            $this->faturas,
        ));
    }

    /**
     * Fontes (traces) de cada bloco — para auditoria/transparência (doc 02 §3.5).
     *
     * @return list<TraceDaConsulta>
     */
    public function traces(): array
    {
        return [
            $this->gastos->trace,
            $this->proximasContas->trace,
            $this->disponivel->trace,
            ...array_map(
                static fn (ResultadoConsultaFaturaCartao $fatura): TraceDaConsulta => $fatura->trace,
                $this->faturas,
            ),
        ];
    }
}
