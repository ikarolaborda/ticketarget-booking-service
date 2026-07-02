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
     * @param int $amountInCents total to charge
     * @throws PaymentException when the charge cannot be completed
     */
    public function charge(int $amountInCents, string $currency, string $paymentToken, string $idempotencyKey): PaymentResult;

    /**
     * Reverses a previously successful charge in full. Used to make the booking
     * transaction safe: if the post-charge commit fails, the money is returned.
     *
     * @throws PaymentException when the refund cannot be issued
     */
    public function refund(string $chargeId): void;
}
