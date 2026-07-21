<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use App\Models\AuditLog;
use App\Models\Installment;
use App\Models\StatusPagamento;
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

        // Cancelado (transação ou a própria parcela) não vira "pago" (auditoria P2-2):
        // o valor reentraria no Disponível/Consumo já tendo sido cancelado.
        $cancelado = StatusPagamento::idFor(StatusPagamento::CANCELADO);

        if ($parcela->transaction->status_id === $cancelado || $parcela->status_id === $cancelado) {
            throw PagamentoNaoPermitidoException::cancelado();
        }

        $pago = StatusPagamento::idFor(StatusPagamento::PAGO);
        $antes = ['status_id' => $parcela->status_id, 'data_pagamento' => $parcela->data_pagamento?->toDateString()];

        return DB::transaction(function () use ($parcela, $userId, $pago, $dataPagamento, $antes) {
            $parcela->update([
                'status_id' => $pago,
                'data_pagamento' => $dataPagamento->toDateString(),
            ]);

            (new StatusAgregadoDaTransacao)->reavaliar($parcela->transaction);

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
}
