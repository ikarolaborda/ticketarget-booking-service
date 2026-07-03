<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RefundBookingAction;
use App\Domain\Payment\PaymentException;
use App\Exceptions\RefundNotAllowedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RefundBookingController
{
    public function __construct(private RefundBookingAction $refund)
    {
    }

    public function __invoke(Request $request, string $booking): JsonResponse
    {
        try {
            $result = $this->refund->execute(
                $booking,
                $request->attributes->get('auth_user_id'),
                $request->input('code'),
            );
        } catch (RefundNotAllowedException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } catch (PaymentException) {
            return response()->json(['message' => 'The refund could not be processed. Please try again.'], Response::HTTP_PAYMENT_REQUIRED);
        }

        return response()->json([
            'status' => 'refund_pending',
            'refund_tier' => $result['refund_tier'],
            'amount_cents' => $result['amount_cents'],
        ]);
    }
}
