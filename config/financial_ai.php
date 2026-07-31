<?php

return [
    'llm' => [
        'enabled' => (bool) env('FINANCIAL_AI_LLM_ENABLED', false),
        'provider' => env('FINANCIAL_AI_LLM_PROVIDER', 'openai'),
        'api_key' => env('FINANCIAL_AI_LLM_API_KEY', env('OPENAI_API_KEY')),
        'base_url' => env('FINANCIAL_AI_LLM_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('FINANCIAL_AI_LLM_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('FINANCIAL_AI_LLM_TIMEOUT', 20),
    ],
];
