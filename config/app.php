<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'booking-service'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'maintenance' => ['driver' => 'file'],
];
