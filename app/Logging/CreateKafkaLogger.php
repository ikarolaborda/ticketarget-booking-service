<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Level;
use Psr\Log\LoggerInterface;
use Ticketarget\Logging\LoggerFactory;

/**
 * Laravel custom-channel factory that hands logging off to the shared
 * ticketarget/logging package, keeping every service's log shape identical.
 * All settings come from the channel config (config/logging.php) — env() is
 * unavailable here once config caching is enabled.
 */
final class CreateKafkaLogger
{
    public function __invoke(array $config): LoggerInterface
    {
        $factory = new LoggerFactory(
            service: (string) ($config['service'] ?? 'booking-service'),
            environment: (string) ($config['environment'] ?? 'production'),
            kafkaBrokers: (string) ($config['brokers'] ?? ''),
            kafkaTopic: (string) ($config['topic'] ?? 'logs.app'),
            level: Level::fromName(ucfirst((string) ($config['level'] ?? 'debug'))),
        );

        return $factory->create('booking-service');
    }
}
