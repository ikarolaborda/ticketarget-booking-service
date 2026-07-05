<?php

declare(strict_types=1);

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Money truth for one reservation: exactly one payment per reservation with an
 * explicit lifecycle. Booking rows keep charge_id only as a denormalized
 * projection for display; refund state is reconciled here from the gateway
 * webhook, which remains the source of truth.
 */
final class Payment extends Model
{
    use HasUuids;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_CAPTURED = 'captured';

    public const string STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    public const string STATUS_REFUNDED = 'refunded';

    public const string STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
        ];
    }

    public function markCaptured(string $paymentIntentId): void
    {
        if ($this->status === self::STATUS_CAPTURED && $this->provider_payment_intent_id === $paymentIntentId) {
            return;
        }

        if ($this->status !== self::STATUS_PENDING) {
            throw new DomainException('Cannot capture a payment in status '.$this->status);
        }

        $this->provider_payment_intent_id = $paymentIntentId;
        $this->status = self::STATUS_CAPTURED;
        $this->save();
    }

    public function markFailed(string $reason): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new DomainException('Cannot fail a payment in status '.$this->status);
        }

        $this->failure_reason = mb_substr($reason, 0, 250);
        $this->status = self::STATUS_FAILED;
        $this->save();
    }

    /**
     * Applies the authoritative refunded total reported by the gateway.
     * Monotonic: a webhook replay carrying a smaller amount never shrinks it,
     * and the total is clamped to the captured amount.
     */
    public function applyRefundTotal(int $refundedCents): void
    {
        if ($this->status === self::STATUS_PENDING || $this->status === self::STATUS_FAILED) {
            throw new DomainException('Cannot refund a payment in status '.$this->status);
        }

        $refundedCents = min(max($refundedCents, $this->refundedAmountInCents()), $this->amountInCents());

        $this->refunded_amount = round($refundedCents / 100, 2);

        if ($refundedCents >= $this->amountInCents()) {
            $this->status = self::STATUS_REFUNDED;
        } elseif ($refundedCents > 0) {
            $this->status = self::STATUS_PARTIALLY_REFUNDED;
        }

        $this->save();
    }

    public function amountInCents(): int
    {
        return (int) round(((float) $this->amount) * 100);
    }

    public function refundedAmountInCents(): int
    {
        return (int) round(((float) $this->refunded_amount) * 100);
    }
}
