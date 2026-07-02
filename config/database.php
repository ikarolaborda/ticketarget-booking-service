<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            // Booking writes and the FOR UPDATE locks must always hit the primary.
            'read' => ['host' => [env('DB_READ_HOST', env('DB_HOST', 'postgres-replica'))]],
            'write' => ['host' => [env('DB_HOST', 'postgres-primary')]],
            'sticky' => true,
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'ticketarget'),
            'username' => env('DB_USERNAME', 'ticketarget'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        // In-memory database used exclusively by the test suite (phpunit.xml
        // forces DB_CONNECTION=sqlite so tests can never touch the live pgsql).
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', ':memory:'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],

    'migrations' => ['table' => 'migrations'],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'predis'),
        'options' => [
            'prefix' => Str::slug(env('APP_NAME', 'ticketarget'), '_').'_booking_',
        ],
        // Dedicated logical DB for distributed seat locks, separate from cache.
        // Connects to the Redis master; the Sentinel cluster is deployed for HA.
        'locks' => [
            'host' => env('REDIS_HOST', 'redis-master'),
            'port' => 6379,
            'password' => env('REDIS_PASSWORD'),
            'database' => (int) env('REDIS_LOCK_DB', 1),
        ],
    ],
];
