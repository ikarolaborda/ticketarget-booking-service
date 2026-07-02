<?php

declare(strict_types=1);

namespace App\Actions;

use App\Domain\Payment\PaymentGateway;
use App\Exceptions\ReservationInvalidException;
use App\Models\Booking;
use App\Models\Reservation;
use App\Models\Ticket;
use Illuminate\Database\ConnectionInterface;
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
    ) {
    }

    public function execute(string $reservationId, string $userId, string $paymentToken): Reservation
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
            return $this->db->transaction(function () use ($reservation, $ticketIds, $payment): Reservation {
                $locked = Ticket::query()
                    ->whereIn('id', $ticketIds)
                    ->where('status', Ticket::STATUS_UNAVAILABLE)
                    ->lockForUpdate()
                    ->get();

                if ($locked->count() !== count($ticketIds)) {
                    throw new ReservationInvalidException('Held seats changed before confirmation');
                }

                foreach ($locked as $ticket) {
                    $ticket->update(['status' => Ticket::STATUS_BOOKED]);
                    Booking::query()->create([
                        'reservation_id' => $reservation->id,
                        'ticket_id' => $ticket->id,
                        'user_id' => $reservation->user_id,
                        'charge_id' => $payment->chargeId,
                        'amount' => $ticket->price,
                    ]);
                }

                $reservation->update(['status' => Reservation::STATUS_CONFIRMED]);
                $this->logger->info('Booking confirmed', ['reservation_id' => $reservation->id, 'charge_id' => $payment->chargeId]);

                return $reservation->refresh();
            });
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
     * @param list<string> $ticketIds
     */
    private function totalInCents(array $ticketIds): int
    {
        $total = Ticket::query()->whereIn('id', $ticketIds)->sum('price');

        return (int) round(((float) $total) * 100);
    }
}
