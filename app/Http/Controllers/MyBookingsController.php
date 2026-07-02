<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class MyBookingsController
{
    public function __invoke(Request $request): JsonResponse
    {
        // OptionalBearerAuth only enriches the request; this endpoint is the
        // strict consumer — no verified token, no ticket list.
        $userId = $request->attributes->get('auth_user_id');

        if ($userId === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $rows = Booking::query()
            ->leftJoin('tickets', 'tickets.id', '=', 'bookings.ticket_id')
            ->leftJoin('events', 'events.id', '=', 'tickets.event_id')
            ->where('bookings.user_id', $userId)
            ->orderByDesc('bookings.created_at')
            ->orderByDesc('bookings.id')
            ->get([
                'bookings.reservation_id',
                'tickets.seat',
                'tickets.type',
                'bookings.amount',
                'bookings.charge_id',
                'events.name as event_name',
                'events.date as event_date',
                'bookings.created_at as purchased_at',
            ]);

        return response()->json(['tickets' => $rows]);
    }
}
