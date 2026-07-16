<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Shared\Money;

/**
 * Pré-visualização de uma recorrência mensal antes da confirmação (regra 7), análoga à
 * {@see \App\Domain\Gasto\PreviaGastoManual}. Mostra o MOLDE que será cadastrado — não um
 * lançamento: nenhum gasto nasce agora; ele vem do materializador, no dia (spec 10).
 *
 * `formaPagamento` e `categoria` já vêm RESOLVIDOS em texto pelo domínio ({@see
 * RegistrarRecorrencia::preview()}), para a redação nunca consultar o banco — mesmo contrato
 * da prévia de gasto. Não há `ehDuplicado` (o molde é único por definição) nem parcelas
 * (recorrência é sempre fora de cartão e mensal).
 */
final readonly class PreviaRecorrencia
{
    public function __construct(
        public string $descricao,
        public Money $valor,
        public int $dia,
        public string $formaPagamento,
        public ?string $categoria = null,
    ) {}
}
