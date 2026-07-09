<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Carbon\CarbonImmutable;

/**
 * Relógio real: devolve o instante atual. Respeita `Carbon::setTestNow()`, então os
 * testes controlam o tempo sem tocar no serviço que o consome.
 */
final class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
