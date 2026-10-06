<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    // FRONTEND_URL may contain a comma-separated list for local + production
    // deployments. CORS requires exact origins, so remove trailing slashes.
    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => rtrim(trim($origin), '/'),
        explode(',', env('FRONTEND_URL', 'http://localhost:3000')),
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
