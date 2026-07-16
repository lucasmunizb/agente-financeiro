<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Resposta;

use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\IA\Consulta\RespostaDaConsulta;
use App\Domain\Importacao\PreImportacao;
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
}
