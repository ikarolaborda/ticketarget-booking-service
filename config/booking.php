<?php

declare(strict_types=1);

return [

    // Post-cutover rollback bridge: seat_inventory is authoritative; while
    // this flag is on every transition is mirrored to catalog tickets.status
    // so reads can be rolled back. Turning it off is irreversible without a
    // seat_inventory -> tickets backfill.
    'catalog_status_dual_write' => (bool) env('CATALOG_STATUS_DUAL_WRITE', true),

    'outbox_topic' => env('OUTBOX_TOPIC', 'booking.events'),

    'kafka_brokers' => env('KAFKA_BROKERS', 'kafka:9092'),

    // Catalog integration events feeding the local capacity read model
    // (DDD remediation: the admin capacity count must not read catalog tables).
    'catalog_topic' => env('CATALOG_TOPIC', 'catalog.events'),

    'catalog_consumer_group' => env('CATALOG_CONSUMER_GROUP', 'booking-capacity'),

];
