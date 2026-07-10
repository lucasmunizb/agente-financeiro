<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\IA\Consulta\TraceDaConsulta;

/**
 * Resultado da PROJEÇÃO de recorrências para um mês futuro (spec 10b). VO imutável, read-only:
 * carrega as ocorrências PREVISTAS (nunca gravadas) de um mês-alvo — cada uma com valor em
 * centavos (o "molde" da recorrência, regra 5) e a data de vencimento já resolvida/clampada —
 * mais o total pré-somado e o {@see TraceDaConsulta} (fonte). A flag `prevista` marca a linha
 * como projeção (o valor real pode diferir na confirmação); a formatação pt-BR é frontend.
 */
final class ResultadoProjecaoRecorrencias
{
    /**
     * @param  list<array{descricao: string, vencimento: string, cents: int, prevista: true, categoriaId: ?int, categoria: ?array{nome: string, cor: ?string}, forma: ?string}>  $ocorrencias  ordenada por vencimento asc
     */
    public function __construct(
        public readonly int $totalCents,
        public readonly array $ocorrencias,
        public readonly TraceDaConsulta $trace,
    ) {}
}
