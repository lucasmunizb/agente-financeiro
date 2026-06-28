<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

use Carbon\CarbonImmutable;

/**
 * Um lançamento extraído da fatura (spec 07 §6). Por construção contém APENAS dados
 * não sensíveis: descrição, valor (centavos inteiros, regra 5), data e nº de parcelas.
 * Nome, endereço, CPF e nascimento nunca entram aqui. Inerte: não calcula dinheiro.
 */
final class LancamentoExtraido
{
    public function __construct(
        public readonly string $descricao,
        public readonly int $valorCents,
        public readonly CarbonImmutable $data,
        public readonly int $parcelas = 1,
    ) {}
}
