<?php

return [
    'verification_days' => (int) env('AUTH_VERIFICATION_RETENTION_DAYS', 1),
    'recovery_days' => (int) env('AUTH_RECOVERY_RETENTION_DAYS', 30),
    'activity_days' => (int) env('AUTH_ACTIVITY_RETENTION_DAYS', 90),
    'device_days' => (int) env('AUTH_DEVICE_RETENTION_DAYS', 180),
];
