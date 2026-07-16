<?php

declare(strict_types=1);

namespace App\Domain\IA;

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Recorrencia\DadosRecorrencia;

/**
 * Resultado da normalização determinística de um gasto extraído pela IA. Ou os dados
 * estão prontos para o motor financeiro (`DadosGastoManual`), ou há `esclarecimentos`
 * a resolver — campo que não pôde ser resolvido (valor inválido, data incompreendida,
 * forma não suportada, cartão não encontrado/ambíguo) vira PERGUNTA, NUNCA chute (§3.4).
 * Os códigos de esclarecimento usam os nomes do schema (valor, data, forma_pagamento,
 * cartao, recorrencia_dia) para a etapa de confirmação saber o que perguntar.
 *
 * A mesma mensagem pode descrever um gasto avulso OU uma recorrência mensal (spec 10c):
 * `dados` e `recorrencia` são mutuamente exclusivos — no máximo um deles é não-nulo, e
 * ambos são nulos quando há esclarecimentos.
 */
final readonly class ResultadoDaNormalizacao
{
    /**
     * @param  list<string>  $esclarecimentos
     */
    public function __construct(
        public ?DadosGastoManual $dados,
        public array $esclarecimentos,
        public ?DadosRecorrencia $recorrencia = null,
    ) {}

    public function precisaEsclarecer(): bool
    {
        return $this->esclarecimentos !== [];
    }
}
