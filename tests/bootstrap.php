<?php

declare(strict_types=1);

/*
 * Container-injected env vars (compose `environment:`) land in $_SERVER, which
 * Laravel's env() consults BEFORE $_ENV — so phpunit.xml's <env force="true">
 * alone cannot shield the live database from the test suite. Pin every
 * test-critical value into all three channels before anything else loads.
 */
$overrides = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'CACHE_STORE' => 'array',
    'LOG_CHANNEL' => 'stdout',
];

foreach ($overrides as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require __DIR__.'/../vendor/autoload.php';
