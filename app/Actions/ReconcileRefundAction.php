<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Booking;
use App\Models\Ticket;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies a charge.refunded event to local state — the authoritative side of
 * the refund flow. Pending rows we initiated complete first; a FULLY refunded
 * charge with nothing pending (dashboard refund) completes every live row.
 * Partial external refunds with nothing pending are ambiguous on multi-seat
 * charges, so they are logged for a human instead of guessed at.
 */
final readonly class ReconcileRefundAction
{
    public function __construct(
        private ConnectionInterface $db,
        private LoggerInterface $logger,
    ) {
    }

    public function execute(string $paymentIntentId, int $amountRefunded, int $chargeAmount): void
    {
        $pending = Booking::query()
            ->where('charge_id', $paymentIntentId)
            ->where('status', Booking::STATUS_REFUND_PENDING)
            ->orderBy('id')
            ->pluck('id');

        if ($pending->isNotEmpty()) {
            $pending->each(fn (string $id) => $this->complete($id));

            return;
        }

        if ($amountRefunded >= $chargeAmount) {
            Booking::query()
                ->where('charge_id', $paymentIntentId)
                ->where('status', '!=', Booking::STATUS_REFUNDED)
                ->orderBy('id')
                ->pluck('id')
                ->each(fn (string $id) => $this->complete($id));

            return;
        }

        $this->logger->warning('Partial external refund with no pending booking — manual review needed', [
            'payment_intent' => $paymentIntentId,
            'amount_refunded' => $amountRefunded,
        ]);
    }

    private function complete(string $bookingId): void
    {
        $this->db->transaction(function () use ($bookingId): void {
            $booking = Booking::query()->lockForUpdate()->find($bookingId);

            if ($booking === null || $booking->status === Booking::STATUS_REFUNDED) {
                return;
            }

            // Release the seat only from the booked state — a seat the sweeper
            // or a resale already moved must never be yanked back.
            Ticket::query()
                ->where('id', $booking->ticket_id)
                ->where('status', Ticket::STATUS_BOOKED)
                ->update(['status' => Ticket::STATUS_AVAILABLE]);

            $booking->status = Booking::STATUS_REFUNDED;
            $booking->save();

            $this->logger->info('Refund reconciled; seat released', ['booking_id' => $bookingId]);
        });
    }
}
