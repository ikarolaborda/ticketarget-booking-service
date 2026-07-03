<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReserveSeatsAction;
use App\Exceptions\SeatUnavailableException;
use App\Http\Requests\ReserveRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final readonly class ReserveController
{
    public function __construct(private ReserveSeatsAction $reserveSeats) {}

    public function __invoke(ReserveRequest $request): JsonResponse
    {
        try {
            $reservation = $this->reserveSeats->execute(
                $request->buyerId(),
                $request->ticketIds(),
            );
        } catch (SeatUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'reservation_id' => $reservation->id,
            'status' => $reservation->status,
            'expires_at' => $reservation->expires_at?->toIso8601String(),
        ], Response::HTTP_CREATED);
    }
}
