<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\SeatInventory;
use App\Models\Ticket;
use App\Services\SeatInventoryProjector;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Releases seats held by reservations whose payment window lapsed. Each
 * reservation is processed in its own locked transaction so a concurrent
 * confirmation cannot race the sweeper, and only tickets still in the
 * `unavailable` hold state are returned to `available` (never booked ones).
 */
final class ReleaseExpiredReservations extends Command
{
    protected $signature = 'booking:release-expired {--limit=500}';

    protected $description = 'Release seats from expired, still-held reservations';

    public function handle(ConnectionInterface $db, SeatInventoryProjector $inventory, LoggerInterface $logger): int
    {
        $limit = (int) $this->option('limit');
        $released = 0;

        Reservation::query()
            ->where('status', Reservation::STATUS_HELD)
            ->where('expires_at', '<', now())
            ->limit($limit)
            ->pluck('id')
            ->each(function (string $reservationId) use ($db, $inventory, &$released): void {
                $db->transaction(function () use ($reservationId, $inventory, &$released): void {
                    $reservation = Reservation::query()->lockForUpdate()->find($reservationId);

                    if ($reservation === null
                        || $reservation->status !== Reservation::STATUS_HELD
                        || ! $reservation->isExpired()
                    ) {
                        return;
                    }

                    /** @var list<string> $ticketIds */
                    $ticketIds = $reservation->ticket_ids;

                    Ticket::query()
                        ->whereIn('id', $ticketIds)
                        ->where('status', Ticket::STATUS_UNAVAILABLE)
                        ->update(['status' => Ticket::STATUS_AVAILABLE]);

                    $inventory->project($ticketIds, SeatInventory::STATUS_AVAILABLE);

                    $reservation->status = Reservation::STATUS_RELEASED;
                    $reservation->save();
                    $released++;
                });
            });

        $logger->info('Expired reservations swept', ['released' => $released]);
        $this->info("Released {$released} reservation(s).");

        return self::SUCCESS;
    }
}
