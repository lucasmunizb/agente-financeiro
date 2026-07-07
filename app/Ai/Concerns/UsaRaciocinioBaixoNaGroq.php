<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use Laravel\Ai\Enums\Lab;

/**
 * Economia de tokens para agentes MECÂNICOS (classificar intenção, extrair campos) nos
 * modelos de raciocínio `gpt-oss-*` da Groq (doc 02 §3.6). Esses modelos emitem tokens de
 * reasoning ANTES da saída — e `max_tokens`/`max_completion_tokens` inclui esse reasoning,
 * então um teto curto TRUNCA a geração e quebra o JSON (Groq responde 400 "Failed to
 * validate JSON"). O lever correto aqui é `reasoning_effort: low`: reduz o reasoning de
 * tarefas triviais SEM cortar a saída, preservando o structured output.
 *
 * Escopo: só a Groq entende `reasoning_effort`. No failover para o Gemini (ou outro
 * provedor) devolvemos vazio para não vazar um parâmetro inválido.
 */
trait UsaRaciocinioBaixoNaGroq
{
    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return ($provider === Lab::Groq || $provider === 'groq')
            ? ['reasoning_effort' => 'low']
            : [];
    }
}
