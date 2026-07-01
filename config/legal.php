<?php

// Metadados dos documentos legais (Termos de Uso e Política de Privacidade).
// PREENCHA os campos abaixo com os dados reais antes de publicar — a LGPD exige
// a identificação do controlador e do encarregado (art. 41). Não invente: os
// placeholders aparecem no texto de propósito, para não passarem despercebidos.
// Em produção, prefira Docker Secrets/env (regra 10); os defaults valem em dev.
return [
    // Identificação do controlador dos dados (razão social ou nome).
    'controlador' => env('LEGAL_CONTROLADOR', '[preencher: nome/razão social do controlador]'),

    // Encarregado pelo tratamento de dados (DPO) e canal de contato do titular.
    'encarregado' => env('LEGAL_ENCARREGADO', '[preencher: nome do encarregado (DPO)]'),
    'contato' => env('LEGAL_CONTATO', '[preencher: e-mail de contato para assuntos de privacidade]'),

    // Foro/comarca para a cláusula de lei aplicável dos Termos.
    'foro' => env('LEGAL_FORO', '[preencher: comarca/UF]'),

    // Datas de última atualização (pt-BR, prontas para exibir).
    'termos_atualizado_em' => '1 de julho de 2026',
    'privacidade_atualizado_em' => '1 de julho de 2026',
];
