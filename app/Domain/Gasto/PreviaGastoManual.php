<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;

/**
 * Pré-visualização de um gasto manual antes da confirmação (regra inviolável 7:
 * confirmação antes de persistir). Mostra o que SERÁ gravado e se há duplicidade.
 *
 * `categoria` é o NOME da categoria pré-selecionada (ou null se nenhuma) — dado de
 * apresentação já resolvido pelo domínio, para a redação não consultar o banco.
 * `categoriaSugeridaPorIa` diz a procedência: true quando a categoria veio do fallback de
 * IA (mostrar como DICA a confirmar), false quando veio de regra aprendida ou não há.
 *
 * `dataPagamento` presente ⇒ a confirmação vai gravar a 1ª parcela já como PAGA nessa data
 * (decisão 2026-07-21). Está aqui para a apresentação poder dizê-lo ANTES do "sim" — o
 * usuário precisa ver que vai marcar pago antes de confirmar (regra 7).
 */
final class PreviaGastoManual
{
    /**
     * @param  array<int, ParcelaPrevia>  $parcelas
     */
    public function __construct(
        public readonly string $descricao,
        public readonly Money $valorTotal,
        public readonly string $origem,
        public readonly bool $ehDuplicado,
        public readonly array $parcelas,
        public readonly ?string $categoria = null,
        public readonly bool $categoriaSugeridaPorIa = false,
        public readonly ?CarbonImmutable $dataPagamento = null,
    ) {}
}
