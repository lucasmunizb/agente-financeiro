<?php

declare(strict_types=1);

namespace App\Domain\Interacao;

use App\Ai\Agents\ClassificadorDeIntencao;
use App\Ai\Agents\ExtratorDeContaPaga;
use App\Ai\Agents\ExtratorDeGasto;
use App\Domain\Chat\ResponderNoChat;
use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\IA\Consulta\ResponderConsulta;
use App\Domain\IA\Esclarecimento\EsclarecimentosPendentes;
use App\Domain\IA\GastoParcial;
use App\Domain\IA\Intencao;
use App\Domain\IA\PrepararConfirmacaoDeGasto;
use App\Domain\Pagamento\ContaPagavel;
use App\Domain\Pagamento\PagamentosPendentes;
use App\Domain\Pagamento\PagarContaPagavel;
use App\Domain\Pagamento\ResolverContaAPagar;
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
        // "Paguei a luz" (decisão do usuário 2026-07-21): a IA só extrai o termo; quem é a
        // conta, quanto vale e o gravar são determinísticos.
        private readonly ExtratorDeContaPaga $extratorDeContaPaga = new ExtratorDeContaPaga,
        private readonly ResolverContaAPagar $resolverConta = new ResolverContaAPagar,
        private readonly PagarContaPagavel $pagarConta = new PagarContaPagavel,
        private readonly PagamentosPendentes $pagamentosPendentes = new PagamentosPendentes,
    ) {}

    public function processar(User $user, ComandoRecebido $comando, ?Intencao $forcada = null): ResultadoDaInteracao
    {
        $agora = CarbonImmutable::now(self::TZ);

        // Confirmação confirmável tem precedência máxima: a mensagem é a resposta sim/não.
        $pendente = $this->pendentes->recuperar($user->id, $agora);
        if ($pendente !== null) {
            return $this->resolverConfirmacao($pendente, $comando, $user->id, $agora);
        }

        // Pagamento pendente ("paguei a luz"): a mensagem é a resposta — sim/não quando há
        // uma conta só, ou o número da escolha quando o termo casou com várias. Precedência
        // pela mesma razão da confirmação: "sim" nunca pode ser reclassificado como outra
        // coisa.
        $contasPendentes = $this->pagamentosPendentes->recuperar($user->id, $agora);
        if ($contasPendentes !== []) {
            return $this->resolverPagamentoPendente($contasPendentes, $comando, $user->id, $agora);
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
            Intencao::PAGAR => $this->pagar($user->id, $texto, $agora),
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

    /**
     * "Paguei a luz": a IA extrai só o TERMO; o domínio resolve quais contas casam. Nenhum
     * número vem do modelo (regra 4). Nada é gravado aqui — o pendente espera o "sim"
     * (regra 7).
     */
    private function pagar(int $userId, string $texto, CarbonImmutable $agora): ResultadoDaInteracao
    {
        $termo = $this->extratorDeContaPaga->extrair($texto);

        $candidatos = $termo !== null ? $this->resolverConta->para($userId, $termo, $agora) : [];

        if ($candidatos === []) {
            return ResultadoDaInteracao::contaAPagarNaoEncontrada($termo);
        }

        // Guarda os candidatos JÁ resolvidos: no "sim", quita-se exatamente o que foi
        // mostrado — não uma nova busca, que poderia render outra conta.
        $this->pagamentosPendentes->guardar($userId, $candidatos, $agora);

        return count($candidatos) === 1
            ? ResultadoDaInteracao::pagamentoAConfirmar($candidatos[0])
            : ResultadoDaInteracao::pagamentoAmbiguo($candidatos);
    }

    /**
     * Turno seguinte do pagamento. Com vários candidatos, a mensagem é o NÚMERO da escolha
     * (que reduz a lista a um e volta a pedir confirmação); com um só, é o sim/não. Resposta
     * indefinida mantém o pendente e reapresenta — nunca grava no escuro (barreira 1).
     *
     * @param  list<ContaPagavel>  $candidatos
     */
    private function resolverPagamentoPendente(
        array $candidatos,
        ComandoRecebido $comando,
        int $userId,
        CarbonImmutable $agora,
    ): ResultadoDaInteracao {
        $resposta = $this->interpretador->interpretar($comando->textoOriginal);

        if ($resposta === RespostaDeConfirmacao::NAO) {
            $this->pagamentosPendentes->descartar($userId);

            return ResultadoDaInteracao::confirmacaoCancelada();
        }

        if (count($candidatos) > 1) {
            $escolhida = $this->escolhaPorNumero($candidatos, $comando->textoOriginal);

            if ($escolhida === null) {
                return ResultadoDaInteracao::pagamentoAmbiguo($candidatos);
            }

            $this->pagamentosPendentes->guardar($userId, [$escolhida], $agora);

            return ResultadoDaInteracao::pagamentoAConfirmar($escolhida);
        }

        if ($resposta !== RespostaDeConfirmacao::SIM) {
            return ResultadoDaInteracao::pagamentoAConfirmar($candidatos[0]);
        }

        $this->pagarConta->pagar($candidatos[0], $userId, $agora);
        $this->pagamentosPendentes->descartar($userId);

        return ResultadoDaInteracao::pagamentoRegistrado($candidatos[0]);
    }

    /**
     * Índice 1..N digitado pelo usuário. Só número puro conta: "2" escolhe o segundo item,
     * mas "paguei 2 contas" não — interpretar texto solto como escolha quitaria a conta
     * errada.
     *
     * @param  list<ContaPagavel>  $candidatos
     */
    private function escolhaPorNumero(array $candidatos, string $texto): ?ContaPagavel
    {
        $texto = trim($texto);

        if (preg_match('/^\d+$/', $texto) !== 1) {
            return null;
        }

        $indice = (int) $texto - 1;

        return $candidatos[$indice] ?? null;
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
