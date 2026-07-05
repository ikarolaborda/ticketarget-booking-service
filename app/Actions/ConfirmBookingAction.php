<?php

declare(strict_types=1);

namespace App\Actions;

use App\Domain\Payment\PaymentGateway;
use App\Exceptions\ReservationInvalidException;
use App\Mail\TicketsConfirmationMail;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\SeatInventory;
use App\Services\OutboxWriter;
use App\Services\SeatInventoryProjector;
use App\Services\TicketCodeIssuer;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a held reservation into a paid booking. Payment happens before the DB
 * commit; the Stripe idempotency key is the reservation id, so a retry after a
 * mid-flight failure neither double-charges nor double-books. Money state
 * lives on the Payment aggregate (one per reservation); booking rows carry
 * charge_id only as a denormalized projection.
 */
final readonly class ConfirmBookingAction
{
    public function __construct(
        private ConnectionInterface $db,
        private PaymentGateway $payments,
        private OutboxWriter $outbox,
        private SeatInventoryProjector $inventory,
        private LoggerInterface $logger,
    ) {}

    public function execute(string $reservationId, string $userId, string $paymentToken, string $email): Reservation
    {
        $reservation = Reservation::query()->find($reservationId);

        if ($reservation === null
            || $reservation->user_id !== $userId
            || $reservation->status !== Reservation::STATUS_HELD
            || $reservation->isExpired()
        ) {
            throw new ReservationInvalidException('Reservation is missing, expired, or not held by this user');
        }

        /** @var list<string> $ticketIds */
        $ticketIds = $reservation->ticket_ids;
        $amountInCents = $this->totalInCents($ticketIds);

        $payment = $this->preparePayment($reservation->id, $amountInCents);

        try {
            $result = $this->payments->charge($amountInCents, 'brl', $paymentToken, $reservation->id);
        } catch (Throwable $e) {
            $payment->markFailed($e->getMessage());

            throw $e;
        }

        $payment->markCaptured($result->chargeId);

        try {
            $confirmed = $this->db->transaction(function () use ($reservation, $ticketIds, $payment, $email): Reservation {
                $locked = SeatInventory::query()
                    ->whereIn('ticket_id', $ticketIds)
                    ->where('status', SeatInventory::STATUS_HELD)
                    ->lockForUpdate()
                    ->get();

                if ($locked->count() !== count($ticketIds)) {
                    throw new ReservationInvalidException('Held seats changed before confirmation');
                }

                // Documented residual catalog read (events only, never
                // tickets): event name/date snapshots move to an event-fed
                // directory read model before schema isolation.
                $events = DB::table('events')
                    ->whereIn('id', $locked->pluck('event_id')->filter()->unique())
                    ->get(['id', 'name', 'date'])
                    ->keyBy('id');

                foreach ($locked as $seat) {
                    $event = $seat->event_id !== null ? ($events[$seat->event_id] ?? null) : null;

                    $booking = new Booking;
                    $booking->status = Booking::STATUS_PAID;
                    $booking->reservation_id = $reservation->id;
                    $booking->ticket_id = $seat->ticket_id;
                    $booking->user_id = $reservation->user_id;
                    $booking->email = $email;
                    $booking->payment_id = $payment->id;
                    $booking->charge_id = $payment->provider_payment_intent_id;
                    $booking->amount = $seat->price;
                    $booking->seat = $seat->seat;
                    $booking->ticket_type = $seat->type;
                    $booking->event_id = $seat->event_id;
                    $booking->event_name = $event->name ?? null;
                    $booking->event_date = $event->date ?? null;
                    $booking->save();
                }

                $this->inventory->transition($ticketIds, SeatInventory::STATUS_BOOKED, $reservation->id);

                $this->outbox->write('payment', $payment->id, 'payment.captured', 'payment.captured:'.$payment->id, [
                    'payment_id' => $payment->id,
                    'reservation_id' => $reservation->id,
                    'amount_cents' => $payment->amountInCents(),
                    'currency' => 'brl',
                ]);
                $this->outbox->write('reservation', $reservation->id, 'booking.confirmed', 'booking.confirmed:'.$reservation->id, [
                    'reservation_id' => $reservation->id,
                    'ticket_ids' => $ticketIds,
                    'user_id' => $reservation->user_id,
                ]);

                $reservation->status = Reservation::STATUS_CONFIRMED;
                $reservation->save();
                $this->logger->info('Booking confirmed', ['reservation_id' => $reservation->id, 'charge_id' => $payment->provider_payment_intent_id]);

                return $reservation->refresh();
            });

            $this->sendTickets($confirmed, $email);

            return $confirmed;
        } catch (Throwable $e) {
            // The charge succeeded but the booking could not be committed, so the
            // money must go back. Refunding here keeps the customer whole; the
            // Stripe webhook reconciles the refund's final state.
            $this->payments->refund($payment->provider_payment_intent_id);
            $payment->applyRefundTotal($payment->amountInCents());
            $this->outbox->write('payment', $payment->id, 'payment.refunded', 'payment.refunded:'.$payment->id.':'.$payment->refundedAmountInCents(), [
                'payment_id' => $payment->id,
                'reservation_id' => $reservation->id,
                'refunded_cents' => $payment->refundedAmountInCents(),
            ]);
            $this->logger->warning('Refunded charge after failed confirmation', [
                'reservation_id' => $reservation->id,
                'charge_id' => $payment->provider_payment_intent_id,
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Reuses the reservation's payment row on retry (a mid-flight failure
     * leaves a failed/refunded row behind; the reservation is still held, so a
     * new attempt resets it to pending). The unique reservation_id constraint
     * arbitrates a concurrent duplicate — the loser adopts the winner's row.
     */
    private function preparePayment(string $reservationId, int $amountInCents): Payment
    {
        $payment = Payment::query()->where('reservation_id', $reservationId)->first();

        if ($payment === null) {
            $payment = new Payment;
            $payment->reservation_id = $reservationId;
        }

        $payment->provider = 'stripe';
        $payment->amount = round($amountInCents / 100, 2);
        $payment->currency = 'brl';
        $payment->status = Payment::STATUS_PENDING;
        $payment->refunded_amount = 0.0;
        $payment->failure_reason = null;
        $payment->idempotency_key = $reservationId;

        try {
            $payment->save();
        } catch (UniqueConstraintViolationException) {
            $payment = Payment::query()->where('reservation_id', $reservationId)->firstOrFail();
        }

        return $payment;
    }

    /**
     * The customer already paid: a mail outage must never fail the booking,
     * so delivery problems are logged and absorbed here, after the commit.
     */
    private function sendTickets(Reservation $reservation, string $email): void
    {
        try {
            $bookings = Booking::query()->where('reservation_id', $reservation->id)->get();
            $seats = SeatInventory::query()
                ->whereIn('ticket_id', $reservation->ticket_ids)
                ->pluck('seat', 'ticket_id')
                ->all();

            $codes = app(TicketCodeIssuer::class);
            $entryCodes = $bookings->mapWithKeys(
                fn (Booking $booking) => [$booking->id => $codes->issue($booking->id)],
            )->all();

            Mail::to($email)->send(new TicketsConfirmationMail($reservation, $bookings, $seats, $entryCodes));
        } catch (Throwable $e) {
            $this->logger->error('Ticket email failed after confirmed booking', [
                'reservation_id' => $reservation->id,
                'email' => $email,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<string>  $ticketIds
     */
    private function totalInCents(array $ticketIds): int
    {
        $total = SeatInventory::query()->whereIn('ticket_id', $ticketIds)->sum('price');

        return (int) round(((float) $total) * 100);
    }
}
