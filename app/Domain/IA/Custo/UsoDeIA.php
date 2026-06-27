<?php

declare(strict_types=1);

namespace App\Domain\IA\Custo;

/**
 * Dados de uma única chamada de IA a registrar (doc 02 §3.6). VO imutável — só metadados de
 * custo, nunca conteúdo de mensagem (NFR/LGPD, doc 09). `custoEstimadoCents` em centavos
 * inteiros (regra inviolável 5).
 */
final readonly class UsoDeIA
{
    public function __construct(
        public string $provider,
        public string $model,
        public int $tokensEntrada,
        public int $tokensSaida,
        public int $custoEstimadoCents,
        public ?int $latenciaMs,
        public TipoDeUsoIA $tipo,
        public ?int $userId,
    ) {}
}
