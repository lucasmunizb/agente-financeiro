<?php

declare(strict_types=1);

namespace App\Domain\Interacao;

use App\Ai\Agents\ClassificadorDeIntencao;
use App\Ai\Agents\ExtratorDeGasto;
use App\Domain\Chat\ResponderNoChat;
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
use App\Domain\Telegram\Resposta\ResultadoDaInteracao;
use App\Jobs\ProcessarMensagemDoBot;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Orquestração determinística de UMA mensagem do usuário (Blocos 4/5), independente de
 * canal. É o núcleo compartilhado entre o bot do Telegram ({@see ProcessarMensagemDoBot})
 * e o chat financeiro na web ({@see ResponderNoChat}): a MESMA lógica de
 * confirmação, classificação e registro/consulta roda nos dois — sem duplicar regra.
 *
 * Devolve o {@see ResultadoDaInteracao} de domínio (objetos já calculados; a IA nunca
 * calcula dinheiro — regra 4). A redação/apresentação (texto do bot ou bolha do chat) é
 * frontend (regra 3), feita por cada canal a partir deste resultado.
 *
 * Precedência (spec 04b §6.d): havendo uma confirmação pendente, a mensagem é a resposta
 * sim/não/ambígua (determinística) — só assim "sim" grava, nunca por engano (regra 7).
 */
final class ProcessarInteracao
{
    private const TZ = 'America/Sao_Paulo';

    public function __construct(
        private readonly ClassificadorDeIntencao $classificador,
        private readonly ExtratorDeGasto $extrator,
        private readonly PrepararConfirmacaoDeGasto $preparar,
        private readonly ResponderConsulta $responder,
        private readonly ConfirmacoesPendentes $pendentes,
        private readonly InterpretadorDeConfirmacao $interpretador,
        private readonly ConfirmarGastoPendente $confirmar,
    ) {}

    public function processar(User $user, ComandoRecebido $comando, ?Intencao $forcada = null): ResultadoDaInteracao
    {
        $agora = CarbonImmutable::now(self::TZ);

        // Confirmação tem precedência: havendo um pendente, a mensagem é a resposta sim/não.
        $pendente = $this->pendentes->recuperar($user->id, $agora);
        if ($pendente !== null) {
            return $this->resolverConfirmacao($pendente, $comando, $user->id, $agora);
        }

        $texto = $this->textoParaIA($comando);

        $intencao = $forcada ?? $this->classificador->classificar($comando->textoOriginal);

        return match ($intencao) {
            Intencao::REGISTRAR => $this->registrar($comando, $user->id, $texto, $agora),
            Intencao::CONSULTAR => ResultadoDaInteracao::consulta($this->responder->responder($user, $texto)),
            default => ResultadoDaInteracao::naoEntendi(),
        };
    }

    /**
     * Resolve a resposta a uma confirmação pendente: "sim" grava (reusa o domínio),
     * "não" descarta, e o ambíguo mantém o pendente e pede de novo (barreira 1).
     */
    private function resolverConfirmacao(
        ConfirmacaoDeGasto $pendente,
        ComandoRecebido $comando,
        int $userId,
        CarbonImmutable $agora,
    ): ResultadoDaInteracao {
        return match ($this->interpretador->interpretar($comando->textoOriginal)) {
            RespostaDeConfirmacao::SIM => $this->gravarConfirmado($userId, $agora),
            RespostaDeConfirmacao::NAO => $this->descartar($userId),
            RespostaDeConfirmacao::INDEFINIDO => ResultadoDaInteracao::confirmacaoAmbigua($pendente),
        };
    }

    private function gravarConfirmado(int $userId, CarbonImmutable $agora): ResultadoDaInteracao
    {
        $transaction = $this->confirmar->confirmar($userId, $agora);

        return $transaction !== null
            ? ResultadoDaInteracao::gravado($transaction)
            : ResultadoDaInteracao::nadaParaConfirmar();
    }

    private function descartar(int $userId): ResultadoDaInteracao
    {
        $this->pendentes->descartar($userId);

        return ResultadoDaInteracao::confirmacaoCancelada();
    }

    /**
     * Texto a interpretar pela IA: para slash conhecido, os argumentos (sem o /comando);
     * para texto livre (DESCONHECIDO), o texto original íntegro.
     */
    private function textoParaIA(ComandoRecebido $comando): string
    {
        return $comando->comando === Comando::DESCONHECIDO
            ? $comando->textoOriginal
            : $comando->argumentos;
    }

    private function registrar(
        ComandoRecebido $comando,
        int $userId,
        string $texto,
        CarbonImmutable $agora,
    ): ResultadoDaInteracao {
        $extracao = $this->extrator->extrair($texto);

        if ($extracao->precisaEsclarecer()) {
            return ResultadoDaInteracao::registro(
                new ConfirmacaoDeGasto(null, null, $extracao->camposFaltantes),
            );
        }

        $confirmacao = $this->preparar->preparar($extracao->gasto, $userId, $agora);

        // Prévia confirmável vira pendente: o próximo "sim" a persiste (regra 7, §6.b/§C6).
        if ($confirmacao->confirmavel()) {
            $this->pendentes->guardar($userId, $confirmacao, $agora);
        }

        return ResultadoDaInteracao::registro($confirmacao);
    }
}
