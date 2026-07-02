<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payment\PaymentGateway;
use App\Mail\TicketsConfirmationMail;
use App\Models\Reservation;
use App\Models\Ticket;
use Illuminate\Support\Facades\Mail;
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

        Mail::fake();
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
            'email' => 'guest@example.com',
        ])->assertCreated()->assertJsonPath('status', Reservation::STATUS_CONFIRMED);

        $this->assertDatabaseHas('bookings', ['email' => 'guest@example.com']);
        Mail::assertSent(TicketsConfirmationMail::class, 'guest@example.com');

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
            'email' => 'guest@example.com',
        ])->assertStatus(422);

        Mail::assertNothingSent();

        // The charge happens before the transaction, so the compensation path
        // must give the money back — exactly once, for exactly that charge.
        $this->assertCount(1, $this->gateway->charges);
        $this->assertCount(1, $this->gateway->refunds);
        $this->assertSame('ch_fake_1', $this->gateway->refunds[0]['charge_id']);

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
            'email' => 'guest@example.com',
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
            'email' => 'guest@example.com',
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
            'email' => 'guest@example.com',
        ])->assertStatus(422);

        $this->assertSame([], $this->gateway->charges);
    }

    public function test_a_guest_booking_without_an_email_is_rejected(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$ticket->id]);

        $this->postJson('/booking', [
            'reservation_id' => $reservation->id,
            'user_id' => $userId,
            'payment_token' => 'pm_card_visa',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->assertSame([], $this->gateway->charges);
    }

    public function test_an_authenticated_booking_uses_the_token_identity(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$ticket->id]);

        // No body user_id/email: both must come from the verified token.
        $this->postJson('/booking', [
            'reservation_id' => $reservation->id,
            'payment_token' => 'pm_card_visa',
        ], ['Authorization' => 'Bearer '.$this->authToken($userId, 'Account@Example.com')])
            ->assertCreated();

        $this->assertDatabaseHas('bookings', ['email' => 'account@example.com', 'user_id' => $userId]);
        Mail::assertSent(TicketsConfirmationMail::class, 'account@example.com');
    }

    public function test_an_invalid_bearer_token_is_rejected_not_downgraded_to_guest(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$ticket->id]);

        $this->postJson('/booking', [
            'reservation_id' => $reservation->id,
            'user_id' => $userId,
            'payment_token' => 'pm_card_visa',
            'email' => 'guest@example.com',
        ], ['Authorization' => 'Bearer '.$this->authToken($userId, 'a@b.c', -10)])
            ->assertStatus(401);

        $this->assertSame([], $this->gateway->charges);
    }
}
