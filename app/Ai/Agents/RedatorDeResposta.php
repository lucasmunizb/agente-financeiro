<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Papel 3 da IA (doc 02 §3.1): redigir a resposta. Recebe dados JÁ calculados pelo motor
 * financeiro (o payload) e apenas os formata em pt-BR. NÃO inventa nem recalcula números —
 * a IA nunca produz um valor que não veio do payload. O guard pós-geração (barreira 4,
 * §3.3), que valida cada número/data do texto contra o payload, é camada NOSSA e entra no
 * Bloco 6. Implementado via Laravel AI SDK (regra inviolável 8).
 */
class RedatorDeResposta implements Agent, Conversational, HasTools
{
    use Promptable;

    /**
     * Redige uma resposta em linguagem natural a partir do payload já calculado.
     */
    public function redigir(string $payload): string
    {
        return $this->prompt($payload)->text;
    }

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        Você redige respostas curtas e claras, em português do Brasil, sobre finanças pessoais.

        Você recebe um PAYLOAD com números já calculados pelo sistema (valores, saldos, datas).
        Sua tarefa é apenas redigir a resposta a partir desse payload.

        Regra inviolável: NÃO invente, NÃO recalcule e NÃO altere nenhum número ou data.
        Use exatamente os valores do payload, formatados em pt-BR. Se um dado não estiver no
        payload, não o mencione.
        TXT;
    }

    /**
     * @return Message[]
     */
    public function messages(): iterable
    {
        return [];
    }

    /**
     * @return \Laravel\Ai\Contracts\Tool[]
     */
    public function tools(): iterable
    {
        return [];
    }
}
