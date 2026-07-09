<?php

declare(strict_types=1);

namespace App\Domain\Lancamentos;

use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;

/**
 * Detalhe de UM lançamento (FE §7.8), produzido por {@see ConsultarLancamentoDetalhe}.
 *
 * Carrega os metadados do lançamento e a lista de parcelas JÁ com o valor derivado em
 * centavos ({@see Money::allocate}, nunca persistido) e o status de
 * EXIBIÇÃO derivado por data (pago | aberto | agendado | vencido | cancelado). A tela só
 * formata em pt-BR e exibe — nunca recalcula dinheiro (regra 4/5). `status` é o status
 * geral do cabeçalho, agregado das parcelas por precedência.
 */
final class DetalheDoLancamento
{
    /**
     * @param  array{nome: string, cor: ?string}|null  $categoria
     * @param  list<array{numero: int, total: int, cents: int, vencimento: CarbonImmutable, status: string}>  $parcelas
     */
    public function __construct(
        public readonly int $transactionId,
        public readonly string $descricao,
        public readonly int $valorTotalCents,
        public readonly ?string $forma,
        public readonly bool $ehCredito,
        public readonly ?string $cartaoDescricao,
        public readonly ?string $cartaoFinal4,
        public readonly CarbonImmutable $dataCompra,
        public readonly CarbonImmutable $vencimento,
        public readonly string $origem,
        public readonly ?array $categoria,
        public readonly string $status,
        public readonly bool $temParcelaPaga,
        public readonly array $parcelas,
    ) {}
}
