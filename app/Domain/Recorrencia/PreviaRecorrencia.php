<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Gasto\PreviaGastoManual;
use App\Domain\Shared\Money;

/**
 * Pré-visualização de uma recorrência mensal antes da confirmação (regra 7), análoga à
 * {@see PreviaGastoManual}. Mostra o MOLDE que será cadastrado — não um
 * lançamento: nenhum gasto nasce agora; ele vem do materializador, no dia (spec 10).
 *
 * `formaPagamento` e `categoria` já vêm RESOLVIDOS em texto pelo domínio ({@see
 * RegistrarRecorrencia::preview()}), para a redação nunca consultar o banco — mesmo contrato
 * da prévia de gasto. Não há `ehDuplicado` (o molde é único por definição) nem parcelas: uma
 * recorrência é uma cobrança por mês (spec 12), não um parcelamento.
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
