<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies catalog event.created/event.updated events to the local event
 * directory. Last-write-wins by occurred_at (emission-time microseconds):
 * strictly older deliveries are stale no-ops, equal timestamps overwrite
 * deterministically (same emission, same content), so replays and
 * out-of-order delivery both converge on the newest catalog state.
 */
final readonly class EventDirectoryProjector
{
    public function apply(string $eventId, string $name, ?string $date, string $occurredAt): bool
    {
        $normalized = CarbonImmutable::parse($occurredAt)->utc()->format('Y-m-d H:i:s.u');

        $row = [
            'name' => $name,
            'event_date' => $date !== null ? CarbonImmutable::parse($date)->utc() : null,
            'occurred_at' => $normalized,
            'updated_at' => now(),
        ];

        $inserted = DB::table('catalog_event_directory')
            ->insertOrIgnore(array_merge(['event_id' => $eventId], $row));

        if ($inserted > 0) {
            return true;
        }

        return DB::table('catalog_event_directory')
            ->where('event_id', $eventId)
            ->where('occurred_at', '<=', $normalized)
            ->update($row) > 0;
    }
}
