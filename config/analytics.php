<?php

return [
    'weights' => [
        'er' => (float) env('ANALYTICS_CONTENT_SCORE_ER_WEIGHT', 0.5),
        'forwards' => (float) env('ANALYTICS_CONTENT_SCORE_FORWARDS_WEIGHT', 0.3),
        'views' => (float) env('ANALYTICS_CONTENT_SCORE_VIEWS_WEIGHT', 0.2),
    ],

    'collection' => [
        'max_limit' => 500,
    ],

    'embeddings' => [
        'base_url' => env('EMBEDDING_BASE_URL', 'https://api.openai.com/v1'),
        'api_key' => env('EMBEDDING_API_KEY', ''),
        'model' => env('EMBEDDING_MODEL', 'bge-m3'),
        'dimension' => (int) env('EMBEDDING_DIMENSION', 1024),
        'batch_size' => (int) env('EMBEDDING_BATCH_SIZE', 50),
        'timeout' => (int) env('EMBEDDING_TIMEOUT', 30),
    ],
];
