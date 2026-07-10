<?php

declare(strict_types=1);

namespace App\Domain\ProximasContas;

use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\IA\Guard\PayloadDeResposta;
use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;

/**
 * Resultado da consulta `consultar_proximas_contas` entregue por uma tool (doc 02 §3.2).
 *
 * Carrega o total e a lista de contas a vencer JÁ calculados pelo domínio
 * ({@see ConsultarProximasContas}) mais o {@see TraceDaConsulta} (fonte). O
 * {@see payload()} expõe o conjunto-verdade (total + valor e vencimento de cada conta)
 * para o guard pós-geração (barreira 4) validar a redação da IA.
 */
final class ResultadoConsultaProximasContas
{
    /**
     * @param  list<array{descricao: string, vencimento: string, cents: int, recorrente?: bool}>  $contas  ordenada por vencimento asc
     */
    public function __construct(
        public readonly int $totalCents,
        public readonly array $contas,
        public readonly TraceDaConsulta $trace,
    ) {}

    /**
     * Conjunto que a resposta da IA pode citar (barreira 4, doc 02 §3.3): o total e o
     * valor de cada conta (centavos) mais os vencimentos (datas).
     */
    public function payload(): PayloadDeResposta
    {
        $valores = [$this->totalCents];
        $datas = [];

        foreach ($this->contas as $conta) {
            $valores[] = $conta['cents'];
            $datas[] = CarbonImmutable::parse($conta['vencimento'], 'America/Sao_Paulo');
        }

        return new PayloadDeResposta(valoresEmCentavos: $valores, datas: $datas);
    }

    /**
     * Payload textual entregue ao modelo: total + cada conta (vencimento em pt-BR) + fonte.
     */
    public function paraPrompt(): string
    {
        $de = $this->trace->filtros['de'] ?? '';
        $ate = $this->trace->filtros['ate'] ?? '';

        $linhas = [
            "proximas_contas: {$de} a {$ate}",
            'total: '.Money::fromCents($this->totalCents)->formatBRL(),
        ];

        foreach ($this->contas as $conta) {
            $vencimento = CarbonImmutable::parse($conta['vencimento'])->format('d/m/Y');
            $linhas[] = "- {$conta['descricao']} (vence {$vencimento}): ".Money::fromCents($conta['cents'])->formatBRL();
        }

        $linhas[] = $this->trace->resumo();

        return implode("\n", $linhas);
    }
}
