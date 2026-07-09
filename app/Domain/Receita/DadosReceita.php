<?php

declare(strict_types=1);

namespace App\Domain\Receita;

use App\Models\Income;
use Carbon\CarbonImmutable;

/**
 * Dados de entrada para cadastrar uma receita (spec FE §7.10). DTO imutável validado/traduzido
 * na borda (Form Request). Valor em centavos (regra 5); `tipo` é fixa/variável ({@see Income});
 * `data` é a data de recebimento (base do "disponível do mês", §4.5).
 */
final class DadosReceita
{
    public function __construct(
        public readonly int $userId,
        public readonly string $descricao,
        public readonly int $valorCents,
        public readonly CarbonImmutable $data,
        public readonly string $tipo = Income::TIPO_FIXA,
    ) {}
}
