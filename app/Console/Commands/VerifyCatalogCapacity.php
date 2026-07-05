<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cutover parity check for the capacity read model: compares the ledger's
 * per-event SUM against the catalog tickets table while the shared database
 * still makes both readable. Retired together with the shared DB at schema
 * isolation — the ledger is then the only capacity source.
 */
final class VerifyCatalogCapacity extends Command
{
    protected $signature = 'booking:verify-capacity {--strict : Exit non-zero when drift is found}';

    protected $description = 'Compare the capacity ledger against catalog ticket counts';

    public function handle(): int
    {
        $ledger = DB::table('catalog_capacity_ledger')
            ->select('event_id', DB::raw('SUM(count) AS capacity'))
            ->groupBy('event_id')
            ->pluck('capacity', 'event_id')
            ->map(static fn ($n): int => (int) $n);

        $actual = DB::table('tickets')
            ->select('event_id', DB::raw('COUNT(*) AS capacity'))
            ->groupBy('event_id')
            ->pluck('capacity', 'event_id')
            ->map(static fn ($n): int => (int) $n);

        $mismatches = 0;

        foreach ($actual as $eventId => $capacity) {
            $projected = $ledger[$eventId] ?? null;

            if ($projected !== $capacity) {
                $mismatches++;
                $this->line(sprintf(
                    'DRIFT event=%s tickets=%d ledger=%s',
                    $eventId,
                    $capacity,
                    $projected === null ? 'missing' : (string) $projected,
                ));
            }
        }

        foreach ($ledger as $eventId => $projected) {
            if (! isset($actual[$eventId])) {
                $mismatches++;
                $this->line(sprintf('DRIFT event=%s tickets=missing ledger=%d', $eventId, $projected));
            }
        }

        $this->info(sprintf(
            'Checked %d event(s), %d mismatch(es).',
            count(array_unique([...array_keys($actual->all()), ...array_keys($ledger->all())])),
            $mismatches,
        ));

        if ($mismatches > 0 && (bool) $this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
