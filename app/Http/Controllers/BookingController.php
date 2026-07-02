<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ConfirmBookingAction;
use App\Domain\Payment\PaymentException;
use App\Exceptions\ReservationInvalidException;
use App\Http\Requests\BookingRequest;
use Illuminate\Http\JsonResponse;

final readonly class BookingController
{
    public function __construct(private ConfirmBookingAction $confirmBooking)
    {
    }

    public function __invoke(BookingRequest $request): JsonResponse
    {
        try {
            $reservation = $this->confirmBooking->execute(
                $request->validated('reservation_id'),
                $request->validated('user_id'),
                $request->validated('payment_token'),
            );
        } catch (ReservationInvalidException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PaymentException $e) {
            return response()->json(['message' => 'Payment failed', 'detail' => $e->getMessage()], 402);
        }

        return response()->json([
            'reservation_id' => $reservation->id,
            'status' => $reservation->status,
        ], 201);
    }
}
