<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Recorrencia\GerarOcorrencias;
use App\Domain\Recorrencia\LiquidarOcorrenciasDeCartao;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Borda fina (agendada) da recorrência mensal (spec 12) — substitui `recorrencia:materializar`.
 * Resolve "hoje" no fuso de São Paulo e delega ao domínio em dois passos:
 *
 *  1. {@see GerarOcorrencias} materializa as competências faltantes até o mês corrente
 *     (idempotente pela unique; nunca grava lançamento nem enfileira confirmação — D1);
 *  2. {@see LiquidarOcorrenciasDeCartao} marca `pago` as cobranças de CARTÃO cuja data de
 *     cobrança já chegou (D3). Fora de cartão nunca liquida sozinho.
 *
 * Sem dado sensível na saída: só contagens.
 */
class GerarOcorrenciasCommand extends Command
{
    protected $signature = 'recorrencia:gerar';

    protected $description = 'Gera as ocorrências mensais das recorrências e liquida as cobranças de cartão já debitadas';

    public function handle(GerarOcorrencias $gerar, LiquidarOcorrenciasDeCartao $liquidar): int
    {
        $hoje = CarbonImmutable::now(RelativeDate::TIMEZONE);

        $geradas = $gerar->paraTodos($hoje);
        $liquidadas = $liquidar->paraTodos($hoje);

        $this->info("Ocorrências de recorrência geradas: {$geradas}");
        $this->info("Ocorrências de cartão liquidadas: {$liquidadas}");

        return self::SUCCESS;
    }
}
