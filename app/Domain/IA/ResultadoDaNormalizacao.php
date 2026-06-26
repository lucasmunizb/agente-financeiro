<?php

declare(strict_types=1);

namespace App\Domain\IA;

use App\Domain\Gasto\DadosGastoManual;

/**
 * Resultado da normalização determinística de um gasto extraído pela IA. Ou os dados
 * estão prontos para o motor financeiro (`DadosGastoManual`), ou há `esclarecimentos`
 * a resolver — campo que não pôde ser resolvido (valor inválido, data incompreendida,
 * forma não suportada, cartão não encontrado/ambíguo) vira PERGUNTA, NUNCA chute (§3.4).
 * Os códigos de esclarecimento usam os nomes do schema (valor, data, forma_pagamento,
 * cartao) para a etapa de confirmação saber o que perguntar.
 */
final readonly class ResultadoDaNormalizacao
{
    /**
     * @param  list<string>  $esclarecimentos
     */
    public function __construct(
        public ?DadosGastoManual $dados,
        public array $esclarecimentos,
    ) {}

    public function precisaEsclarecer(): bool
    {
        return $this->esclarecimentos !== [];
    }
}
