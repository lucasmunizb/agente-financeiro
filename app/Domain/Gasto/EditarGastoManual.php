<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Shared\Money;
use App\Models\AuditLog;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Edição de gasto manual (F2 — CRUD). Regenera as parcelas de forma
 * determinística a partir dos novos dados (mesmo motor do cadastro), reavaliando
 * o status pela data, e registra a auditoria antes/depois.
 *
 * Bloqueia quando há parcela já finalizada (paga/parcial): regenerar apagaria o
 * histórico de pagamento. A IA nunca passa por aqui — todo cálculo é determinístico.
 */
final class EditarGastoManual
{
    private const ORIGEM = 'manual';

    /** Parcelas cujo status impede a regeneração (perda de histórico de pagamento). */
    private const FINALIZADAS = [StatusPagamento::PAGO, StatusPagamento::PAGO_PARCIAL];

    public function preview(int $transactionId, DadosGastoManual $novos, ?CarbonImmutable $hoje = null): PreviaGastoManual
    {
        $hoje ??= CarbonImmutable::now(RelativeDate::TIMEZONE);
        $this->transacaoDo($novos->userId, $transactionId); // valida posse/existência

        return new PreviaGastoManual(
            descricao: $novos->descricao,
            valorTotal: Money::fromCents($novos->valorTotalCents),
            origem: self::ORIGEM,
            ehDuplicado: false, // edição não cria novo lançamento; duplicidade não se aplica
            parcelas: (new MontadorDeParcelas)->montar($novos, $hoje),
        );
    }

    public function confirmar(int $transactionId, DadosGastoManual $novos, ?CarbonImmutable $hoje = null): Transaction
    {
        $hoje ??= CarbonImmutable::now(RelativeDate::TIMEZONE);
        $transaction = $this->transacaoDo($novos->userId, $transactionId);

        $this->garantirEditavel($transaction);

        $parcelas = (new MontadorDeParcelas)->montar($novos, $hoje);
        $antes = $this->snapshot($transaction);

        return DB::transaction(function () use ($transaction, $novos, $parcelas, $antes) {
            $transaction->update([
                'descricao' => $novos->descricao,
                'valor_total_cents' => $novos->valorTotalCents,
                'data_compra' => $novos->dataCompra->toDateString(),
                'payment_method_id' => $novos->paymentMethodId,
                'card_id' => $novos->cardId,
                'account_id' => $novos->accountId,
                'categoria_id' => $novos->categoriaId,
            ]);

            $transaction->installments()->delete();

            foreach ($parcelas as $parcela) {
                $transaction->installments()->create([
                    'numero' => $parcela->numero,
                    'total' => $parcela->total,
                    'vencimento' => $parcela->vencimento->toDateString(),
                    'status_id' => StatusPagamento::idFor($parcela->statusCodigo),
                ]);
            }

            AuditLog::create([
                'user_id' => $transaction->user_id,
                'entidade' => 'transaction',
                'entidade_id' => $transaction->id,
                'acao' => AuditLog::ACAO_EDITAR,
                'antes' => $antes,
                'depois' => $this->snapshot($transaction->refresh()) + ['parcelas' => count($parcelas)],
                'origem' => self::ORIGEM,
            ]);

            return $transaction->load('installments');
        });
    }

    private function garantirEditavel(Transaction $transaction): void
    {
        // Cancelado não se edita (auditoria P2-2): regenerar as parcelas as recriaria
        // "abertas" e o gasto voltaria a contar no Disponível com a transação cancelada.
        $cancelado = StatusPagamento::idFor(StatusPagamento::CANCELADO);

        if ($transaction->status_id === $cancelado) {
            throw EdicaoBloqueadaException::cancelado();
        }

        $finalizadas = StatusPagamento::query()
            ->whereIn('codigo', self::FINALIZADAS)
            ->pluck('id')
            ->all();

        if ($transaction->installments()->whereIn('status_id', $finalizadas)->exists()) {
            throw EdicaoBloqueadaException::parcelaPaga();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Transaction $transaction): array
    {
        return [
            'descricao' => $transaction->descricao,
            'valor_total_cents' => $transaction->valor_total_cents,
            'data_compra' => $transaction->data_compra->toDateString(),
            'payment_method_id' => $transaction->payment_method_id,
            'card_id' => $transaction->card_id,
            'account_id' => $transaction->account_id,
            'categoria_id' => $transaction->categoria_id,
        ];
    }

    private function transacaoDo(int $userId, int $transactionId): Transaction
    {
        return Transaction::where('user_id', $userId)->findOrFail($transactionId);
    }
}
