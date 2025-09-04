<?php

return [
    // Applies CORS to these paths (our gateway routes)
    'paths' => ['*'],

    // Allows all methods
    'allowed_methods' => ['*'],

    // Allows Swagger UI origins
    'allowed_origins' => [
        'http://localhost:8081',
        'http://localhost:8082',
    ],

    // Allows all headers; includes Authorization and Idempotency-Key
    'allowed_headers' => ['*'],

    // Exposes request ID for debugging
    'exposed_headers' => ['X-Request-Id'],

    // Preflights cache duration (seconds)
    'max_age' => 3600,

    // No cookies for this setup
    'supports_credentials' => false,
];
