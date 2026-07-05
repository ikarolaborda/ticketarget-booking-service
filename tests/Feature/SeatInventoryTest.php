<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ReconcileRefundAction;
use App\Actions\ReserveSeatsAction;
use App\Domain\Payment\PaymentGateway;
use App\Models\SeatInventory;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\FakePaymentGateway;

final class SeatInventoryTest extends BookingTestCase
{
    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        Mail::fake();
    }

    public function test_it_transitions_inventory_through_reserve_confirm_and_release(): void
    {
        $ticket = $this->createTicket();
        $userId = (string) Str::uuid();

        $reservation = app(ReserveSeatsAction::class)->execute($userId, [$ticket->id]);

        $this->assertDatabaseHas('seat_inventory', [
            'ticket_id' => $ticket->id,
            'status' => SeatInventory::STATUS_HELD,
            'reservation_id' => $reservation->id,
        ]);

        $this->confirm($reservation->id, $userId)->assertCreated();

        $this->assertDatabaseHas('seat_inventory', [
            'ticket_id' => $ticket->id,
            'status' => SeatInventory::STATUS_BOOKED,
        ]);

        $expired = $this->createTicket(Ticket::STATUS_AVAILABLE, 'B01');
        $sweptReservation = app(ReserveSeatsAction::class)->execute((string) Str::uuid(), [$expired->id]);
        $sweptReservation->expires_at = now()->subMinute();
        $sweptReservation->save();

        $this->artisan('booking:release-expired')->assertExitCode(0);

        $this->assertDatabaseHas('seat_inventory', [
            'ticket_id' => $expired->id,
            'status' => SeatInventory::STATUS_AVAILABLE,
            'reservation_id' => null,
        ]);
    }

    public function test_it_mirrors_transitions_to_catalog_tickets_while_dual_write_is_on(): void
    {
        $ticket = $this->createTicket();

        app(ReserveSeatsAction::class)->execute((string) Str::uuid(), [$ticket->id]);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'status' => Ticket::STATUS_UNAVAILABLE,
        ]);
    }

    public function test_the_purchase_flow_owns_inventory_without_catalog_tables(): void
    {
        config(['booking.catalog_status_dual_write' => false]);

        $ticket = $this->createTicket();
        $userId = (string) Str::uuid();

        // Ownership proof: reserve + confirm succeed with the catalog tickets
        // table gone entirely (events stays for the documented name/date
        // residual).
        DB::table('tickets')->delete();

        $reservation = app(ReserveSeatsAction::class)->execute($userId, [$ticket->id]);
        $this->confirm($reservation->id, $userId)->assertCreated();

        $this->assertDatabaseHas('seat_inventory', [
            'ticket_id' => $ticket->id,
            'status' => SeatInventory::STATUS_BOOKED,
        ]);
        $this->assertDatabaseHas('bookings', [
            'ticket_id' => $ticket->id,
            'seat' => 'A01',
        ]);
    }

    public function test_it_releases_inventory_when_a_refund_completes(): void
    {
        $ticket = $this->createTicket();
        $userId = (string) Str::uuid();
        $reservation = app(ReserveSeatsAction::class)->execute($userId, [$ticket->id]);
        $this->confirm($reservation->id, $userId)->assertCreated();

        DB::table('bookings')->update(['status' => 'refund_pending']);

        app(ReconcileRefundAction::class)->execute('ch_fake_1', 5000, 5000);

        $this->assertDatabaseHas('seat_inventory', [
            'ticket_id' => $ticket->id,
            'status' => SeatInventory::STATUS_AVAILABLE,
        ]);
        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'status' => Ticket::STATUS_AVAILABLE,
        ]);
    }

    public function test_it_reports_drift_when_the_catalog_mirror_diverges(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_AVAILABLE);

        DB::table('tickets')->where('id', $ticket->id)->update(['status' => Ticket::STATUS_BOOKED]);

        $this->artisan('booking:verify-inventory', ['--strict' => true])->assertExitCode(1);

        DB::table('tickets')->where('id', $ticket->id)->update(['status' => Ticket::STATUS_AVAILABLE]);

        $this->artisan('booking:verify-inventory', ['--strict' => true])->assertExitCode(0);
    }

    public function test_the_seed_command_inserts_missing_and_enriches_incomplete_rows(): void
    {
        // A catalog ticket with no inventory row (pre-cutover history).
        $orphan = new Ticket;
        $orphan->event_id = (string) Str::uuid();
        $orphan->seat = 'S01';
        $orphan->price = '35.00';
        $orphan->type = 'standard';
        $orphan->status = Ticket::STATUS_BOOKED;
        $orphan->save();

        // A row from the old shadow projector: status only, no identity.
        $bare = new Ticket;
        $bare->event_id = (string) Str::uuid();
        $bare->seat = 'S02';
        $bare->price = '45.00';
        $bare->type = 'standard';
        $bare->status = Ticket::STATUS_AVAILABLE;
        $bare->save();
        DB::table('seat_inventory')->insert([
            'ticket_id' => $bare->id,
            'event_id' => null,
            'status' => SeatInventory::STATUS_AVAILABLE,
            'reservation_id' => null,
            'updated_at' => now(),
        ]);

        $this->artisan('booking:seed-inventory')->assertExitCode(0);

        $this->assertDatabaseHas('seat_inventory', [
            'ticket_id' => $orphan->id,
            'status' => SeatInventory::STATUS_BOOKED,
            'seat' => 'S01',
            'source' => 'seed',
        ]);
        $this->assertDatabaseHas('seat_inventory', [
            'ticket_id' => $bare->id,
            'seat' => 'S02',
            'event_id' => $bare->event_id,
        ]);

        // Rerun safety: nothing changes.
        $this->artisan('booking:seed-inventory')->assertExitCode(0);
        $this->assertSame(2, DB::table('seat_inventory')->count());
    }

    public function test_the_availability_endpoint_serves_per_ticket_status_and_zone_aggregates(): void
    {
        $ticket = $this->createTicket();
        $eventId = (string) Str::uuid();
        $this->assignTicketToEvent($ticket->id, $eventId);
        DB::table('seat_inventory')->where('ticket_id', $ticket->id)->update(['price' => '30.00']);

        $held = $this->createTicket(Ticket::STATUS_UNAVAILABLE, 'A02');
        $this->assignTicketToEvent($held->id, $eventId);

        $response = $this->getJson('/booking/availability/'.$eventId)->assertOk();

        $this->assertSame('available', $response->json('data.tickets.'.$ticket->id));
        $this->assertSame('unavailable', $response->json('data.tickets.'.$held->id));
        $this->assertSame(1, $response->json('data.zones.0.available'));
        $this->assertSame('30.00', $response->json('data.zones.0.from_price'));
    }
}
