<?php

declare(strict_types=1);

namespace App\Domain\IA\Consulta;

/**
 * "Trace" de uma consulta com escopo por usuário (doc 02 §3.2/§3.5, barreira 5).
 *
 * Toda tool de consulta devolve, além dos dados já calculados, a sua fonte: qual
 * ferramenta respondeu, com que filtros e quantos registros foram considerados.
 * É o que permite explicar como o número foi obtido. Reutilizável pelas 4 tools.
 */
final class TraceDaConsulta
{
    /**
     * @param  array<string, scalar|null>  $filtros  parâmetros efetivos da consulta (ex.: mes, categoria)
     * @param  int  $registros  quantidade de registros considerados
     */
    public function __construct(
        public readonly string $ferramenta,
        public readonly array $filtros,
        public readonly int $registros,
    ) {
    }

    /**
     * Resumo textual da fonte, para compor o payload entregue ao modelo.
     */
    public function resumo(): string
    {
        $filtros = [];
        foreach ($this->filtros as $chave => $valor) {
            if ($valor !== null && $valor !== '') {
                $filtros[] = "{$chave}={$valor}";
            }
        }

        $filtrosTexto = $filtros === [] ? 'sem filtros' : implode(', ', $filtros);

        return "fonte: {$this->ferramenta} ({$filtrosTexto}); {$this->registros} registro(s)";
    }
}
