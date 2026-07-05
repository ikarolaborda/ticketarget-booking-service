<?php

declare(strict_types=1);

return [

    // Phase 2 shadow mode: mirror every ticket-status transition into the
    // booking-owned seat_inventory table. tickets.status stays authoritative
    // until booking:verify-inventory shows sustained zero drift.
    'inventory_dual_write' => (bool) env('INVENTORY_DUAL_WRITE', true),

    'outbox_topic' => env('OUTBOX_TOPIC', 'booking.events'),

    'kafka_brokers' => env('KAFKA_BROKERS', 'kafka:9092'),

    // Catalog integration events feeding the local capacity read model
    // (DDD remediation: the admin capacity count must not read catalog tables).
    'catalog_topic' => env('CATALOG_TOPIC', 'catalog.events'),

    'catalog_consumer_group' => env('CATALOG_CONSUMER_GROUP', 'booking-capacity'),

];
