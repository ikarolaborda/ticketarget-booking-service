<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Ticket;
use Illuminate\Support\Str;

final class ShowReservationTest extends BookingTestCase
{
    public function test_a_guest_can_rehydrate_their_held_reservation(): void
    {
        $userId = (string) Str::uuid();
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE, 'B02', '75.50');
        $reservation = $this->createHeldReservation($userId, [$ticket->id]);

        $response = $this->getJson('/booking/reservation/'.$reservation->id.'?user_id='.$userId);

        $response->assertOk()
            ->assertJsonPath('reservation_id', $reservation->id)
            ->assertJsonPath('status', 'held')
            ->assertJsonPath('event_id', $ticket->event_id)
            ->assertJsonPath('tickets.0.seat', 'B02')
            ->assertJsonPath('tickets.0.price', '75.50');

        $this->assertNotNull($response->json('expires_at'));
    }

    public function test_a_bearer_token_identifies_the_owner_and_overrides_the_query_param(): void
    {
        $owner = (string) Str::uuid();
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $reservation = $this->createHeldReservation($owner, [$ticket->id]);

        $this->getJson(
            '/booking/reservation/'.$reservation->id.'?user_id='.Str::uuid(),
            ['Authorization' => 'Bearer '.$this->authToken($owner, 'owner@example.com')],
        )->assertOk()->assertJsonPath('reservation_id', $reservation->id);
    }

    public function test_a_foreign_owner_and_an_unknown_id_are_indistinguishable(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $reservation = $this->createHeldReservation((string) Str::uuid(), [$ticket->id]);

        $foreign = $this->getJson('/booking/reservation/'.$reservation->id.'?user_id='.Str::uuid());
        $unknown = $this->getJson('/booking/reservation/'.Str::uuid().'?user_id='.Str::uuid());

        $foreign->assertStatus(404);
        $unknown->assertStatus(404);
        $this->assertSame($unknown->getContent(), $foreign->getContent());
    }

    public function test_a_guest_without_a_user_id_is_rejected(): void
    {
        $this->getJson('/booking/reservation/'.Str::uuid())->assertStatus(422);
    }

    public function test_an_expired_hold_is_reported_as_released_before_the_sweeper_runs(): void
    {
        $userId = (string) Str::uuid();
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $reservation = $this->createHeldReservation($userId, [$ticket->id], now()->subMinute());

        $this->getJson('/booking/reservation/'.$reservation->id.'?user_id='.$userId)
            ->assertOk()
            ->assertJsonPath('status', 'released');

        // The read path must not mutate the row; releasing is the sweeper's job.
        $this->assertSame(Reservation::STATUS_HELD, $reservation->fresh()->status);
    }

    public function test_a_confirmed_reservation_reports_confirmed(): void
    {
        $userId = (string) Str::uuid();
        $ticket = $this->createTicket(Ticket::STATUS_BOOKED);
        $reservation = $this->createHeldReservation($userId, [$ticket->id]);
        $reservation->status = Reservation::STATUS_CONFIRMED;
        $reservation->save();

        $this->getJson('/booking/reservation/'.$reservation->id.'?user_id='.$userId)
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');
    }
}
