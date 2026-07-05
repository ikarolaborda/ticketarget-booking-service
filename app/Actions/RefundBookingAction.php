<?php

declare(strict_types=1);

namespace App\Actions;

use App\Domain\Payment\PaymentException;
use App\Domain\Payment\PaymentGateway;
use App\Exceptions\RefundNotAllowedException;
use App\Models\Booking;
use App\Services\TicketCodeIssuer;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a booking refund_pending under the policy tiers and asks Stripe for the
 * money back. The webhook (charge.refunded) — not this action — is the source
 * of truth that completes the refund and releases the seat.
 */
final readonly class RefundBookingAction
{
    private const int FULL_REFUND_MIN_HOURS = 168;

    private const int HALF_REFUND_MIN_HOURS = 48;

    public function __construct(
        private ConnectionInterface $db,
        private PaymentGateway $payments,
        private TicketCodeIssuer $codes,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{refund_tier: int, amount_cents: int}
     */
    public function execute(string $bookingId, ?string $authUserId, ?string $entryCode): array
    {
        $booking = Booking::query()->find($bookingId);

        // Uniform 403 whether the booking is missing or simply not theirs.
        if ($booking === null || ! $this->authorized($booking, $authUserId, $entryCode)) {
            throw new RefundNotAllowedException('You cannot refund this booking.', Response::HTTP_FORBIDDEN);
        }

        [$tier, $amountCents] = $this->db->transaction(function () use ($bookingId): array {
            $booking = Booking::query()->lockForUpdate()->findOrFail($bookingId);

            if ($booking->status !== Booking::STATUS_PAID) {
                throw new RefundNotAllowedException('This booking is not refundable (already refunded or in progress).', Response::HTTP_CONFLICT);
            }

            // The confirmation-time snapshot avoids the cross-context join;
            // rows created before the snapshot column fall back to it.
            $eventDate = $booking->getRawOriginal('event_date')
                ?? DB::table('tickets')
                    ->leftJoin('events', 'events.id', '=', 'tickets.event_id')
                    ->where('tickets.id', $booking->ticket_id)
                    ->value('events.date');

            $tier = $this->tierFor($eventDate);

            // Amount is ALWAYS explicit: one payment intent can cover several
            // seats, so "refund everything" is never what a single-booking
            // refund means.
            $amountCents = (int) round(((float) $booking->amount) * 100 * $tier);

            $booking->status = Booking::STATUS_REFUND_PENDING;
            $booking->save();

            return [(int) ($tier * 100), $amountCents];
        });

        try {
            $this->payments->refund($booking->charge_id, $amountCents, 'refund:'.$bookingId);
        } catch (PaymentException $e) {
            // Guarded reversion: the webhook may already have completed the
            // refund despite the API error, so only revert if still pending.
            Booking::query()
                ->where('id', $bookingId)
                ->where('status', Booking::STATUS_REFUND_PENDING)
                ->update(['status' => Booking::STATUS_PAID]);

            $this->logger->warning('Refund request failed at the gateway', [
                'booking_id' => $bookingId,
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->logger->info('Refund requested', [
            'booking_id' => $bookingId,
            'tier' => $tier,
            'amount_cents' => $amountCents,
        ]);

        return ['refund_tier' => $tier, 'amount_cents' => $amountCents];
    }

    private function authorized(Booking $booking, ?string $authUserId, ?string $entryCode): bool
    {
        if ($authUserId !== null && $booking->user_id === $authUserId) {
            return true;
        }

        return $entryCode !== null && $this->codes->verify($entryCode) === $booking->id;
    }

    /**
     * @return float 1.0 or 0.5 — anything else refuses with 422
     */
    private function tierFor(?string $eventDate): float
    {
        if ($eventDate === null) {
            throw new RefundNotAllowedException('This booking cannot be refunded automatically.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $hoursUntilEvent = now()->diffInMinutes(CarbonImmutable::parse($eventDate), false) / 60;

        if ($hoursUntilEvent >= self::FULL_REFUND_MIN_HOURS) {
            return 1.0;
        }

        if ($hoursUntilEvent >= self::HALF_REFUND_MIN_HOURS) {
            return 0.5;
        }

        throw new RefundNotAllowedException('Refunds close 48 hours before the event.', Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
