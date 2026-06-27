<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\IA\Historico\ExpurgarConversas;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Borda fina (agendada) do expurgo de histórico de conversa (doc 02 §3.6). Resolve "agora"
 * no fuso de São Paulo e delega ao domínio {@see ExpurgarConversas}. Sem dado sensível na
 * saída — só a contagem.
 */
class ExpurgarConversasCommand extends Command
{
    protected $signature = 'ai:expurgar-conversas';

    protected $description = 'Apaga conversas e mensagens com mais de 60 dias (histórico da IA)';

    public function handle(ExpurgarConversas $expurgo): int
    {
        $apagadas = $expurgo->executar(CarbonImmutable::now('America/Sao_Paulo'));

        $this->info("Conversas expurgadas: {$apagadas}");

        return self::SUCCESS;
    }
}
