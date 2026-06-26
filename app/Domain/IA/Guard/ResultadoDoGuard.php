<?php

declare(strict_types=1);

namespace App\Domain\IA\Guard;

/**
 * Veredito do guard pós-geração (doc 02 §3.3). Quando `aprovado` é falso, o texto
 * citou ao menos um valor ou data que NÃO existe no payload calculado — os tokens
 * crus divergentes ficam listados para diagnóstico/log. O caller deve então
 * bloquear o envio e regenerar a resposta ou cair no fallback.
 */
final class ResultadoDoGuard
{
    /**
     * @param  list<string>  $valoresDivergentes  tokens monetários crus que não existem no payload
     * @param  list<string>  $datasDivergentes  tokens de data crus que não existem no payload
     */
    public function __construct(
        public readonly bool $aprovado,
        public readonly array $valoresDivergentes = [],
        public readonly array $datasDivergentes = [],
    ) {
    }
}
