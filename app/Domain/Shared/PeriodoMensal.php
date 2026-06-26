<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Calendar\RelativeDate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Janela [início, fim] de um mês civil no fuso base (America/Sao_Paulo).
 *
 * Parseia "YYYY-MM" e expõe os limites do mês — base determinística para somar
 * receitas e gastos do mês (doc 03 §4.5). Imutável.
 */
final class PeriodoMensal
{
    private function __construct(
        public readonly string $mes,
        public readonly CarbonImmutable $inicio,
        public readonly CarbonImmutable $fim,
    ) {
    }

    public static function fromString(string $mes): self
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) !== 1) {
            throw new InvalidArgumentException("Mês inválido (esperado YYYY-MM): \"{$mes}\".");
        }

        $inicio = CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$mes}-01 00:00:00", RelativeDate::TIMEZONE)
            ->startOfMonth();

        return new self($mes, $inicio, $inicio->endOfMonth());
    }

    /**
     * Pertinência por data civil (ignora hora/fuso): a data está no mês se seu
     * ano-mês coincide com o do período.
     */
    public function contem(CarbonImmutable $data): bool
    {
        return $data->format('Y-m') === $this->mes;
    }
}
