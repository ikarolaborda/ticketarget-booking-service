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

    public function test_it_mirrors_reserve_confirm_and_release_into_seat_inventory(): void
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
    }

    public function test_it_writes_nothing_when_dual_write_is_disabled(): void
    {
        config(['booking.inventory_dual_write' => false]);

        $ticket = $this->createTicket();

        app(ReserveSeatsAction::class)->execute((string) Str::uuid(), [$ticket->id]);

        $this->assertSame(0, DB::table('seat_inventory')->count());
    }

    public function test_it_reports_drift_between_inventory_and_tickets(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_AVAILABLE);

        DB::table('seat_inventory')->insert([
            'ticket_id' => $ticket->id,
            'event_id' => $ticket->event_id,
            'status' => SeatInventory::STATUS_BOOKED,
            'reservation_id' => null,
            'updated_at' => now(),
        ]);

        $this->artisan('booking:verify-inventory', ['--strict' => true])->assertExitCode(1);

        DB::table('seat_inventory')->update(['status' => SeatInventory::STATUS_AVAILABLE]);

        $this->artisan('booking:verify-inventory', ['--strict' => true])->assertExitCode(0);
    }
}
