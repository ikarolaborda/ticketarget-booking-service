<?php

declare(strict_types=1);

return [
    'default' => env('CACHE_STORE', 'redis'),

    'stores' => [
        // Atomic locks are backed by the dedicated 'locks' Redis connection.
        'redis' => [
            'driver' => 'redis',
            'connection' => 'locks',
            'lock_connection' => 'locks',
        ],
        'array' => ['driver' => 'array', 'serialize' => false],
    ],

    'prefix' => env('CACHE_PREFIX', 'booking'),
];
