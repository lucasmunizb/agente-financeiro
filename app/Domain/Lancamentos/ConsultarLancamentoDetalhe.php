<?php

declare(strict_types=1);

namespace App\Domain\Lancamentos;

use App\Domain\Gasto\StatusDaParcela;
use App\Domain\Shared\Money;
use App\Models\PaymentMethod;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Consulta do DETALHE de um lançamento (FE §7.8) — leitura determinística de UMA transação
 * do usuário com suas parcelas e metadados. O valor por parcela é DERIVADO
 * ({@see Money::allocate}, nunca persistido).
 *
 * O status de EXIBIÇÃO por parcela é derivado por DATA, reusando a MESMA regra do cadastro
 * ({@see StatusDaParcela}: futuro → agendado · hoje → aberto · passado → vencido), com
 * pago/pago_parcial → pago e cancelado/estornado → cancelado — nunca o rótulo cru guardado,
 * que pode estar defasado. "Hoje" é INJETADO (determinismo/regra 4/5). Escopo ESTRITO por
 * usuário: transação de outro dono não é encontrada (404 na borda).
 */
final class ConsultarLancamentoDetalhe
{
    public const STATUS_PAGO = 'pago';

    public const STATUS_ABERTO = 'aberto';

    public const STATUS_AGENDADO = 'agendado';

    public const STATUS_VENCIDO = 'vencido';

    public const STATUS_CANCELADO = 'cancelado';

    /** Precedência do status geral do cabeçalho (mais pressionante primeiro). */
    private const PRECEDENCIA = [
        self::STATUS_VENCIDO,
        self::STATUS_ABERTO,
        self::STATUS_AGENDADO,
        self::STATUS_PAGO,
    ];

    public function para(int $userId, int $transactionId, CarbonImmutable $hoje): DetalheDoLancamento
    {
        $tx = Transaction::query()
            ->with(['installments.status', 'paymentMethod', 'card', 'categoria'])
            ->where('user_id', $userId)
            ->findOrFail($transactionId);

        $hojeData = $hoje->setTimezone('America/Sao_Paulo')->startOfDay();

        $parcelas = $tx->installments
            ->sortBy('numero')
            ->values()
            ->map(function ($parcela) use ($hojeData): array {
                // O vencimento é immutable_date em UTC; normaliza para a MESMA data em SP
                // antes de comparar/derivar (senão a fronteira "vence hoje" escorrega).
                $vencimento = CarbonImmutable::parse($parcela->vencimento->toDateString(), 'America/Sao_Paulo');

                return [
                    'numero' => (int) $parcela->numero,
                    'total' => (int) $parcela->total,
                    'cents' => $parcela->valor()->cents(),
                    'vencimento' => $vencimento,
                    'status' => $this->statusParcela((string) $parcela->status?->codigo, $vencimento, $hojeData),
                ];
            })
            ->all();

        $ehCredito = $tx->paymentMethod?->tipo === PaymentMethod::CREDITO;

        return new DetalheDoLancamento(
            transactionId: (int) $tx->id,
            descricao: (string) $tx->descricao,
            valorTotalCents: (int) $tx->valor_total_cents,
            forma: $tx->paymentMethod?->tipo,
            ehCredito: $ehCredito,
            cartaoDescricao: $ehCredito ? $tx->card?->descricao : null,
            cartaoFinal4: $ehCredito ? $tx->card?->final_4 : null,
            dataCompra: CarbonImmutable::parse($tx->data_compra->toDateString(), 'America/Sao_Paulo'),
            vencimento: $this->vencimentoPrincipal($parcelas),
            origem: (string) $tx->origem,
            categoria: $tx->categoria !== null
                ? ['nome' => (string) $tx->categoria->nome, 'cor' => $tx->categoria->cor]
                : null,
            status: $this->statusGeral($parcelas),
            temParcelaPaga: $this->temParcelaPaga($tx),
            parcelas: $parcelas,
        );
    }

    /**
     * Status de exibição de uma parcela: pago/pago_parcial → pago; cancelado/estornado →
     * cancelado; caso contrário, derivado por data ({@see StatusDaParcela}).
     */
    private function statusParcela(string $codigo, CarbonImmutable $vencimento, CarbonImmutable $hojeData): string
    {
        return match ($codigo) {
            StatusPagamento::PAGO, StatusPagamento::PAGO_PARCIAL => self::STATUS_PAGO,
            StatusPagamento::CANCELADO, StatusPagamento::ESTORNADO => self::STATUS_CANCELADO,
            default => StatusDaParcela::para($vencimento, $hojeData),
        };
    }

    /**
     * Status geral do cabeçalho: o mais pressionante entre as parcelas vivas
     * (vencido > aberto > agendado > pago). Se não há parcela viva, é cancelado.
     *
     * @param  list<array{status: string}>  $parcelas
     */
    private function statusGeral(array $parcelas): string
    {
        $status = array_column($parcelas, 'status');

        foreach (self::PRECEDENCIA as $bucket) {
            if (in_array($bucket, $status, true)) {
                return $bucket;
            }
        }

        return self::STATUS_CANCELADO;
    }

    /**
     * Vencimento principal para o cabeçalho/metadados: o da primeira parcela (nº 1).
     *
     * @param  list<array{numero: int, vencimento: CarbonImmutable}>  $parcelas
     */
    private function vencimentoPrincipal(array $parcelas): CarbonImmutable
    {
        return $parcelas[0]['vencimento'];
    }

    private function temParcelaPaga(Transaction $tx): bool
    {
        $finalizadas = StatusPagamento::query()
            ->whereIn('codigo', [StatusPagamento::PAGO, StatusPagamento::PAGO_PARCIAL])
            ->pluck('id')
            ->all();

        return $tx->installments->whereIn('status_id', $finalizadas)->isNotEmpty();
    }
}
