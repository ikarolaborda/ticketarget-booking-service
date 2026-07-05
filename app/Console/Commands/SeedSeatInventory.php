<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SeatInventory;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off cutover seed: gives every catalog ticket a booking-owned inventory
 * row. Runs while the shared database still exists (documented cutover read,
 * same pattern as the snapshot backfills). Rerunnable: existing rows only
 * gain missing identity fields; status and reservation_id are never touched.
 * New tickets after the seed arrive through ticket.generated events.
 */
final class SeedSeatInventory extends Command
{
    protected $signature = 'booking:seed-inventory {--chunk=500}';

    protected $description = 'Seed seat_inventory from catalog tickets (fill-missing-only)';

    private const array STATUS_MAP = [
        Ticket::STATUS_AVAILABLE => SeatInventory::STATUS_AVAILABLE,
        Ticket::STATUS_UNAVAILABLE => SeatInventory::STATUS_HELD,
        Ticket::STATUS_BOOKED => SeatInventory::STATUS_BOOKED,
    ];

    public function handle(): int
    {
        $inserted = 0;
        $enriched = 0;
        $skipped = 0;

        DB::table('tickets')->orderBy('id')->chunk((int) $this->option('chunk'), function ($tickets) use (&$inserted, &$enriched, &$skipped): void {
            $existing = DB::table('seat_inventory')
                ->whereIn('ticket_id', $tickets->pluck('id'))
                ->get(['ticket_id', 'seat'])
                ->keyBy('ticket_id');

            foreach ($tickets as $ticket) {
                $row = $existing[$ticket->id] ?? null;

                if ($row === null) {
                    DB::table('seat_inventory')->insertOrIgnore([
                        'ticket_id' => $ticket->id,
                        'event_id' => $ticket->event_id,
                        'status' => self::STATUS_MAP[$ticket->status] ?? SeatInventory::STATUS_AVAILABLE,
                        'reservation_id' => null,
                        'seat' => $ticket->seat,
                        'price' => $ticket->price,
                        'type' => $ticket->type,
                        'zone_id' => $ticket->zone_id,
                        'source' => 'seed',
                        'updated_at' => now(),
                    ]);
                    $inserted++;

                    continue;
                }

                if ($row->seat === null) {
                    DB::table('seat_inventory')->where('ticket_id', $ticket->id)->update([
                        'event_id' => $ticket->event_id,
                        'seat' => $ticket->seat,
                        'price' => $ticket->price,
                        'type' => $ticket->type,
                        'zone_id' => $ticket->zone_id,
                        'source' => 'seed',
                    ]);
                    $enriched++;

                    continue;
                }

                $skipped++;
            }
        });

        $this->info(sprintf('Seed complete: %d inserted, %d enriched, %d already present.', $inserted, $enriched, $skipped));

        return self::SUCCESS;
    }
}
