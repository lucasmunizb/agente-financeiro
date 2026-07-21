<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use App\Models\StatusPagamento;
use App\Models\Transaction;

/**
 * Status agregado de um lançamento, derivado das suas parcelas (§4.4) — determinístico,
 * nunca informado pela borda nem pela IA.
 *
 * Compartilhado por {@see RegistrarPagamentoParcela} e {@see ReverterPagamentoParcela}: os
 * dois lados precisam da MESMA derivação, senão desmarcar deixaria o lançamento com um
 * status que contradiz as parcelas.
 *
 *  - todas as parcelas pagas → `pago`
 *  - alguma paga            → `pago_parcial`
 *  - nenhuma paga           → `aberto`
 *
 * O caso "nenhuma" só passou a existir com o estorno da marcação; antes a derivação vinha
 * do caminho de pagamento, onde havia sempre ao menos uma paga.
 */
final class StatusAgregadoDaTransacao
{
    public function reavaliar(Transaction $transaction): void
    {
        $pago = StatusPagamento::idFor(StatusPagamento::PAGO);

        $total = $transaction->installments()->count();
        $pagas = $transaction->installments()->where('status_id', $pago)->count();

        $novo = match (true) {
            $total > 0 && $pagas === $total => $pago,
            $pagas > 0 => StatusPagamento::idFor(StatusPagamento::PAGO_PARCIAL),
            default => StatusPagamento::idFor(StatusPagamento::ABERTO),
        };

        $transaction->update(['status_id' => $novo]);
    }
}
