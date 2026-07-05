<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\TicketCodeIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class MyBookingsController
{
    public function __construct(private TicketCodeIssuer $codes) {}

    public function __invoke(Request $request): JsonResponse
    {
        // OptionalBearerAuth only enriches the request; this endpoint is the
        // strict consumer — no verified token, no ticket list.
        $userId = $request->attributes->get('auth_user_id');

        if ($userId === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        // Booking-local read: catalog fields come from the purchase-time
        // snapshots on the booking rows (backfilled for legacy rows).
        $rows = Booking::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get([
                'reservation_id',
                'seat',
                'ticket_type as type',
                'amount',
                'charge_id',
                'event_name',
                'event_date',
                'status',
                'created_at as purchased_at',
                'id as booking_id',
            ])
            ->map(function ($row) {
                $row->ticket_code = $this->codes->issue($row->booking_id);
                unset($row->booking_id);

                return $row;
            });

        return response()->json(['tickets' => $rows]);
    }
}
