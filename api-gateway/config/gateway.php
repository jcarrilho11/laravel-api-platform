<?php

return [
    'auth_base_url' => env('AUTH_BASE_URL', ''),
    'tasks_base_url' => env('TASKS_BASE_URL', ''),

    'jwt_aud' => env('JWT_AUD', ''),
    'jwt_secret' => env('JWT_SECRET', ''),
    'jwt_iss' => env('JWT_ISS', null),

    // per-IP requests per minute
    'rate_limit_per_minute' => (int) env('RATE_LIMIT_PER_MINUTE', 60),
];
