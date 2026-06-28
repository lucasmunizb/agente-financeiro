<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

use App\Models\InvoiceImport;

/**
 * Pré-importação revisável (spec 07 §6, C6). Agrega os itens extraídos com a marcação
 * de duplicados e fica `pendente_revisao`: é INERTE — não entra em nenhum cálculo
 * (saldo, disponível, faturas) até o usuário confirmar (regra 7). É um VO puro; não
 * persiste nada por si só.
 */
final class PreImportacao
{
    /**
     * @param  array<int, ItemPreImportacao>  $itens
     */
    public function __construct(
        public readonly int $importId,
        public readonly array $itens,
        public readonly string $status = InvoiceImport::PENDENTE_REVISAO,
    ) {}

    /**
     * @return array<int, ItemPreImportacao>
     */
    public function novos(): array
    {
        return array_values(array_filter($this->itens, fn (ItemPreImportacao $i) => ! $i->duplicado));
    }

    /**
     * @return array<int, ItemPreImportacao>
     */
    public function duplicados(): array
    {
        return array_values(array_filter($this->itens, fn (ItemPreImportacao $i) => $i->duplicado));
    }
}
