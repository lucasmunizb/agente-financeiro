<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use Carbon\CarbonImmutable;

/**
 * Resultado do cálculo de UMA ocorrência mensal (spec 12) — VO imutável, sem persistência.
 *
 * As duas datas são conceitos distintos e só coincidem fora de cartão:
 *  - `dataCobranca`: quando o dinheiro sai — o dia do molde no mês de origem. É o gatilho da
 *    liquidação automática de cartão (D3);
 *  - `vencimento`: quando a conta vence — o dia do molde fora de cartão, ou o vencimento da
 *    FATURA em que a cobrança caiu quando é crédito.
 *
 * `competencia` (YYYY-MM) é sempre derivada do `vencimento` — é o mês em que a conta pesa no
 * disponível (§4.5), que no cartão pode ser posterior ao mês da cobrança.
 */
final readonly class OcorrenciaCalculada
{
    public function __construct(
        public CarbonImmutable $dataCobranca,
        public CarbonImmutable $vencimento,
        public string $competencia,
    ) {}
}
