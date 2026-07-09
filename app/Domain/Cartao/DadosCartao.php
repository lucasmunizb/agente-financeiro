<?php

declare(strict_types=1);

namespace App\Domain\Cartao;

/**
 * Dados de entrada para cadastrar um cartão (spec FE §7.13). DTO imutável validado/traduzido
 * na borda (Form Request). Cartão é identificado só por descrição + 4 dígitos finais (nunca o
 * número completo — LGPD/§4.6). `limiteCents` opcional, em centavos (regra 5).
 */
final class DadosCartao
{
    public function __construct(
        public readonly int $userId,
        public readonly string $descricao,
        public readonly string $final4,
        public readonly int $diaFechamento,
        public readonly int $diaVencimento,
        public readonly ?int $limiteCents = null,
    ) {}
}
