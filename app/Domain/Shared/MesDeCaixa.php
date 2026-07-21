<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Gasto\RegistrarPagamentoParcela;
use App\Domain\Recorrencia\LiquidarOcorrenciasDeCartao;
use App\Http\Requests\RegistrarGastoRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * MÊS DE CAIXA (decisão do usuário 2026-07-21) — a que mês um gasto pertence.
 *
 * Regra: **pago fora de cartão ⇒ o mês em que o dinheiro saiu**; qualquer outro caso ⇒ o mês
 * do vencimento (parcela) ou da competência (ocorrência de conta fixa). Antes tudo pertencia ao
 * mês do vencimento (doc 03 §4.5): quitar em julho a conta fixa de agosto inflava agosto sem
 * tocar julho, e pagar em julho a conta de junho mantinha o peso num mês já encerrado.
 *
 * Duas fronteiras deliberadas:
 *  - **Cartão nunca migra.** A compra não se quita sozinha: quem paga é a fatura, e o mês do
 *    gasto é o da fatura (§4.3/§4.5). A ocorrência de cartão ainda é liquidada pelo agendador
 *    ({@see LiquidarOcorrenciasDeCartao}, D3) na data da COBRANÇA —
 *    seguir essa data jogaria a assinatura para um mês antes do da fatura que a cobra.
 *  - **Enquanto não é paga, a conta continua no mês do vencimento.** Ali ela é previsão, e é
 *    isso que os quadros "a vencer"/"em atraso" mostram.
 *
 * Só expressões de recorte — nenhuma soma acontece aqui (regra 4: quem soma são as consultas
 * determinísticas). `installments.data_pagamento` é `date` (sem fuso);
 * `recurrence_occurrences.data_pagamento` é `timestamptz` e por isso é convertida para o
 * calendário de São Paulo antes de virar mês (regra 5).
 */
final class MesDeCaixa
{
    /**
     * Recorta parcelas pelo mês de caixa: a data do pagamento quando houver, senão a do
     * vencimento (inclusive nas duas pontas).
     *
     * Sem `CASE` por cartão de propósito: parcela em cartão nunca tem `data_pagamento` — a
     * borda recusa ({@see RegistrarGastoRequest}) e o domínio também
     * ({@see RegistrarPagamentoParcela}). Se um dia a fatura passar a escrever essa coluna
     * (spec 09), esta expressão precisa do `CASE`.
     */
    public static function parcelasNoMes(Builder $query, PeriodoMensal $periodo): Builder
    {
        return $query->whereRaw(
            'COALESCE(installments.data_pagamento, installments.vencimento) BETWEEN ? AND ?',
            [$periodo->inicio->toDateString(), $periodo->fim->toDateString()],
        );
    }

    /**
     * Recorta ocorrências pela competência de caixa (YYYY-MM): o mês do pagamento quando a
     * conta foi paga fora de cartão; senão, a competência gravada.
     */
    public static function ocorrenciasNoMes(Builder $query, string $mes): Builder
    {
        return $query->whereRaw(self::SQL_COMPETENCIA_DE_CAIXA.' = ?', [$mes]);
    }

    private const SQL_COMPETENCIA_DE_CAIXA = <<<'SQL'
        CASE
            WHEN recurrence_occurrences.card_id IS NULL AND recurrence_occurrences.data_pagamento IS NOT NULL
                THEN to_char(recurrence_occurrences.data_pagamento AT TIME ZONE 'America/Sao_Paulo', 'YYYY-MM')
            ELSE recurrence_occurrences.competencia
        END
        SQL;
}
