<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * Port for charging a customer. The domain depends on this abstraction; the
 * Stripe implementation lives in the infrastructure layer and is swappable.
 */
interface PaymentGateway
{
    /**
     * @param  int  $amountInCents  total to charge
     *
     * @throws PaymentException when the charge cannot be completed
     */
    public function charge(int $amountInCents, string $currency, string $paymentToken, string $idempotencyKey): PaymentResult;

    /**
     * Reverses part or all of a previously successful charge. The amount is
     * ALWAYS explicit for policy refunds because one payment intent can cover
     * several seats; null means "everything still refundable" (compensation path).
     *
     * @throws PaymentException when the refund cannot be issued
     */
    public function refund(string $chargeId, ?int $amountInCents = null, ?string $idempotencyKey = null): void;
}
