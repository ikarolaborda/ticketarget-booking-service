<?php

declare(strict_types=1);

namespace App\Actions;

use App\Domain\Payment\PaymentGateway;
use App\Exceptions\ReservationInvalidException;
use App\Mail\TicketsConfirmationMail;
use App\Models\Booking;
use App\Models\Reservation;
use App\Models\Ticket;
use App\Services\TicketCodeIssuer;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Mail;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a held reservation into a paid booking. Payment happens before the DB
 * commit; the Stripe idempotency key is the reservation id, so a retry after a
 * mid-flight failure neither double-charges nor double-books.
 */
final readonly class ConfirmBookingAction
{
    public function __construct(
        private ConnectionInterface $db,
        private PaymentGateway $payments,
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

        $ticketIds = $reservation->ticket_ids;
        $amountInCents = $this->totalInCents($ticketIds);

        $payment = $this->payments->charge($amountInCents, 'brl', $paymentToken, $reservation->id);

        try {
            $confirmed = $this->db->transaction(function () use ($reservation, $ticketIds, $payment, $email): Reservation {
                $locked = Ticket::query()
                    ->whereIn('id', $ticketIds)
                    ->where('status', Ticket::STATUS_UNAVAILABLE)
                    ->lockForUpdate()
                    ->get();

                if ($locked->count() !== count($ticketIds)) {
                    throw new ReservationInvalidException('Held seats changed before confirmation');
                }

                foreach ($locked as $ticket) {
                    $ticket->status = Ticket::STATUS_BOOKED;
                    $ticket->save();

                    $booking = new Booking;
                    $booking->status = Booking::STATUS_PAID;
                    $booking->reservation_id = $reservation->id;
                    $booking->ticket_id = $ticket->id;
                    $booking->user_id = $reservation->user_id;
                    $booking->email = $email;
                    $booking->charge_id = $payment->chargeId;
                    $booking->amount = $ticket->price;
                    $booking->save();
                }

                $reservation->status = Reservation::STATUS_CONFIRMED;
                $reservation->save();
                $this->logger->info('Booking confirmed', ['reservation_id' => $reservation->id, 'charge_id' => $payment->chargeId]);

                return $reservation->refresh();
            });

            $this->sendTickets($confirmed, $email);

            return $confirmed;
        } catch (Throwable $e) {
            // The charge succeeded but the booking could not be committed, so the
            // money must go back. Refunding here keeps the customer whole; the
            // Stripe webhook reconciles the refund's final state.
            $this->payments->refund($payment->chargeId);
            $this->logger->warning('Refunded charge after failed confirmation', [
                'reservation_id' => $reservation->id,
                'charge_id' => $payment->chargeId,
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * The customer already paid: a mail outage must never fail the booking,
     * so delivery problems are logged and absorbed here, after the commit.
     */
    private function sendTickets(Reservation $reservation, string $email): void
    {
        try {
            $bookings = Booking::query()->where('reservation_id', $reservation->id)->get();
            $seats = Ticket::query()
                ->whereIn('id', $reservation->ticket_ids)
                ->pluck('seat', 'id')
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
        $total = Ticket::query()->whereIn('id', $ticketIds)->sum('price');

        return (int) round(((float) $total) * 100);
    }
}
