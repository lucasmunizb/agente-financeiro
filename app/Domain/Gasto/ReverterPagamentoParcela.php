<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use App\Models\AuditLog;
use App\Models\Installment;
use App\Models\StatusPagamento;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Desfaz a MARCAÇÃO de pagamento de UMA parcela (decisão do usuário 2026-07-21).
 *
 * Inverso exato de {@see RegistrarPagamentoParcela}: apaga a `data_pagamento`, devolve a
 * parcela ao status que ela teria SEM pagamento e reavalia o status agregado do lançamento
 * ({@see StatusAgregadoDaTransacao}) — sem tocar nas irmãs. Não é estorno do gasto (isso é
 * `cancelado`): o lançamento continua devendo, ele só deixa de constar como quitado.
 *
 * O status de volta NÃO é `aberto` cravado: é o mesmo que o cadastro derivaria da data
 * ({@see StatusDaParcela} — futuro ⇒ `agendado`, hoje ⇒ `aberto`, passado ⇒ `vencido`).
 * Fixar `aberto` faria uma conta atrasada voltar como se estivesse em dia. Por isso "hoje"
 * é injetado (regras 4 e 5).
 *
 * Mesmas barreiras do pagamento: exclusivo de FORA DE CARTÃO (cartão é quitado pela fatura,
 * §4.3) e nunca reabre `cancelado` — reabrir devolveria ao Disponível/Consumo um valor que
 * já foi anulado. Escopo ESTRITO por usuário (404 para parcela alheia), idempotente (parcela
 * já aberta não muda nada nem gera auditoria) e auditado. A IA nunca passa por aqui.
 */
final class ReverterPagamentoParcela
{
    private const ORIGEM = 'manual';

    public function reverter(int $installmentId, int $userId, CarbonImmutable $hoje): Installment
    {
        $parcela = Installment::query()
            ->whereHas('transaction', fn ($q) => $q->where('user_id', $userId))
            ->with('transaction')
            ->findOrFail($installmentId);

        if ($parcela->transaction->card_id !== null) {
            throw PagamentoNaoPermitidoException::ehCartao();
        }

        $cancelado = StatusPagamento::idFor(StatusPagamento::CANCELADO);

        if ($parcela->transaction->status_id === $cancelado || $parcela->status_id === $cancelado) {
            throw PagamentoNaoPermitidoException::cancelado();
        }

        $pago = StatusPagamento::idFor(StatusPagamento::PAGO);

        // Idempotência: não estava paga ⇒ nada a desfazer (e nada a auditar).
        if ($parcela->status_id !== $pago) {
            return $parcela;
        }

        // Volta ao status que a data manda — nunca `aberto` cravado (ver docblock).
        $destino = StatusPagamento::idFor(StatusDaParcela::para($parcela->vencimento, $hoje));

        $antes = [
            'status_id' => $parcela->status_id,
            'data_pagamento' => $parcela->data_pagamento?->toDateString(),
        ];

        return DB::transaction(function () use ($parcela, $userId, $destino, $antes): Installment {
            $parcela->update([
                'status_id' => $destino,
                'data_pagamento' => null,
            ]);

            (new StatusAgregadoDaTransacao)->reavaliar($parcela->transaction);

            AuditLog::create([
                'user_id' => $userId,
                'entidade' => 'installment',
                'entidade_id' => $parcela->id,
                'acao' => AuditLog::ACAO_DESMARCAR_PAGAMENTO,
                'antes' => $antes,
                'depois' => ['status_id' => $destino, 'data_pagamento' => null],
                'origem' => self::ORIGEM,
            ]);

            return $parcela->fresh();
        });
    }
}
