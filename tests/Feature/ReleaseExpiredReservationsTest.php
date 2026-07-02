<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Ticket;
use Illuminate\Support\Str;

final class ReleaseExpiredReservationsTest extends BookingTestCase
{
    public function test_it_releases_seats_from_expired_held_reservations(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $reservation = $this->createHeldReservation((string) Str::uuid(), [$ticket->id], now()->subMinute());

        $this->artisan('booking:release-expired')->assertSuccessful();

        $this->assertSame(Ticket::STATUS_AVAILABLE, $ticket->refresh()->status);
        $this->assertSame(Reservation::STATUS_RELEASED, $reservation->refresh()->status);
    }

    public function test_it_leaves_active_holds_untouched(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $reservation = $this->createHeldReservation((string) Str::uuid(), [$ticket->id], now()->addMinutes(5));

        $this->artisan('booking:release-expired')->assertSuccessful();

        $this->assertSame(Ticket::STATUS_UNAVAILABLE, $ticket->refresh()->status);
        $this->assertSame(Reservation::STATUS_HELD, $reservation->refresh()->status);
    }

    public function test_it_never_releases_booked_seats(): void
    {
        // An expired reservation whose seat was meanwhile booked (e.g. through a
        // confirm that raced the sweeper): the reservation is closed out but the
        // sold seat must stay sold.
        $ticket = $this->createTicket(Ticket::STATUS_BOOKED);
        $reservation = $this->createHeldReservation((string) Str::uuid(), [$ticket->id], now()->subMinute());

        $this->artisan('booking:release-expired')->assertSuccessful();

        $this->assertSame(Ticket::STATUS_BOOKED, $ticket->refresh()->status);
        $this->assertSame(Reservation::STATUS_RELEASED, $reservation->refresh()->status);
    }

    public function test_it_is_idempotent_when_run_twice(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $reservation = $this->createHeldReservation((string) Str::uuid(), [$ticket->id], now()->subMinute());

        $this->artisan('booking:release-expired')->assertSuccessful();

        // Rebook the seat between runs: the second sweep must not release it
        // again because the reservation is no longer held.
        $ticket->refresh()->update(['status' => Ticket::STATUS_BOOKED]);

        $this->artisan('booking:release-expired')->assertSuccessful();

        $this->assertSame(Ticket::STATUS_BOOKED, $ticket->refresh()->status);
        $this->assertSame(Reservation::STATUS_RELEASED, $reservation->refresh()->status);
    }
}
