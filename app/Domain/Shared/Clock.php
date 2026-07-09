<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Carbon\CarbonImmutable;

/**
 * Relógio injetável — contrato mínimo para o "agora" do domínio.
 *
 * Nenhum serviço com estado no tempo (ex.: TTL de cooldown da rotação de provedores)
 * lê o relógio global direto: recebe este contrato para permanecer determinístico nos
 * testes (a implementação real respeita `Carbon::setTestNow()`).
 */
interface Clock
{
    public function now(): CarbonImmutable;
}
