<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Level;
use Psr\Log\LoggerInterface;
use Ticketarget\Logging\LoggerFactory;

final class CreateKafkaLogger
{
    public function __invoke(array $config): LoggerInterface
    {
        return (new LoggerFactory(
            service: (string) env('APP_NAME', 'booking-service'),
            environment: (string) env('APP_ENV', 'production'),
            kafkaBrokers: (string) env('KAFKA_BROKERS', ''),
            kafkaTopic: (string) env('KAFKA_LOG_TOPIC', 'logs.app'),
            level: Level::fromName(ucfirst((string) ($config['level'] ?? 'debug'))),
        ))->create('booking-service');
    }
}
