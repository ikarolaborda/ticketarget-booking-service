<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Payment\PaymentException;
use App\Domain\Payment\PaymentGateway;
use App\Domain\Payment\PaymentResult;

/**
 * In-memory gateway double that records every charge/refund so tests can
 * assert the exact compensation behavior of the booking flow.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var list<array{charge_id: string, amount: int, currency: string, token: string, idempotency_key: string}> */
    public array $charges = [];

    /** @var list<string> */
    public array $refunds = [];

    public bool $failCharge = false;

    public function charge(int $amountInCents, string $currency, string $paymentToken, string $idempotencyKey): PaymentResult
    {
        if ($this->failCharge) {
            throw new PaymentException('Your card was declined.');
        }

        $chargeId = 'ch_fake_'.(count($this->charges) + 1);

        $this->charges[] = [
            'charge_id' => $chargeId,
            'amount' => $amountInCents,
            'currency' => $currency,
            'token' => $paymentToken,
            'idempotency_key' => $idempotencyKey,
        ];

        return new PaymentResult($chargeId, $amountInCents, $currency);
    }

    public function refund(string $chargeId): void
    {
        $this->refunds[] = $chargeId;
    }
}
