<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Models\Recurrence;

/**
 * Dados de entrada para cadastrar uma recorrência mensal (spec 10, revista pela spec 12). DTO
 * imutável validado/traduzido na borda (Form Request) antes de chegar ao domínio. `valorCents`
 * em centavos (regra 5); `dia` é o dia-do-mês (clampado na borda do mês pelo
 * {@see OcorrenciaMensal}).
 *
 * Cartão de crédito é PERMITIDO (spec 12, D3): quando a forma é `credito`, `cardId` é
 * obrigatório — é dele que sai o ciclo de fatura que define o vencimento e a competência da
 * ocorrência. Fora de cartão, `cardId` é sempre null. Não há `parcelas`: uma recorrência é uma
 * cobrança por mês, não um parcelamento.
 */
final class DadosRecorrencia
{
    public function __construct(
        public readonly int $userId,
        public readonly string $descricao,
        public readonly int $valorCents,
        public readonly int $paymentMethodId,
        public readonly int $dia,
        public readonly ?int $categoriaId = null,
        public readonly ?int $cardId = null,
        public readonly string $periodicidade = Recurrence::PERIODICIDADE_MENSAL,
    ) {}
}
