<?php

declare(strict_types=1);

namespace App\Domain\Payment;

final readonly class PaymentResult
{
    public function __construct(
        public string $chargeId,
        public int $amountInCents,
        public string $currency,
    ) {
    }
}
