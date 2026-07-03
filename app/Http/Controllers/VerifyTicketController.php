<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\TicketCodeIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gate-scanner endpoint. Every failure mode (malformed, forged, unknown) is the
 * same {valid:false} so nothing can be learned by probing; forging a hit needs
 * the HMAC secret. Sits behind the gateway's booking rate limit.
 */
final readonly class VerifyTicketController
{
    public function __construct(private TicketCodeIssuer $codes) {}

    public function __invoke(Request $request): JsonResponse
    {
        $bookingId = $this->codes->verify((string) $request->query('code', ''));

        if ($bookingId === null) {
            return response()->json(['valid' => false]);
        }

        $row = Booking::query()
            ->leftJoin('tickets', 'tickets.id', '=', 'bookings.ticket_id')
            ->leftJoin('events', 'events.id', '=', 'tickets.event_id')
            ->where('bookings.id', $bookingId)
            // A ticket being refunded (or refunded) no longer admits anyone.
            ->where('bookings.status', Booking::STATUS_PAID)
            ->first(['tickets.seat', 'events.name as event_name', 'events.date as event_date']);

        if ($row === null) {
            return response()->json(['valid' => false]);
        }

        return response()->json([
            'valid' => true,
            'seat' => $row->seat,
            'event_name' => $row->event_name,
            'event_date' => $row->event_date,
        ]);
    }
}
