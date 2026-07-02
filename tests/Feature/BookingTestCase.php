<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class BookingTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Last line of defense: RefreshDatabase must never run against anything
     * but the in-memory sqlite the suite is configured for (tests/bootstrap.php).
     */
    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite') {
            throw new \RuntimeException(sprintf(
                'Refusing to refresh the "%s" database — the test env overrides did not apply.',
                config('database.default'),
            ));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The seat-lock code addresses the store by name ('redis'); the array
        // driver honors the same atomic-lock contract without a live Redis.
        config([
            'cache.stores.redis' => ['driver' => 'array', 'serialize' => false],
            'queue_gate.secret' => 'test-queue-secret',
        ]);

        $this->createTicketsTable();
    }

    /**
     * The tickets table is owned by the Event service's migrations (shared
     * data plane), so booking tests create the minimal schema themselves.
     */
    private function createTicketsTable(): void
    {
        if (Schema::hasTable('tickets')) {
            return;
        }

        Schema::create('tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->string('seat');
            $table->decimal('price', 10, 2);
            $table->string('type')->default('standard');
            $table->string('status')->default('available')->index();
            $table->timestamps();

            $table->unique(['event_id', 'seat']);
        });
    }

    protected function createTicket(
        string $status = Ticket::STATUS_AVAILABLE,
        string $seat = 'A01',
        string $price = '50.00',
    ): Ticket {
        $ticket = new Ticket();
        $ticket->event_id = (string) Str::uuid();
        $ticket->seat = $seat;
        $ticket->price = $price;
        $ticket->type = 'standard';
        $ticket->status = $status;
        $ticket->save();

        return $ticket;
    }

    /**
     * @param list<string> $ticketIds
     */
    protected function createHeldReservation(
        string $userId,
        array $ticketIds,
        ?CarbonInterface $expiresAt = null,
    ): Reservation {
        return Reservation::query()->create([
            'user_id' => $userId,
            'ticket_ids' => $ticketIds,
            'status' => Reservation::STATUS_HELD,
            'expires_at' => $expiresAt ?? now()->addMinutes(10),
        ]);
    }

    /**
     * Builds a token with the exact payload/HMAC layout QueueTokenIssuer signs,
     * so the middleware's validation path is exercised end to end.
     */
    protected function queueToken(?int $expiresAt = null): string
    {
        $payloadJson = json_encode([
            'uid' => (string) Str::uuid(),
            'eid' => (string) Str::uuid(),
            'exp' => $expiresAt ?? time() + 600,
            'nonce' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $payloadJson, (string) config('queue_gate.secret'), true);

        return $this->base64Url($payloadJson).'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
