<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Resposta;

use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\IA\Consulta\RespostaDaConsulta;

/**
 * Resultado de domínio de uma mensagem processada pelo worker (Blocos 4/5), pronto para
 * ser apresentado ao usuário. NÃO contém texto formatado do bot — a redação é frontend
 * (regra 3); aqui viajam os objetos de domínio já calculados (a IA nunca calcula dinheiro,
 * regra 4). Construído pelos fabricadores estáticos para manter os estados válidos por
 * construção: REGISTRO sempre traz uma {@see ConfirmacaoDeGasto}, CONSULTA uma
 * {@see RespostaDaConsulta}, e NAO_ENTENDI nenhum dos dois.
 */
final readonly class ResultadoDaInteracao
{
    private function __construct(
        public TipoDeInteracao $tipo,
        public ?ConfirmacaoDeGasto $registro = null,
        public ?RespostaDaConsulta $consulta = null,
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
}
