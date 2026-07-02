<?php

declare(strict_types=1);

use App\Logging\CreateKafkaLogger;
use Monolog\Level;

return [
    'default' => env('LOG_CHANNEL', 'kafka'),

    'channels' => [
        'kafka' => [
            'driver' => 'custom',
            'via' => CreateKafkaLogger::class,
            'level' => env('LOG_LEVEL', 'debug'),
        ],
        'stdout' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\StreamHandler::class,
            'with' => ['stream' => 'php://stdout'],
            'level' => env('LOG_LEVEL', 'debug'),
        ],
    ],

    'deprecations' => ['channel' => 'stdout', 'trace' => false],

    'level_class' => Level::class,
];
