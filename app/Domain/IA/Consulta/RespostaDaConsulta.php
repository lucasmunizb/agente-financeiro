<?php

declare(strict_types=1);

namespace App\Domain\IA\Consulta;

/**
 * Resposta final do chat de consulta (Bloco 6). Carrega o texto a ser entregue ao
 * usuário, se passou no guard pós-geração (`aprovado`) e as fontes (trace) das tools
 * consultadas — barreira 5 (doc 02 §3.3): toda resposta carrega de onde os números
 * vieram. `tentativas` registra quantas gerações foram necessárias (diagnóstico/log).
 *
 * Quando `aprovado` é falso, `texto` é a mensagem de fallback segura (sem números) — o
 * guard reprovou todas as tentativas. A apresentação (bot/web) é etapa separada.
 */
final class RespostaDaConsulta
{
    /**
     * @param  list<TraceDaConsulta>  $fontes  fontes das tools chamadas na geração aprovada (ou da última tentativa)
     */
    public function __construct(
        public readonly string $texto,
        public readonly bool $aprovado,
        public readonly array $fontes,
        public readonly int $tentativas,
    ) {}
}
