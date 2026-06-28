<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Resposta;

use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\IA\Consulta\RespostaDaConsulta;
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
}
