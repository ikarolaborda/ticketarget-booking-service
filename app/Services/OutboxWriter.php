<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes integration events into the transactional outbox. Callers invoke this
 * inside the same DB transaction as the state change so the event exists iff
 * the change committed; the scheduled outbox:publish command ships them.
 */
final readonly class OutboxWriter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function write(string $aggregateType, string $aggregateId, string $eventType, string $eventKey, array $payload): void
    {
        // insertOrIgnore + unique event_key: a retried application path
        // enqueues the same semantic event at most once.
        DB::table('outbox_messages')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'event_key' => $eventKey,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'created_at' => now(),
        ]);
    }
}
