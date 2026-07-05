<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payment\PaymentGateway;
use App\Models\Ticket;
use App\Services\EventDirectoryProjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\FakePaymentGateway;

/**
 * The event directory is the booking-owned source for confirm-time
 * name/date snapshots; the catalog events table is only a shared-DB
 * fallback until the backfill converges.
 */
final class EventDirectoryTest extends BookingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PaymentGateway::class, new FakePaymentGateway);

        Mail::fake();
    }

    public function test_projector_applies_a_new_directory_row(): void
    {
        $eventId = (string) Str::uuid();

        $applied = app(EventDirectoryProjector::class)
            ->apply($eventId, 'Projected Fest', '2026-08-01T20:00:00+00:00', '2026-07-05T14:00:00.123456+00:00');

        $this->assertTrue($applied);

        $row = DB::table('catalog_event_directory')->sole();
        $this->assertSame($eventId, $row->event_id);
        $this->assertSame('Projected Fest', $row->name);
        $this->assertSame('2026-07-05 14:00:00.123456', $row->occurred_at);
    }

    public function test_projector_rejects_stale_deliveries(): void
    {
        $eventId = (string) Str::uuid();
        $projector = app(EventDirectoryProjector::class);

        $projector->apply($eventId, 'Newer Name', null, '2026-07-05T14:00:00.200000+00:00');

        $stale = $projector->apply($eventId, 'Older Name', null, '2026-07-05T14:00:00.100000+00:00');

        $this->assertFalse($stale);
        $this->assertSame('Newer Name', DB::table('catalog_event_directory')->sole()->name);
    }

    public function test_projector_overwrites_on_equal_occurred_at(): void
    {
        $eventId = (string) Str::uuid();
        $projector = app(EventDirectoryProjector::class);

        $projector->apply($eventId, 'First Delivery', null, '2026-07-05T14:00:00.300000+00:00');

        $replay = $projector->apply($eventId, 'Replayed Delivery', null, '2026-07-05T14:00:00.300000+00:00');

        $this->assertTrue($replay);
        $this->assertSame('Replayed Delivery', DB::table('catalog_event_directory')->sole()->name);
    }

    public function test_projector_applies_newer_updates(): void
    {
        $eventId = (string) Str::uuid();
        $projector = app(EventDirectoryProjector::class);

        $projector->apply($eventId, 'Old Name', null, '2026-07-05T14:00:00.100000+00:00');

        $newer = $projector->apply($eventId, 'New Name', null, '2026-07-05T14:00:01.000000+00:00');

        $this->assertTrue($newer);
        $this->assertSame('New Name', DB::table('catalog_event_directory')->sole()->name);
    }

    public function test_confirm_prefers_the_directory_snapshot_over_catalog(): void
    {
        $eventId = (string) Str::uuid();
        DB::table('events')->insert([
            'id' => $eventId,
            'name' => 'Catalog Name',
            'date' => now()->addDays(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(EventDirectoryProjector::class)
            ->apply($eventId, 'Directory Name', now()->addDays(10)->toIso8601String(), now()->format('Y-m-d\TH:i:s.uP'));

        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE, 'E01');
        $this->assignTicketToEvent($ticket->id, $eventId);

        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$ticket->id]);
        $this->confirm($reservation->id, $userId)->assertCreated();

        $booking = DB::table('bookings')->sole();
        $this->assertSame('Directory Name', $booking->event_name);
        $this->assertNotNull($booking->event_date);
    }

    public function test_confirm_falls_back_to_catalog_and_writes_through(): void
    {
        $eventId = (string) Str::uuid();
        DB::table('events')->insert([
            'id' => $eventId,
            'name' => 'Fallback Fest',
            'date' => now()->addDays(15),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE, 'E02');
        $this->assignTicketToEvent($ticket->id, $eventId);

        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$ticket->id]);
        $this->confirm($reservation->id, $userId)->assertCreated();

        $this->assertSame('Fallback Fest', DB::table('bookings')->sole()->event_name);

        // Write-through: the miss healed itself, so the next confirm for this
        // event never touches the catalog table again.
        $directory = DB::table('catalog_event_directory')->sole();
        $this->assertSame($eventId, $directory->event_id);
        $this->assertSame('Fallback Fest', $directory->name);
    }
}
