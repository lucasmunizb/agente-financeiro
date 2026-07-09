<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use App\Models\AuditLog;
use App\Models\Installment;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Marca UMA parcela como paga (F2 — CRUD, decisão do usuário 2026-07-08).
 *
 * Ação por parcela, exclusiva de lançamentos FORA DE CARTÃO (cartão é quitado
 * pela fatura — §4.3). Grava `pago` + `data_pagamento` só na parcela alvo, sem
 * tocar nas irmãs, e reavalia o status agregado da transação de forma
 * determinística (todas pagas → pago; alguma paga → pago_parcial). Escopo
 * estrito por usuário. Registra auditoria. A IA nunca passa por aqui.
 */
final class RegistrarPagamentoParcela
{
    private const ORIGEM = 'manual';

    public function confirmar(int $installmentId, int $userId, CarbonImmutable $dataPagamento): Installment
    {
        $parcela = Installment::query()
            ->whereHas('transaction', fn ($q) => $q->where('user_id', $userId))
            ->with('transaction')
            ->findOrFail($installmentId);

        if ($parcela->transaction->card_id !== null) {
            throw PagamentoNaoPermitidoException::ehCartao();
        }

        $pago = StatusPagamento::idFor(StatusPagamento::PAGO);
        $antes = ['status_id' => $parcela->status_id, 'data_pagamento' => $parcela->data_pagamento?->toDateString()];

        return DB::transaction(function () use ($parcela, $userId, $pago, $dataPagamento, $antes) {
            $parcela->update([
                'status_id' => $pago,
                'data_pagamento' => $dataPagamento->toDateString(),
            ]);

            $this->reavaliarStatusDaTransacao($parcela->transaction, $pago);

            AuditLog::create([
                'user_id' => $userId,
                'entidade' => 'installment',
                'entidade_id' => $parcela->id,
                'acao' => AuditLog::ACAO_PAGAR,
                'antes' => $antes,
                'depois' => ['status_id' => $pago, 'data_pagamento' => $dataPagamento->toDateString()],
                'origem' => self::ORIGEM,
            ]);

            return $parcela->fresh();
        });
    }

    /**
     * Status agregado da transação, derivado das parcelas: todas pagas → pago;
     * ao menos uma paga → pago_parcial. Determinístico (§4.4).
     */
    private function reavaliarStatusDaTransacao(Transaction $transaction, int $pago): void
    {
        $total = $transaction->installments()->count();
        $pagas = $transaction->installments()->where('status_id', $pago)->count();

        $novo = $pagas === $total
            ? $pago
            : StatusPagamento::idFor(StatusPagamento::PAGO_PARCIAL);

        $transaction->update(['status_id' => $novo]);
    }
}
