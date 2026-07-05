<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies catalog ticket.generated events to the local capacity read model.
 * Replay-safe: the unique event_key makes a duplicate delivery a no-op, so
 * the Kafka consumer can commit offsets after this returns either way.
 */
final readonly class CapacityLedgerProjector
{
    public function apply(string $eventKey, string $eventId, ?string $zoneId, int $count): bool
    {
        if ($count < 0) {
            return false;
        }

        return DB::table('catalog_capacity_ledger')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'event_key' => $eventKey,
            'event_id' => $eventId,
            'zone_id' => $zoneId,
            'count' => $count,
            'created_at' => now(),
        ]) > 0;
    }
}
