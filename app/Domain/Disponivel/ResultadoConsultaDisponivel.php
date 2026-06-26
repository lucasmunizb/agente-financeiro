<?php

declare(strict_types=1);

namespace App\Domain\Disponivel;

use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\IA\Guard\PayloadDeResposta;
use App\Domain\Shared\Money;

/**
 * Resultado da consulta "disponível do mês" entregue por uma tool (doc 02 §3.2).
 *
 * Carrega os números JÁ calculados pelo motor determinístico ({@see DisponivelDoMes})
 * mais o {@see TraceDaConsulta} (fonte). O {@see payload()} expõe o conjunto-verdade
 * para o guard pós-geração (barreira 4) validar a redação da IA.
 */
final class ResultadoConsultaDisponivel
{
    public function __construct(
        public readonly int $receitasCents,
        public readonly int $gastosDoMesCents,
        public readonly ResultadoDisponivel $disponivel,
        public readonly TraceDaConsulta $trace,
    ) {
    }

    /**
     * Conjunto de valores que a resposta da IA pode citar (barreira 4, doc 02 §3.3).
     */
    public function payload(): PayloadDeResposta
    {
        return new PayloadDeResposta(valoresEmCentavos: [
            $this->receitasCents,
            $this->gastosDoMesCents,
            $this->disponivel->disponivel->cents(),
            $this->disponivel->previstoProximoMes->cents(),
        ]);
    }

    /**
     * Payload textual entregue ao modelo: números formatados em pt-BR + fonte.
     */
    public function paraPrompt(): string
    {
        $mes = $this->trace->filtros['mes'] ?? '';

        return implode("\n", [
            "disponivel_mes: {$mes}",
            'receitas: '.Money::fromCents($this->receitasCents)->formatBRL(),
            'gastos_do_mes: '.Money::fromCents($this->gastosDoMesCents)->formatBRL(),
            'disponivel: '.$this->disponivel->disponivel->formatBRL(),
            'previsto_proximo_mes: '.$this->disponivel->previstoProximoMes->formatBRL(),
            $this->trace->resumo(),
        ]);
    }
}
