<?php

declare(strict_types=1);

namespace App\Domain\Gastos;

use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\IA\Guard\PayloadDeResposta;
use App\Domain\Shared\Money;

/**
 * Resultado da consulta `consultar_gastos` entregue por uma tool (doc 02 §3.2).
 *
 * Carrega o total e a quebra por categoria JÁ calculados pelo domínio
 * ({@see ConsultarGastos}) mais o {@see TraceDaConsulta} (fonte). O {@see payload()}
 * expõe o conjunto-verdade (total + subtotais) para o guard pós-geração (barreira 4)
 * validar a redação da IA.
 */
final class ResultadoConsultaGastos
{
    /**
     * @param  list<array{nome: string, cents: int}>  $porCategoria  quebra ordenada por valor desc
     */
    public function __construct(
        public readonly int $totalCents,
        public readonly array $porCategoria,
        public readonly TraceDaConsulta $trace,
    ) {}

    /**
     * Conjunto de valores que a resposta da IA pode citar (barreira 4, doc 02 §3.3):
     * o total e cada subtotal por categoria.
     */
    public function payload(): PayloadDeResposta
    {
        $valores = [$this->totalCents];

        foreach ($this->porCategoria as $linha) {
            $valores[] = $linha['cents'];
        }

        return new PayloadDeResposta(valoresEmCentavos: $valores);
    }

    /**
     * Payload textual entregue ao modelo: total + quebra por categoria em pt-BR + fonte.
     */
    public function paraPrompt(): string
    {
        $periodo = $this->trace->filtros['periodo'] ?? '';

        $linhas = [
            "gastos_periodo: {$periodo}",
            'total: '.Money::fromCents($this->totalCents)->formatBRL(),
        ];

        foreach ($this->porCategoria as $linha) {
            $linhas[] = "- {$linha['nome']}: ".Money::fromCents($linha['cents'])->formatBRL();
        }

        $linhas[] = $this->trace->resumo();

        return implode("\n", $linhas);
    }
}
