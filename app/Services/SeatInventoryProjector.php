<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SeatInventory;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Applies inventory transitions to booking-owned seat_inventory (the source
 * of truth since the ownership cutover) and, while the rollback window is
 * open (CATALOG_STATUS_DUAL_WRITE), mirrors the status to catalog
 * tickets.status so a read-path rollback stays possible. Runs inside the
 * caller's transaction. Once the flag is off, re-enabling requires a
 * seat_inventory -> tickets backfill before reads can go back.
 */
final readonly class SeatInventoryProjector
{
    private const array MIRROR = [
        SeatInventory::STATUS_AVAILABLE => Ticket::STATUS_AVAILABLE,
        SeatInventory::STATUS_HELD => Ticket::STATUS_UNAVAILABLE,
        SeatInventory::STATUS_BOOKED => Ticket::STATUS_BOOKED,
    ];

    /**
     * @param  list<string>  $ticketIds
     */
    public function transition(array $ticketIds, string $status, ?string $reservationId = null): void
    {
        if ($ticketIds === []) {
            return;
        }

        DB::table('seat_inventory')
            ->whereIn('ticket_id', $ticketIds)
            ->update([
                'status' => $status,
                'reservation_id' => $reservationId,
                'updated_at' => now(),
            ]);

        if (config('booking.catalog_status_dual_write')) {
            DB::table('tickets')
                ->whereIn('id', $ticketIds)
                ->update(['status' => self::MIRROR[$status]]);
        }
    }
}
