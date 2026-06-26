<?php

declare(strict_types=1);

namespace App\Domain\IA;

/**
 * Resultado da extração de um gasto pela IA. Materializa a barreira 1 (§3.3): ou há um
 * `GastoExtraido` completo, ou há `camposFaltantes` a esclarecer — campo obrigatório
 * ausente vira PERGUNTA ao usuário, NUNCA chute. Os campos faltantes usam os nomes do
 * schema (descricao, valor, forma_pagamento, cartao) para a etapa de confirmação saber
 * o que perguntar. O texto da pergunta e o fluxo de confirmação são FE/Bloco 4 item 3.
 */
final readonly class ResultadoDaExtracao
{
    /**
     * @param  list<string>  $camposFaltantes
     */
    public function __construct(
        public ?GastoExtraido $gasto,
        public array $camposFaltantes,
    ) {}

    public function precisaEsclarecer(): bool
    {
        return $this->camposFaltantes !== [];
    }
}
