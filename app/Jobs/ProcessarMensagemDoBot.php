<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\ClassificadorDeIntencao;
use App\Ai\Agents\ExtratorDeGasto;
use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\IA\Consulta\ResponderConsulta;
use App\Domain\IA\Intencao;
use App\Domain\IA\PrepararConfirmacaoDeGasto;
use App\Domain\Telegram\Comando;
use App\Domain\Telegram\ComandoRecebido;
use App\Domain\Telegram\Confirmacao\ConfirmacoesPendentes;
use App\Domain\Telegram\Confirmacao\ConfirmarGastoPendente;
use App\Domain\Telegram\Confirmacao\InterpretadorDeConfirmacao;
use App\Domain\Telegram\Confirmacao\RespostaDeConfirmacao;
use App\Domain\Telegram\Resposta\RespostaAoUsuario;
use App\Domain\Telegram\Resposta\ResultadoDaInteracao;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Processamento da mensagem do bot no worker (spec 03 §10: liga os Blocos 4/5 ao
 * roteador). Fora do request (barreira §4), resolve a intenção — forçada pelo slash ou
 * classificada por IA no texto livre — e executa a orquestração determinística:
 *
 *  - REGISTRAR → extrai o gasto (IA) e prepara a confirmação SEM persistir (regra 7);
 *  - CONSULTAR → responde via chat financeiro com o guard pós-geração (barreira 4);
 *  - demais (editar/cancelar/importar/desconhecido) → fallback seguro "não entendi",
 *    até ganharem extrator/fluxo próprios.
 *
 * A IA nunca calcula dinheiro (regra 4): valores/datas saem como texto e a normalização
 * é determinística, fora da IA. O resultado de domínio é entregue à porta de saída
 * {@see RespostaAoUsuario}; a redação/envio ao Telegram é frontend (regra 3).
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

    public function handle(
        ClassificadorDeIntencao $classificador,
        ExtratorDeGasto $extrator,
        PrepararConfirmacaoDeGasto $preparar,
        ResponderConsulta $responder,
        RespostaAoUsuario $saida,
        ConfirmacoesPendentes $pendentes,
        InterpretadorDeConfirmacao $interpretador,
        ConfirmarGastoPendente $confirmar,
    ): void {
        $user = User::findOrFail($this->userId);
        $agora = CarbonImmutable::now('America/Sao_Paulo');

        // Confirmação tem precedência: havendo um pendente, a mensagem é a resposta sim/não
        // (determinística, regra 4). Só assim "sim" grava — nunca por engano (regra 7, §6.d).
        $pendente = $pendentes->recuperar($this->userId, $agora);
        if ($pendente !== null) {
            $saida->entregar($user, $this->resolverConfirmacao($pendente, $interpretador, $confirmar, $pendentes, $agora));

            return;
        }

        $texto = $this->textoParaIA();

        $intencao = $this->intencaoForcada
            ?? $classificador->classificar($this->comando->textoOriginal);

        $resultado = match ($intencao) {
            Intencao::REGISTRAR => $this->registrar($extrator, $preparar, $pendentes, $texto, $agora),
            Intencao::CONSULTAR => ResultadoDaInteracao::consulta($responder->responder($user, $texto)),
            default => ResultadoDaInteracao::naoEntendi(),
        };

        $saida->entregar($user, $resultado);
    }

    /**
     * Resolve a resposta a uma confirmação pendente: "sim" grava (reusa o domínio),
     * "não" descarta, e o ambíguo mantém o pendente e pede de novo (barreira 1).
     */
    private function resolverConfirmacao(
        ConfirmacaoDeGasto $pendente,
        InterpretadorDeConfirmacao $interpretador,
        ConfirmarGastoPendente $confirmar,
        ConfirmacoesPendentes $pendentes,
        CarbonImmutable $agora,
    ): ResultadoDaInteracao {
        return match ($interpretador->interpretar($this->comando->textoOriginal)) {
            RespostaDeConfirmacao::SIM => $this->gravarConfirmado($confirmar, $agora),
            RespostaDeConfirmacao::NAO => $this->descartar($pendentes),
            RespostaDeConfirmacao::INDEFINIDO => ResultadoDaInteracao::confirmacaoAmbigua($pendente),
        };
    }

    private function gravarConfirmado(ConfirmarGastoPendente $confirmar, CarbonImmutable $agora): ResultadoDaInteracao
    {
        $transaction = $confirmar->confirmar($this->userId, $agora);

        return $transaction !== null
            ? ResultadoDaInteracao::gravado($transaction)
            : ResultadoDaInteracao::nadaParaConfirmar();
    }

    private function descartar(ConfirmacoesPendentes $pendentes): ResultadoDaInteracao
    {
        $pendentes->descartar($this->userId);

        return ResultadoDaInteracao::confirmacaoCancelada();
    }

    /**
     * Texto a interpretar pela IA: para slash conhecido, os argumentos (sem o /comando);
     * para texto livre (DESCONHECIDO), o texto original íntegro.
     */
    private function textoParaIA(): string
    {
        return $this->comando->comando === Comando::DESCONHECIDO
            ? $this->comando->textoOriginal
            : $this->comando->argumentos;
    }

    private function registrar(
        ExtratorDeGasto $extrator,
        PrepararConfirmacaoDeGasto $preparar,
        ConfirmacoesPendentes $pendentes,
        string $texto,
        CarbonImmutable $agora,
    ): ResultadoDaInteracao {
        $extracao = $extrator->extrair($texto);

        if ($extracao->precisaEsclarecer()) {
            return ResultadoDaInteracao::registro(
                new ConfirmacaoDeGasto(null, null, $extracao->camposFaltantes),
            );
        }

        $confirmacao = $preparar->preparar($extracao->gasto, $this->userId, $agora);

        // Prévia confirmável vira pendente: o próximo "sim" a persiste (regra 7, §6.b/§C6).
        if ($confirmacao->confirmavel()) {
            $pendentes->guardar($this->userId, $confirmacao, $agora);
        }

        return ResultadoDaInteracao::registro($confirmacao);
    }
}
