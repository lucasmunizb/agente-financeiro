<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => env('AI_PROVIDER', 'anthropic'),

    /*
    |--------------------------------------------------------------------------
    | Failover de provedores
    |--------------------------------------------------------------------------
    |
    | Lista ordenada de provedores para failover automático (doc 02 §3.6 / regra
    | inviolável 8). Indisponibilidade de um provedor cai no próximo — recurso nativo
    | da Laravel AI SDK, exposto pelos agentes via provider(). Configurável por env
    | (AI_FAILOVER="anthropic,openai"); o primeiro é o provedor padrão.
    |
    */

    'failover' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('AI_FAILOVER', env('AI_PROVIDER', 'anthropic')))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Timeout por request (segundos)
    |--------------------------------------------------------------------------
    |
    | Timeout HTTP aplicado a CADA chamada de provedor (por request, não pela
    | conversa inteira). Mantido curto de propósito: se o provedor principal
    | pendurar, a SDK falha rápido e cai no próximo do failover — a resposta ao
    | usuário precisa ser quase instantânea. Pior caso ≈ timeout × nº provedores,
    | que deve caber no limite de execução do PHP.
    |
    */

    'request_timeout' => (int) env('AI_REQUEST_TIMEOUT', 8),

    /*
    |--------------------------------------------------------------------------
    | Rotação de provedores (fila LRU + cooldown) — spec 04c
    |--------------------------------------------------------------------------
    |
    | Distribui as chamadas entre vários free tiers, rotacionando em fila (o menos-
    | recentemente-usado primeiro) e benchando quem falha por `cooldown` segundos.
    | Desligado (padrão) → o trait usa o `failover` estático acima (retrocompatível,
    | spec 04 intacta). O estado (fila + cooldowns) vive no cache `store`, compartilhado
    | entre os contêineres `app` e `worker`, sob `Cache::lock` (atômico). Só entram na
    | rotação os provedores COM chave presente. Ver docs/specs/04c-rotacao-provedores-ia.md.
    |
    */

    'rotacao' => [
        'enabled' => (bool) env('AI_ROTACAO_ENABLED', false),
        'pool' => array_values(array_filter(array_map('trim', explode(
            ',',
            // Free tiers que a SDK suporta para TEXTO: groq (rápido), mistral, gemini
            // (reserva — lento/503 frequente). Cohere fica de fora: o driver da SDK só
            // faz reranking/embeddings, não geração de texto.
            env('AI_ROTACAO_POOL', 'groq,mistral,gemini')
        )))),
        'cooldown' => (int) env('AI_ROTACAO_COOLDOWN', 60),   // segundos benched após falha
        'store' => env('AI_ROTACAO_STORE', env('CACHE_STORE', 'database')),
        'lock_ttl' => (int) env('AI_ROTACAO_LOCK_TTL', 5),    // segundos de espera pelo lock
    ],

    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_reranking' => 'cohere',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2025-04-01-preview'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
            'image_deployment' => env('AZURE_OPENAI_IMAGE_DEPLOYMENT', 'gpt-image-1'),
            'store' => env('AZURE_OPENAI_STORE', true),
        ],

        'bedrock' => [
            'driver' => 'bedrock',
            'region' => env('AWS_BEDROCK_REGION', 'us-east-1'),
            'key' => env('AWS_BEARER_TOKEN_BEDROCK'),
            'access_key_id' => env('AWS_ACCESS_KEY_ID'),
            'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
            'session_token' => env('AWS_SESSION_TOKEN'),
            'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIALS', true),
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => env('OPENAI_STORE', true),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

];
