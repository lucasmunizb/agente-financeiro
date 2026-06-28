<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

/**
 * Contrato do parser de fatura por banco (spec 07 §6). Determinístico: extrai os
 * lançamentos (descrição, valor em centavos, data, parcelas) do texto, IGNORANDO todo
 * dado sensível. A IA nunca produz número aqui (regra 4). O pipeline depende só desta
 * interface — cada banco tem sua implementação; o MVP mira o Itaú.
 */
interface ParserDeFatura
{
    /**
     * @return array<int, LancamentoExtraido>
     */
    public function interpretar(TextoExtraido $texto): array;
}
