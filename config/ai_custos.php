<?php

/*
|--------------------------------------------------------------------------
| Tabela de preços de IA (estimativa de custo)
|--------------------------------------------------------------------------
|
| Preço por provedor/modelo em CENTAVOS por 1.000.000 de tokens (regra 5: centavos
| inteiros). Usado pela CalculadoraDeCustoIA para estimar o custo de cada chamada
| registrada em ai_usage_log (doc 02 §3.6). Provedor/modelo ausente => custo 0
| (nunca inventa custo). Valores são uma estimativa de governança, não dinheiro do
| usuário; ajuste conforme a tabela vigente do provedor.
|
*/

return [
    'tabela' => [
        'anthropic' => [
            // claude-opus-4-8: entrada ~US$ 15 / saída ~US$ 75 por 1M tokens.
            'claude-opus-4-8' => ['entrada' => 1500, 'saida' => 7500],
            // claude-sonnet-4-6: entrada ~US$ 3 / saída ~US$ 15 por 1M tokens.
            'claude-sonnet-4-6' => ['entrada' => 300, 'saida' => 1500],
            // claude-haiku-4-5: entrada ~US$ 1 / saída ~US$ 5 por 1M tokens.
            'claude-haiku-4-5' => ['entrada' => 100, 'saida' => 500],
        ],

        // Provedores do runtime atual (Groq principal + Gemini failover + Mistral na
        // rotação — auditoria P3-1: sem eles o dashboard de custo subcontava para 0).
        // Free tier: o "custo" é o preço de tabela equivalente (governança), não fatura.
        'groq' => [
            // gpt-oss-20b: ~US$ 0,10 / 0,50 por 1M tokens.
            'openai/gpt-oss-20b' => ['entrada' => 10, 'saida' => 50],
            // gpt-oss-120b: ~US$ 0,15 / 0,75 por 1M tokens.
            'openai/gpt-oss-120b' => ['entrada' => 15, 'saida' => 75],
            // llama-3.3-70b-versatile: ~US$ 0,59 / 0,79 por 1M tokens.
            'llama-3.3-70b-versatile' => ['entrada' => 59, 'saida' => 79],
        ],
        'gemini' => [
            // gemini-2.5-flash: ~US$ 0,30 / 2,50 por 1M tokens.
            'gemini-2.5-flash' => ['entrada' => 30, 'saida' => 250],
            // gemini-2.5-flash-lite: ~US$ 0,10 / 0,40 por 1M tokens.
            'gemini-2.5-flash-lite' => ['entrada' => 10, 'saida' => 40],
            // gemini-2.0-flash: ~US$ 0,10 / 0,40 por 1M tokens.
            'gemini-2.0-flash' => ['entrada' => 10, 'saida' => 40],
        ],
        'mistral' => [
            // mistral-small-latest: ~US$ 0,10 / 0,30 por 1M tokens.
            'mistral-small-latest' => ['entrada' => 10, 'saida' => 30],
        ],
    ],
];
