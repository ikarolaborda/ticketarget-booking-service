<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payment\PaymentGateway;
use App\Models\Reservation;
use App\Models\Ticket;
use Illuminate\Support\Str;
use Tests\Support\FakePaymentGateway;

final class BookingConfirmTest extends BookingTestCase
{
    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway();
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    public function test_it_confirms_a_held_reservation_and_books_the_seats(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$ticket->id]);

        $this->postJson('/booking', [
            'reservation_id' => $reservation->id,
            'user_id' => $userId,
            'payment_token' => 'pm_card_visa',
        ])->assertCreated()->assertJsonPath('status', Reservation::STATUS_CONFIRMED);

        $this->assertSame(Ticket::STATUS_BOOKED, $ticket->refresh()->status);
        $this->assertDatabaseHas('bookings', [
            'reservation_id' => $reservation->id,
            'ticket_id' => $ticket->id,
            'user_id' => $userId,
            'charge_id' => 'ch_fake_1',
        ]);

        $this->assertCount(1, $this->gateway->charges);
        $this->assertSame(5000, $this->gateway->charges[0]['amount']);
        $this->assertSame($reservation->id, $this->gateway->charges[0]['idempotency_key']);
        $this->assertSame([], $this->gateway->refunds);
    }

    public function test_it_refunds_the_charge_when_held_seats_changed_before_commit(): void
    {
        $kept = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        // This seat slipped out of the hold (e.g. the sweeper released it and
        // someone else bought it) after the reservation was created.
        $stolen = $this->createTicket(Ticket::STATUS_BOOKED, 'A02');
        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$kept->id, $stolen->id]);

        $this->postJson('/booking', [
            'reservation_id' => $reservation->id,
            'user_id' => $userId,
            'payment_token' => 'pm_card_visa',
        ])->assertStatus(422);

        // The charge happens before the transaction, so the compensation path
        // must give the money back — exactly once, for exactly that charge.
        $this->assertCount(1, $this->gateway->charges);
        $this->assertSame(['ch_fake_1'], $this->gateway->refunds);

        // Everything inside the transaction must have rolled back.
        $this->assertDatabaseCount('bookings', 0);
        $this->assertSame(Reservation::STATUS_HELD, $reservation->refresh()->status);
        $this->assertSame(Ticket::STATUS_UNAVAILABLE, $kept->refresh()->status);
    }

    public function test_it_does_not_refund_when_the_charge_itself_fails(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$ticket->id]);

        $this->gateway->failCharge = true;

        $this->postJson('/booking', [
            'reservation_id' => $reservation->id,
            'user_id' => $userId,
            'payment_token' => 'pm_card_visa',
        ])->assertStatus(402);

        // Negative control: a failed charge has nothing to compensate.
        $this->assertSame([], $this->gateway->refunds);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertSame(Reservation::STATUS_HELD, $reservation->refresh()->status);
        $this->assertSame(Ticket::STATUS_UNAVAILABLE, $ticket->refresh()->status);
    }

    public function test_it_rejects_an_expired_reservation_without_charging(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$ticket->id], now()->subMinute());

        $this->postJson('/booking', [
            'reservation_id' => $reservation->id,
            'user_id' => $userId,
            'payment_token' => 'pm_card_visa',
        ])->assertStatus(422);

        $this->assertSame([], $this->gateway->charges);
    }

    public function test_it_rejects_another_users_reservation_without_charging(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $reservation = $this->createHeldReservation((string) Str::uuid(), [$ticket->id]);

        $this->postJson('/booking', [
            'reservation_id' => $reservation->id,
            'user_id' => (string) Str::uuid(),
            'payment_token' => 'pm_card_visa',
        ])->assertStatus(422);

        $this->assertSame([], $this->gateway->charges);
    }
}
