<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SeatInventory;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Shadow-mode drift detector (DDD remediation Phase 2): compares every
 * seat_inventory row against tickets.status (the source of truth). Sustained
 * zero drift is the cutover criterion for flipping inventory ownership.
 */
final class VerifySeatInventory extends Command
{
    protected $signature = 'booking:verify-inventory {--strict : Exit non-zero when drift is found}';

    protected $description = 'Compare shadow seat inventory against the authoritative ticket statuses';

    private const array EXPECTED = [
        Ticket::STATUS_AVAILABLE => SeatInventory::STATUS_AVAILABLE,
        Ticket::STATUS_UNAVAILABLE => SeatInventory::STATUS_HELD,
        Ticket::STATUS_BOOKED => SeatInventory::STATUS_BOOKED,
    ];

    public function handle(): int
    {
        $checked = 0;
        $mismatches = 0;

        DB::table('seat_inventory')->orderBy('ticket_id')->chunk(500, function ($rows) use (&$checked, &$mismatches): void {
            $ticketStatuses = DB::table('tickets')
                ->whereIn('id', $rows->pluck('ticket_id'))
                ->pluck('status', 'id');

            foreach ($rows as $row) {
                $checked++;
                $ticketStatus = $ticketStatuses[$row->ticket_id] ?? null;
                $expected = $ticketStatus === null ? null : (self::EXPECTED[$ticketStatus] ?? null);

                if ($expected !== $row->status) {
                    $mismatches++;
                    $this->line(sprintf(
                        'DRIFT ticket=%s ticket_status=%s inventory_status=%s',
                        $row->ticket_id,
                        $ticketStatus ?? 'missing',
                        $row->status,
                    ));
                }
            }
        });

        $this->info(sprintf('Checked %d inventory row(s), %d mismatch(es).', $checked, $mismatches));

        if ($mismatches > 0 && (bool) $this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
