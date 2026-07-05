<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SeatInventory;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

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

    public function handle(LoggerInterface $logger): int
    {
        $checked = 0;
        $mismatches = 0;
        $samples = [];

        DB::table('seat_inventory')->orderBy('ticket_id')->chunk(500, function ($rows) use (&$checked, &$mismatches, &$samples): void {
            $ticketStatuses = DB::table('tickets')
                ->whereIn('id', $rows->pluck('ticket_id'))
                ->pluck('status', 'id');

            foreach ($rows as $row) {
                $checked++;
                $mirrored = $ticketStatuses[$row->ticket_id] ?? null;
                $expected = self::EXPECTED[$row->status] ?? null;

                if ($mirrored !== $expected) {
                    $mismatches++;

                    if (count($samples) < 10) {
                        $samples[] = $row->ticket_id;
                    }

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

        // Structured log per run: the zero-drift window that gates the
        // CATALOG_STATUS_DUAL_WRITE flag-off is evidenced by querying these
        // entries, not by whoever happened to watch the console.
        $context = ['checked' => $checked, 'mismatches' => $mismatches, 'sample_ticket_ids' => $samples];

        if ($mismatches > 0) {
            $logger->warning('Inventory drift detected', $context);
        } else {
            $logger->info('Inventory drift check clean', $context);
        }

        if ($mismatches > 0 && (bool) $this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
