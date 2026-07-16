<?php

declare(strict_types=1);

namespace App\Domain\Interacao;

use App\Ai\Agents\ClassificadorDeIntencao;
use App\Ai\Agents\ExtratorDeGasto;
use App\Domain\Chat\ResponderNoChat;
use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\IA\Consulta\ResponderConsulta;
use App\Domain\IA\Esclarecimento\EsclarecimentosPendentes;
use App\Domain\IA\GastoParcial;
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
use App\Models\Recurrence;
use App\Models\Transaction;
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
        private readonly EsclarecimentosPendentes $esclarecimentos,
        private readonly InterpretadorDeConfirmacao $interpretador,
        private readonly ConfirmarGastoPendente $confirmar,
    ) {}

    public function processar(User $user, ComandoRecebido $comando, ?Intencao $forcada = null): ResultadoDaInteracao
    {
        $agora = CarbonImmutable::now(self::TZ);

        // Confirmação confirmável tem precedência máxima: a mensagem é a resposta sim/não.
        $pendente = $this->pendentes->recuperar($user->id, $agora);
        if ($pendente !== null) {
            return $this->resolverConfirmacao($pendente, $comando, $user->id, $agora);
        }

        // Esclarecimento pendente: enquanto faltar campo, a mensagem PREENCHE os slots do
        // gasto em curso — não classificamos de novo nem pulamos para outra intenção. Assim
        // o bot não repergunta o que o usuário já disse (slot-filling multi-turno, 04 §3.3).
        $parcialPendente = $this->esclarecimentos->recuperar($user->id, $agora);
        if ($parcialPendente !== null) {
            return $this->continuarEsclarecimento($parcialPendente, $comando, $user->id, $agora);
        }

        $texto = $this->textoParaIA($comando);

        $intencao = $forcada ?? $this->classificador->classificar($comando->textoOriginal);

        return match ($intencao) {
            Intencao::REGISTRAR => $this->registrar($user->id, $texto, $agora),
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
        // O pendente carrega um de dois moldes (spec 10c): gasto → lançamento; recorrência →
        // molde mensal (o lançamento vem depois, do materializador da spec 10).
        $gravado = $this->confirmar->confirmar($userId, $agora);

        return match (true) {
            $gravado instanceof Transaction => ResultadoDaInteracao::gravado($gravado),
            $gravado instanceof Recurrence => ResultadoDaInteracao::recorrenciaGravada($gravado),
            default => ResultadoDaInteracao::nadaParaConfirmar(),
        };
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

    private function registrar(int $userId, string $texto, CarbonImmutable $agora): ResultadoDaInteracao
    {
        return $this->resolverExtracao($this->extrator->extrairParcial($texto), $userId, $agora);
    }

    /**
     * Turno seguinte de um gasto que ficou incompleto: mescla a nova mensagem sobre o
     * parcial acumulado e reavalia. "Não/cancelar" é a saída explícita (descarta o parcial);
     * qualquer outra coisa é tratada como preenchimento dos campos que faltam.
     */
    private function continuarEsclarecimento(
        GastoParcial $acumulado,
        ComandoRecebido $comando,
        int $userId,
        CarbonImmutable $agora,
    ): ResultadoDaInteracao {
        if ($this->interpretador->interpretar($comando->textoOriginal) === RespostaDeConfirmacao::NAO) {
            $this->esclarecimentos->descartar($userId);

            return ResultadoDaInteracao::confirmacaoCancelada();
        }

        $novo = $this->extrator->extrairParcial($this->textoParaIA($comando));

        return $this->resolverExtracao($acumulado->mesclar($novo), $userId, $agora);
    }

    /**
     * Núcleo comum do registro: ou ainda falta campo obrigatório (guarda o parcial e pede o
     * que falta — barreira 1), ou o gasto está completo e vira prévia. A normalização
     * determinística ({@see PrepararConfirmacaoDeGasto}) ainda pode pedir esclarecimento
     * (valor inválido, cartão ambíguo, data ruim); nesse caso o parcial segue guardado para
     * o próximo turno. Só a prévia confirmável guarda a confirmação para o "sim" (regra 7).
     */
    private function resolverExtracao(
        GastoParcial $parcial,
        int $userId,
        CarbonImmutable $agora,
    ): ResultadoDaInteracao {
        $faltantes = $parcial->faltantes();

        if ($faltantes !== []) {
            $this->esclarecimentos->guardar($userId, $parcial, $agora);

            return ResultadoDaInteracao::registro(new ConfirmacaoDeGasto(null, null, $faltantes));
        }

        $confirmacao = $this->preparar->preparar($parcial->paraExtraido(), $userId, $agora);

        if ($confirmacao->confirmavel()) {
            $this->esclarecimentos->descartar($userId);
            $this->pendentes->guardar($userId, $confirmacao, $agora);
        } else {
            // Esclarecimento vindo da normalização: mantém o parcial para o usuário corrigir.
            $this->esclarecimentos->guardar($userId, $parcial, $agora);
        }

        return ResultadoDaInteracao::registro($confirmacao);
    }
}
