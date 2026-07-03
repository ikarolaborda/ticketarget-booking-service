<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ShowReservationRequest;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rehydration endpoint: lets a browser that lost its in-memory cart (hard
 * refresh) re-verify a persisted reservation pointer. The reservation row is
 * the source of truth — the client must render only what this returns.
 */
final readonly class ShowReservationController
{
    public function __invoke(ShowReservationRequest $request, string $id): JsonResponse
    {
        $reservation = Reservation::query()->find($id);

        // Unknown id and foreign owner are indistinguishable on purpose.
        if ($reservation === null || $reservation->user_id !== $request->buyerId()) {
            return response()->json(['message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        // The sweeper may not have run yet; never report a dead hold as live.
        $status = $reservation->status;
        if ($status === Reservation::STATUS_HELD && $reservation->isExpired()) {
            $status = Reservation::STATUS_RELEASED;
        }

        $tickets = DB::table('tickets')
            ->whereIn('id', $reservation->ticket_ids ?? [])
            ->orderBy('seat')
            ->get(['id', 'event_id', 'seat', 'price', 'type', 'status']);

        return response()->json([
            'reservation_id' => $reservation->id,
            'status' => $status,
            'expires_at' => $reservation->expires_at?->toIso8601String(),
            'event_id' => $tickets->first()->event_id ?? null,
            'tickets' => $tickets->map(static fn (object $t): array => [
                'id' => $t->id,
                'seat' => $t->seat,
                'price' => number_format((float) $t->price, 2, '.', ''),
                'type' => $t->type,
                'status' => $t->status,
            ])->all(),
        ]);
    }
}
