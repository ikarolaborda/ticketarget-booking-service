<?php

declare(strict_types=1);

use App\Logging\CreateKafkaLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

return [
    'default' => env('LOG_CHANNEL', 'kafka'),

    'channels' => [
        'kafka' => [
            'driver' => 'custom',
            'via' => CreateKafkaLogger::class,
            'level' => env('LOG_LEVEL', 'debug'),
            'service' => env('APP_NAME', 'booking-service'),
            'environment' => env('APP_ENV', 'production'),
            'brokers' => env('KAFKA_BROKERS', ''),
            'topic' => env('KAFKA_LOG_TOPIC', 'logs.app'),
        ],
        'stdout' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stdout'],
            'level' => env('LOG_LEVEL', 'debug'),
        ],
    ],

    'deprecations' => ['channel' => 'stdout', 'trace' => false],

    'level_class' => Level::class,
];
