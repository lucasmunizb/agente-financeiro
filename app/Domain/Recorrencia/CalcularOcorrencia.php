<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Vencimento\CalculadoraDeVencimento;
use App\Models\Recurrence;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Cálculo PURO de uma ocorrência mensal (spec 12): dado o molde e o MÊS DE ORIGEM (o mês em
 * que o dia do molde cai), devolve data de cobrança, vencimento e competência.
 *
 * Fora de cartão: `vencimento = dataCobranca` (o dia do molde, clampado ao fim do mês via
 * {@see OcorrenciaMensal}) e a competência é o próprio mês de origem.
 *
 * Em cartão: a cobrança acontece no dia do molde, mas a CONTA vence junto com a fatura em que
 * ela caiu ({@see CalculadoraDeVencimento::cartao}, doc 03 §4.2) — então a competência passa a
 * ser a do vencimento resultante, que pode ser um ou dois meses à frente. Nenhum relógio global
 * é lido; datas no calendário de São Paulo (regras 4 e 5).
 */
final class CalcularOcorrencia
{
    /** @param  string  $mesOrigem  YYYY-MM em que o dia do molde cai (não necessariamente a competência) */
    public function para(Recurrence $recorrencia, string $mesOrigem): OcorrenciaCalculada
    {
        $inicio = CarbonImmutable::createFromFormat('!Y-m-d', $mesOrigem.'-01', RelativeDate::TIMEZONE);
        $dataCobranca = OcorrenciaMensal::aPartirDe($recorrencia->dia, $inicio);

        if ($recorrencia->card_id === null) {
            return new OcorrenciaCalculada(
                dataCobranca: $dataCobranca,
                vencimento: CalculadoraDeVencimento::foraDeCartao($dataCobranca),
                competencia: $mesOrigem,
            );
        }

        $card = $recorrencia->card;

        if ($card === null) {
            // FK existe mas o cartão sumiu: recusa em vez de inventar um vencimento (regra 4).
            throw new RuntimeException("Recorrência {$recorrencia->id} aponta para um cartão inexistente.");
        }

        $vencimento = CalculadoraDeVencimento::cartao(
            $dataCobranca,
            (int) $card->dia_fechamento,
            (int) $card->dia_vencimento,
        );

        return new OcorrenciaCalculada(
            dataCobranca: $dataCobranca,
            vencimento: $vencimento,
            competencia: $vencimento->format('Y-m'),
        );
    }
}
