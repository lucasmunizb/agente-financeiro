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
    ],
];
