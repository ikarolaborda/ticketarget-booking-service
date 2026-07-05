<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\SeatInventory;
use App\Services\OutboxWriter;
use App\Services\SeatInventoryProjector;
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
        private OutboxWriter $outbox,
        private SeatInventoryProjector $inventory,
        private LoggerInterface $logger,
    ) {}

    public function execute(string $paymentIntentId, int $amountRefunded, int $chargeAmount): void
    {
        $this->reconcilePayment($paymentIntentId, $amountRefunded);

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
            $released = SeatInventory::query()
                ->where('ticket_id', $booking->ticket_id)
                ->where('status', SeatInventory::STATUS_BOOKED)
                ->count();

            if ($released > 0) {
                $this->inventory->transition([$booking->ticket_id], SeatInventory::STATUS_AVAILABLE);
            }

            $booking->status = Booking::STATUS_REFUNDED;
            $booking->save();

            $this->logger->info('Refund reconciled; seat released', ['booking_id' => $bookingId]);
        });
    }

    /**
     * The webhook total is authoritative for the Payment aggregate. Legacy
     * bookings created before the payments table degrade gracefully: no
     * matching payment simply means only booking rows are reconciled.
     */
    private function reconcilePayment(string $paymentIntentId, int $amountRefunded): void
    {
        $payment = Payment::query()->where('provider_payment_intent_id', $paymentIntentId)->first();

        if ($payment === null) {
            return;
        }

        $payment->applyRefundTotal(min($amountRefunded, $payment->amountInCents()));

        $this->outbox->write('payment', $payment->id, 'payment.refunded', 'payment.refunded:'.$payment->id.':'.$payment->refundedAmountInCents(), [
            'payment_id' => $payment->id,
            'reservation_id' => $payment->reservation_id,
            'refunded_cents' => $payment->refundedAmountInCents(),
        ]);
    }
}
