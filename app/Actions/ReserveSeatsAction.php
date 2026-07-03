<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\SeatUnavailableException;
use App\Models\Reservation;
use App\Models\Ticket;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Holds seats for a user without overselling, using three layered guarantees:
 *
 *   1. A short-lived Redis lock per seat (Sentinel-backed) serializes the
 *      critical section across every Booking replica.
 *   2. `SELECT … FOR UPDATE` re-checks availability against the primary inside a
 *      transaction, so a stale read can never let two buyers through.
 *   3. The ticket status flag + reservation TTL hold the seat until payment.
 *
 * If any seat cannot be locked or is no longer available the whole reservation
 * is rejected atomically.
 */
final readonly class ReserveSeatsAction
{
    private const int LOCK_SECONDS = 15;

    private const int HOLD_MINUTES = 10;

    public function __construct(
        private ConnectionInterface $db,
        private CacheFactory $cache,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param  list<string>  $ticketIds
     */
    public function execute(string $userId, array $ticketIds): Reservation
    {
        $ticketIds = array_values(array_unique($ticketIds));
        sort($ticketIds); // deterministic lock order prevents deadlocks

        $locks = $this->acquireLocks($ticketIds);

        try {
            return $this->db->transaction(function () use ($userId, $ticketIds): Reservation {
                $held = Ticket::query()
                    ->whereIn('id', $ticketIds)
                    ->where('status', Ticket::STATUS_AVAILABLE)
                    ->lockForUpdate()
                    ->get();

                if ($held->count() !== count($ticketIds)) {
                    throw new SeatUnavailableException;
                }

                Ticket::query()
                    ->whereIn('id', $ticketIds)
                    ->update(['status' => Ticket::STATUS_UNAVAILABLE]);

                $reservation = new Reservation;
                $reservation->user_id = $userId;
                $reservation->ticket_ids = $ticketIds;
                $reservation->status = Reservation::STATUS_HELD;
                $reservation->expires_at = now()->addMinutes(self::HOLD_MINUTES);
                $reservation->save();

                return $reservation;
            });
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }

    /**
     * @param  list<string>  $ticketIds
     * @return list<Lock>
     */
    private function acquireLocks(array $ticketIds): array
    {
        $store = $this->cache->store('redis');
        $acquired = [];

        foreach ($ticketIds as $ticketId) {
            $lock = $store->lock("seat:{$ticketId}", self::LOCK_SECONDS);

            if (! $lock->get()) {
                foreach ($acquired as $held) {
                    $held->release();
                }

                $this->logger->info('Seat lock contention', ['ticket_id' => $ticketId]);
                throw new SeatUnavailableException('Seat is being reserved by another user');
            }

            $acquired[] = $lock;
        }

        return $acquired;
    }
}
