<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Phase 2 shadow mode: mirrors ticket-status transitions into the
 * booking-owned seat_inventory table. Upsert-based so rows never need to
 * pre-exist; runs inside the caller's transaction so the mirror is atomic
 * with the authoritative tickets.status change.
 */
final readonly class SeatInventoryProjector
{
    /**
     * @param  list<string>  $ticketIds
     */
    public function project(array $ticketIds, string $status, ?string $reservationId = null): void
    {
        if ($ticketIds === [] || ! config('booking.inventory_dual_write')) {
            return;
        }

        $eventIds = DB::table('tickets')->whereIn('id', $ticketIds)->pluck('event_id', 'id');

        $rows = array_map(fn (string $ticketId): array => [
            'ticket_id' => $ticketId,
            'event_id' => $eventIds[$ticketId] ?? null,
            'status' => $status,
            'reservation_id' => $reservationId,
            'updated_at' => now(),
        ], $ticketIds);

        DB::table('seat_inventory')->upsert($rows, ['ticket_id'], ['event_id', 'status', 'reservation_id', 'updated_at']);
    }
}
