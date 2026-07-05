<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SeatInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Public availability read for the buyer seat map, served from booking-owned
 * inventory (the availability authority since the ownership cutover). The
 * catalog serves static seat identity; this endpoint serves the live state.
 * held is reported as unavailable — buyers cannot distinguish a hold from a
 * sale, and the catalog wire format used the same collapse.
 */
final readonly class EventAvailabilityController
{
    public function __invoke(string $event): JsonResponse
    {
        $seats = DB::table('seat_inventory')
            ->where('event_id', $event)
            ->get(['ticket_id', 'status', 'zone_id', 'price']);

        $tickets = [];
        $zones = [];

        foreach ($seats as $seat) {
            $tickets[(string) $seat->ticket_id] = $seat->status === SeatInventory::STATUS_HELD
                ? 'unavailable'
                : (string) $seat->status;

            $zoneKey = $seat->zone_id !== null ? (string) $seat->zone_id : '';
            $zones[$zoneKey] ??= ['zone_id' => $seat->zone_id, 'available' => 0, 'from_price' => null];

            if ($seat->status === SeatInventory::STATUS_AVAILABLE) {
                $zones[$zoneKey]['available']++;
                $price = (float) $seat->price;

                if ($zones[$zoneKey]['from_price'] === null || $price < (float) $zones[$zoneKey]['from_price']) {
                    $zones[$zoneKey]['from_price'] = number_format($price, 2, '.', '');
                }
            }
        }

        return response()->json([
            'data' => [
                'event_id' => $event,
                'tickets' => $tickets,
                'zones' => array_values($zones),
            ],
        ]);
    }
}
