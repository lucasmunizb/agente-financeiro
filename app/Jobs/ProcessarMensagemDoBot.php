<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\IA\Intencao;
use App\Domain\Interacao\ProcessarInteracao;
use App\Domain\Telegram\ComandoRecebido;
use App\Domain\Telegram\Resposta\RespostaAoUsuario;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Processamento da mensagem do bot no worker (spec 03 §10: liga os Blocos 4/5 ao
 * roteador). Fora do request (barreira §4), delega a orquestração determinística ao núcleo
 * compartilhado {@see ProcessarInteracao} — o MESMO motor do chat web — e entrega o
 * resultado de domínio à porta de saída {@see RespostaAoUsuario}. A redação/envio ao
 * Telegram é frontend (regra 3). A IA nunca calcula dinheiro (regra 4).
 */
final class ProcessarMensagemDoBot implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly ComandoRecebido $comando,
        public readonly ?Intencao $intencaoForcada = null,
    ) {}

    public function handle(ProcessarInteracao $orquestrar, RespostaAoUsuario $saida): void
    {
        $user = User::findOrFail($this->userId);

        $resultado = $orquestrar->processar($user, $this->comando, $this->intencaoForcada);

        $saida->entregar($user, $resultado);
    }
}
