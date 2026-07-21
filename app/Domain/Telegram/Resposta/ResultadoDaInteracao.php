<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Resposta;

use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\IA\Consulta\RespostaDaConsulta;
use App\Domain\Importacao\PreImportacao;
use App\Domain\Pagamento\ContaPagavel;
use App\Models\Recurrence;
use App\Models\Transaction;

/**
 * Resultado de domínio de uma mensagem processada pelo worker (Blocos 4/5), pronto para
 * ser apresentado ao usuário. NÃO contém texto formatado do bot — a redação é frontend
 * (regra 3); aqui viajam os objetos de domínio já calculados (a IA nunca calcula dinheiro,
 * regra 4). Construído pelos fabricadores estáticos para manter os estados válidos por
 * construção: REGISTRO sempre traz uma {@see ConfirmacaoDeGasto}, CONSULTA uma
 * {@see RespostaDaConsulta}, e NAO_ENTENDI nenhum dos dois. Na confirmação do gasto via bot
 * (spec 04b), GRAVADO traz a {@see Transaction} persistida e CONFIRMACAO_AMBIGUA reusa o
 * slot `registro` para devolver o pendente a reapresentar.
 */
final readonly class ResultadoDaInteracao
{
    private function __construct(
        public TipoDeInteracao $tipo,
        public ?ConfirmacaoDeGasto $registro = null,
        public ?RespostaDaConsulta $consulta = null,
        public ?Transaction $transacao = null,
        public ?PreImportacao $preImportacao = null,
        public ?Recurrence $recorrencia = null,
        // Quitar conta pelo bot: a conta identificada (a confirmar / quitada) e, quando o
        // termo casa com várias, a lista para o usuário desempatar.
        public ?ContaPagavel $contaAPagar = null,
        /** @var list<ContaPagavel> */
        public array $contasCandidatas = [],
        public ?string $termoBuscado = null,
    ) {}

    public static function registro(ConfirmacaoDeGasto $confirmacao): self
    {
        return new self(TipoDeInteracao::REGISTRO, registro: $confirmacao);
    }

    public static function consulta(RespostaDaConsulta $resposta): self
    {
        return new self(TipoDeInteracao::CONSULTA, consulta: $resposta);
    }

    public static function naoEntendi(): self
    {
        return new self(TipoDeInteracao::NAO_ENTENDI);
    }

    public static function gravado(Transaction $transacao): self
    {
        return new self(TipoDeInteracao::GRAVADO, transacao: $transacao);
    }

    /**
     * O "sim" cadastrou uma recorrência mensal (spec 10c) — nenhum lançamento nasceu ainda.
     * Tipo próprio porque a redação é outra ("passei a repetir todo dia X"), e a mensagem do
     * bot é frontend (regra 3).
     */
    public static function recorrenciaGravada(Recurrence $recorrencia): self
    {
        return new self(TipoDeInteracao::RECORRENCIA_GRAVADA, recorrencia: $recorrencia);
    }

    public static function confirmacaoCancelada(): self
    {
        return new self(TipoDeInteracao::CONFIRMACAO_CANCELADA);
    }

    public static function confirmacaoAmbigua(ConfirmacaoDeGasto $pendente): self
    {
        return new self(TipoDeInteracao::CONFIRMACAO_AMBIGUA, registro: $pendente);
    }

    public static function nadaParaConfirmar(): self
    {
        return new self(TipoDeInteracao::NADA_PARA_CONFIRMAR);
    }

    public static function importacaoPronta(PreImportacao $preImportacao): self
    {
        return new self(TipoDeInteracao::IMPORTACAO_PRONTA, preImportacao: $preImportacao);
    }

    public static function importacaoProtegidaPorSenha(): self
    {
        return new self(TipoDeInteracao::IMPORTACAO_PROTEGIDA_POR_SENHA);
    }

    public static function importacaoFalhou(): self
    {
        return new self(TipoDeInteracao::IMPORTACAO_FALHOU);
    }

    /**
     * Uma conta foi identificada e espera o "sim" — nada gravado ainda (regra 7). Os números
     * vêm do banco (regra 4): o modelo só forneceu o termo da busca.
     */
    public static function pagamentoAConfirmar(ContaPagavel $conta): self
    {
        return new self(TipoDeInteracao::PAGAMENTO_A_CONFIRMAR, contaAPagar: $conta);
    }

    /**
     * Mais de uma conta casa com o que o usuário disse. Quem desempata é ele — escolher
     * sozinho arriscaria quitar a conta errada.
     *
     * @param  list<ContaPagavel>  $candidatos
     */
    public static function pagamentoAmbiguo(array $candidatos): self
    {
        return new self(TipoDeInteracao::PAGAMENTO_AMBIGUO, contasCandidatas: $candidatos);
    }

    /** O "sim" quitou a conta. */
    public static function pagamentoRegistrado(ContaPagavel $conta): self
    {
        return new self(TipoDeInteracao::PAGAMENTO_REGISTRADO, contaAPagar: $conta);
    }

    /** Nada casou com o termo — o bot diz isso em vez de inventar uma conta. */
    public static function contaAPagarNaoEncontrada(?string $termo): self
    {
        return new self(TipoDeInteracao::CONTA_A_PAGAR_NAO_ENCONTRADA, termoBuscado: $termo);
    }
}
