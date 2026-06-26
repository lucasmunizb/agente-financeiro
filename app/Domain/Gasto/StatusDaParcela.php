<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use App\Models\StatusPagamento;
use Carbon\CarbonImmutable;

/**
 * Deriva o status inicial de uma parcela pela sua data de vencimento (§4.4).
 *
 * Decisão do projeto: vencimento futuro → agendado; vence hoje → aberto; já
 * venceu → vencido. Determinístico (o "hoje" é injetado). Pagamento é ação
 * posterior — o cadastro nunca marca "pago".
 */
final class StatusDaParcela
{
    public static function para(CarbonImmutable $vencimento, CarbonImmutable $hoje): string
    {
        $venc = $vencimento->startOfDay();
        $hojeData = $hoje->startOfDay();

        if ($venc->greaterThan($hojeData)) {
            return StatusPagamento::AGENDADO;
        }

        if ($venc->equalTo($hojeData)) {
            return StatusPagamento::ABERTO;
        }

        return StatusPagamento::VENCIDO;
    }
}
