<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SeatInventory;
use App\Services\CapacityLedgerProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use Throwable;

/**
 * Drains catalog integration events into the local capacity read model.
 * Bounded batch per run (scheduled every minute, like outbox:publish) so a
 * stuck broker cannot wedge the scheduler. Offsets are committed only after
 * the projector has handled the message — at-least-once delivery plus the
 * ledger's event_key dedupe yields effectively-once application. The consumer
 * contract is deliberately minimal: event_key, event_id, zone_id, count —
 * never the tickets[] detail.
 */
final class ConsumeCatalogEvents extends Command
{
    private const string OUTCOME_APPLIED = 'applied';

    private const string OUTCOME_DUPLICATE = 'duplicate';

    private const string OUTCOME_IGNORED = 'ignored';

    protected $signature = 'catalog:consume {--limit=500} {--idle-ms=5000}';

    protected $description = 'Consume catalog events into the local capacity read model';

    public function handle(CapacityLedgerProjector $projector, LoggerInterface $logger): int
    {
        if (! extension_loaded('rdkafka')) {
            $logger->warning('Catalog consume skipped: rdkafka extension unavailable');

            return self::SUCCESS;
        }

        $consumer = $this->consumer();
        $consumer->subscribe([(string) config('booking.catalog_topic')]);

        $limit = (int) $this->option('limit');
        $idleBudgetMs = (int) $this->option('idle-ms');

        $applied = 0;
        $duplicates = 0;
        $ignored = 0;
        $idleMs = 0;

        try {
            while (($applied + $duplicates + $ignored) < $limit && $idleMs < $idleBudgetMs) {
                $message = $consumer->consume(1000);

                if ($message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF
                    || $message->err === RD_KAFKA_RESP_ERR__TIMED_OUT) {
                    $idleMs += 1000;

                    continue;
                }

                if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                    $logger->error('Catalog consume error', ['error' => $message->errstr()]);

                    return self::FAILURE;
                }

                $idleMs = 0;

                match ($this->project($projector, $logger, $message)) {
                    self::OUTCOME_APPLIED => $applied++,
                    self::OUTCOME_DUPLICATE => $duplicates++,
                    self::OUTCOME_IGNORED => $ignored++,
                };

                // Commit AFTER the DB write; a crash between write and commit
                // re-delivers, and the ledger dedupe absorbs the replay.
                $consumer->commit($message);
            }
        } catch (Throwable $e) {
            $logger->error('Catalog consume failed', ['reason' => $e->getMessage()]);
            $this->error('Consume failed: '.$e->getMessage());

            return self::FAILURE;
        }
        // No explicit close(): php-rdkafka 6.0.5 on PHP 8.5 ZTS segfaults
        // intermittently when close() runs before the destructor's own
        // teardown (double-close). Offsets are already committed per message,
        // so destructor-driven teardown loses nothing.

        $this->info(sprintf(
            'Catalog consume: %d applied, %d duplicate(s), %d ignored.',
            $applied,
            $duplicates,
            $ignored,
        ));

        return self::SUCCESS;
    }

    private function project(CapacityLedgerProjector $projector, LoggerInterface $logger, Message $message): string
    {
        $envelope = json_decode((string) $message->payload, true);

        if (! is_array($envelope) || ($envelope['event_type'] ?? null) !== 'ticket.generated') {
            return self::OUTCOME_IGNORED;
        }

        $eventKey = $envelope['event_key'] ?? null;
        $payload = $envelope['payload'] ?? null;
        $eventId = is_array($payload) ? ($payload['event_id'] ?? null) : null;
        $count = is_array($payload) ? ($payload['count'] ?? null) : null;

        if (! is_string($eventKey) || ! is_string($eventId) || ! is_numeric($count)) {
            // Malformed messages are logged and skipped, not retried forever —
            // replaying them would fail identically.
            $logger->warning('Catalog event skipped: malformed ticket.generated', [
                'event_key' => is_string($eventKey) ? $eventKey : null,
            ]);

            return self::OUTCOME_IGNORED;
        }

        $zoneId = is_string($payload['zone_id'] ?? null) ? $payload['zone_id'] : null;

        // Live events carry tickets[] with seat identities; seed new seats
        // into the booking-owned inventory (insert-if-missing — never touches
        // status of existing rows). Backfill events are count-only and rely
        // on booking:seed-inventory instead.
        $this->seedSeats($eventId, $zoneId, is_array($payload['tickets'] ?? null) ? $payload['tickets'] : []);

        return $projector->apply($eventKey, $eventId, $zoneId, (int) $count)
            ? self::OUTCOME_APPLIED
            : self::OUTCOME_DUPLICATE;
    }

    /**
     * @param  list<mixed>  $tickets
     */
    private function seedSeats(string $eventId, ?string $zoneId, array $tickets): void
    {
        $rows = [];

        foreach ($tickets as $ticket) {
            if (! is_array($ticket) || ! is_string($ticket['id'] ?? null)) {
                continue;
            }

            $rows[] = [
                'ticket_id' => $ticket['id'],
                'event_id' => $eventId,
                'status' => SeatInventory::STATUS_AVAILABLE,
                'reservation_id' => null,
                'seat' => is_string($ticket['seat'] ?? null) ? $ticket['seat'] : null,
                'price' => is_numeric($ticket['price'] ?? null) ? $ticket['price'] : null,
                'type' => is_string($ticket['type'] ?? null) ? $ticket['type'] : null,
                'zone_id' => $zoneId,
                'source' => 'event',
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('seat_inventory')->insertOrIgnore($rows);
        }
    }

    private function consumer(): KafkaConsumer
    {
        $conf = new Conf;
        $conf->set('metadata.broker.list', (string) config('booking.kafka_brokers'));
        $conf->set('group.id', (string) config('booking.catalog_consumer_group'));
        $conf->set('auto.offset.reset', 'earliest');
        $conf->set('enable.auto.commit', 'false');

        return new KafkaConsumer($conf);
    }
}
