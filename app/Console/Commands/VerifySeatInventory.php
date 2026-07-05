<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SeatInventory;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Post-cutover drift detector: seat_inventory is the source of truth and
 * catalog tickets.status is the mirrored shadow (rollback bridge). Drift
 * here means the CATALOG_STATUS_DUAL_WRITE mirror is broken — resolve it
 * before considering the rollback window closed. Retired together with the
 * mirror flag and the shared database.
 */
final class VerifySeatInventory extends Command
{
    protected $signature = 'booking:verify-inventory {--strict : Exit non-zero when drift is found}';

    protected $description = 'Compare the mirrored catalog ticket statuses against authoritative seat inventory';

    private const array EXPECTED = [
        SeatInventory::STATUS_AVAILABLE => Ticket::STATUS_AVAILABLE,
        SeatInventory::STATUS_HELD => Ticket::STATUS_UNAVAILABLE,
        SeatInventory::STATUS_BOOKED => Ticket::STATUS_BOOKED,
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
                $mirrored = $ticketStatuses[$row->ticket_id] ?? null;
                $expected = self::EXPECTED[$row->status] ?? null;

                if ($mirrored !== $expected) {
                    $mismatches++;
                    $this->line(sprintf(
                        'DRIFT ticket=%s inventory_status=%s mirrored_ticket_status=%s',
                        $row->ticket_id,
                        $row->status,
                        $mirrored ?? 'missing',
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
