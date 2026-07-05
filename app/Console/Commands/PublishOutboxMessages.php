<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OutboxMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use RdKafka\Conf;
use RdKafka\Producer;
use RuntimeException;
use Throwable;

/**
 * Ships committed outbox rows to Kafka. Rows are only marked published after
 * the broker acknowledges the flush, so a crash re-sends rather than loses;
 * consumers deduplicate on event_key.
 */
final class PublishOutboxMessages extends Command
{
    protected $signature = 'outbox:publish {--limit=200}';

    protected $description = 'Publish pending outbox messages to Kafka';

    public function handle(LoggerInterface $logger): int
    {
        $rows = OutboxMessage::query()
            ->whereNull('published_at')
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($rows->isEmpty()) {
            return self::SUCCESS;
        }

        if (! extension_loaded('rdkafka')) {
            $logger->warning('Outbox publish skipped: rdkafka extension unavailable', ['pending' => $rows->count()]);

            return self::SUCCESS;
        }

        try {
            $this->publish($rows->all());
        } catch (Throwable $e) {
            OutboxMessage::query()
                ->whereIn('id', $rows->pluck('id'))
                ->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error' => mb_substr($e->getMessage(), 0, 250),
                ]);

            $logger->error('Outbox publish failed', ['reason' => $e->getMessage()]);
            $this->error('Publish failed: '.$e->getMessage());

            return self::FAILURE;
        }

        OutboxMessage::query()
            ->whereIn('id', $rows->pluck('id'))
            ->update(['published_at' => now()]);

        $this->info(sprintf('Published %d outbox message(s).', $rows->count()));

        return self::SUCCESS;
    }

    /**
     * @param  list<OutboxMessage>  $rows
     */
    private function publish(array $rows): void
    {
        $conf = new Conf;
        $conf->set('metadata.broker.list', (string) config('booking.kafka_brokers'));
        $conf->set('message.timeout.ms', '8000');

        $producer = new Producer($conf);
        $topic = $producer->newTopic((string) config('booking.outbox_topic'));

        foreach ($rows as $row) {
            $topic->produce(RD_KAFKA_PARTITION_UA, 0, json_encode([
                'id' => $row->id,
                'aggregate_type' => $row->aggregate_type,
                'aggregate_id' => $row->aggregate_id,
                'event_type' => $row->event_type,
                'event_key' => $row->event_key,
                'occurred_at' => (string) $row->created_at,
                'payload' => $row->payload,
            ], JSON_THROW_ON_ERROR), $row->event_key);

            $producer->poll(0);
        }

        if ($producer->flush(10000) !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw new RuntimeException('Kafka flush timed out with unsent outbox messages');
        }
    }
}
