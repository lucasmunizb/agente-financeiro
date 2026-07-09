<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Recorrencia\MaterializarRecorrencias;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Borda fina (agendada) da recorrência mensal (spec 10). Resolve "hoje" no fuso de São Paulo
 * e delega ao domínio {@see MaterializarRecorrencias}, que enfileira uma confirmação por
 * recorrência vencível (regra 7 — nada grava sozinho). Sem dado sensível na saída: só a
 * contagem de ocorrências enfileiradas.
 */
class MaterializarRecorrenciasCommand extends Command
{
    protected $signature = 'recorrencia:materializar';

    protected $description = 'Enfileira as confirmações das recorrências mensais vencíveis hoje';

    public function handle(MaterializarRecorrencias $materializar): int
    {
        $enfileiradas = $materializar->paraTodos(CarbonImmutable::now('America/Sao_Paulo'));

        $this->info("Ocorrências de recorrência enfileiradas: {$enfileiradas}");

        return self::SUCCESS;
    }
}
