<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Domain\Payment\PaymentException;
use App\Domain\Payment\PaymentGateway;
use App\Domain\Payment\PaymentResult;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final readonly class StripePaymentGateway implements PaymentGateway
{
    public function __construct(private StripeClient $stripe)
    {
    }

    public function charge(int $amountInCents, string $currency, string $paymentToken, string $idempotencyKey): PaymentResult
    {
        try {
            $intent = $this->stripe->paymentIntents->create(
                [
                    'amount' => $amountInCents,
                    'currency' => $currency,
                    'payment_method' => $paymentToken,
                    'confirm' => true,
                    'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
                ],
                // Idempotency key ties the charge to the reservation, so a retried
                // request never double-charges the customer.
                ['idempotency_key' => $idempotencyKey],
            );
        } catch (ApiErrorException $e) {
            throw new PaymentException($e->getMessage(), previous: $e);
        }

        if ($intent->status !== 'succeeded') {
            throw new PaymentException("Payment not completed: {$intent->status}");
        }

        return new PaymentResult($intent->id, $amountInCents, $currency);
    }

    public function refund(string $chargeId): void
    {
        try {
            $this->stripe->refunds->create(['payment_intent' => $chargeId]);
        } catch (ApiErrorException $e) {
            throw new PaymentException($e->getMessage(), previous: $e);
        }
    }
}
