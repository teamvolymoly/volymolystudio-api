<?php

return [
    // Keep off until the migration and frontend activity screen deploy together.
    'enabled' => (bool) env('NEW_DEVICE_ALERTS_ENABLED', false),
    'review_path' => env('LOGIN_ACTIVITY_REVIEW_PATH'),
    'proxy_secret' => env('AUTH_PROXY_SECRET'),
    'cookie' => 'volymoly_device',
    'device_days' => 180,
    'review_minutes' => 1440,
];
