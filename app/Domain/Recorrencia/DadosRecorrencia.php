<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Models\Recurrence;

/**
 * Dados de entrada para cadastrar uma recorrência mensal (spec 10). DTO imutável
 * validado/traduzido na borda (Form Request) antes de chegar ao domínio. `valorCents` em
 * centavos (regra 5); `dia` é o dia-do-mês (clampado na borda do mês pelo {@see OcorrenciaMensal}).
 * Recorrência é sempre FORA DE CARTÃO — não há `cardId` nem `parcelas`.
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
        public readonly string $periodicidade = Recurrence::PERIODICIDADE_MENSAL,
    ) {}
}
