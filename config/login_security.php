<?php

return [
    // Enable only after the reviewed frontend activity screen is deployed.
    'enabled' => (bool) env('NEW_DEVICE_ALERTS_ENABLED', false),
    'review_path' => env('LOGIN_ACTIVITY_REVIEW_PATH'),
    'proxy_secret' => env('AUTH_PROXY_SECRET'),
    'cookie' => 'volymoly_device',
    'device_days' => 180,
    'review_minutes' => 1440,
];
