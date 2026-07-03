<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ConfirmBookingAction;
use App\Domain\Payment\PaymentException;
use App\Exceptions\ReservationInvalidException;
use App\Http\Requests\BookingRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

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
                $request->buyerId(),
                $request->validated('payment_token'),
                $request->buyerEmail(),
            );
        } catch (ReservationInvalidException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (PaymentException $e) {
            return response()->json(['message' => 'Payment failed', 'detail' => $e->getMessage()], Response::HTTP_PAYMENT_REQUIRED);
        }

        return response()->json([
            'reservation_id' => $reservation->id,
            'status' => $reservation->status,
        ], Response::HTTP_CREATED);
    }
}
