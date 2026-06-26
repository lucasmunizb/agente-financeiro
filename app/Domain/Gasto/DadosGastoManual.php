<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use Carbon\CarbonImmutable;

/**
 * Dados de entrada para o cadastro de um gasto manual (F2.3).
 *
 * DTO imutável validado/traduzido na borda (Form Request) antes de chegar ao
 * domínio. `cardId` presente ⇒ compra em cartão (usa o vencimento do cartão);
 * ausente ⇒ fora de cartão (vence na data da compra).
 */
final class DadosGastoManual
{
    public function __construct(
        public readonly int $userId,
        public readonly string $descricao,
        public readonly int $valorTotalCents,
        public readonly CarbonImmutable $dataCompra,
        public readonly int $paymentMethodId,
        public readonly int $parcelas = 1,
        public readonly ?int $cardId = null,
        public readonly ?int $accountId = null,
        public readonly ?int $categoriaId = null,
    ) {
    }
}
