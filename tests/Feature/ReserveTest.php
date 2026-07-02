<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Ticket;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ReserveTest extends BookingTestCase
{
    public function test_it_rejects_reserve_without_a_queue_token(): void
    {
        $ticket = $this->createTicket();

        $this->postJson('/reserve', [
            'user_id' => (string) Str::uuid(),
            'tickets' => [$ticket->id],
        ])->assertStatus(403);

        $this->assertSame(Ticket::STATUS_AVAILABLE, $ticket->refresh()->status);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_it_rejects_a_tampered_queue_token(): void
    {
        $ticket = $this->createTicket();

        $this->postJson('/reserve', [
            'user_id' => (string) Str::uuid(),
            'tickets' => [$ticket->id],
        ], ['X-Queue-Token' => $this->queueToken().'tampered'])->assertStatus(403);
    }

    public function test_it_rejects_an_expired_queue_token(): void
    {
        $ticket = $this->createTicket();

        $this->postJson('/reserve', [
            'user_id' => (string) Str::uuid(),
            'tickets' => [$ticket->id],
        ], ['X-Queue-Token' => $this->queueToken(time() - 10)])->assertStatus(403);
    }

    public function test_it_reserves_available_seats_with_a_valid_token(): void
    {
        $ticket = $this->createTicket();
        $userId = (string) Str::uuid();

        $response = $this->postJson('/reserve', [
            'user_id' => $userId,
            'tickets' => [$ticket->id],
        ], ['X-Queue-Token' => $this->queueToken()]);

        $response->assertCreated()
            ->assertJsonPath('status', Reservation::STATUS_HELD)
            ->assertJsonStructure(['reservation_id', 'status', 'expires_at']);

        $this->assertSame(Ticket::STATUS_UNAVAILABLE, $ticket->refresh()->status);
        $this->assertDatabaseHas('reservations', [
            'user_id' => $userId,
            'status' => Reservation::STATUS_HELD,
        ]);
    }

    public function test_it_returns_409_when_a_seat_is_already_unavailable(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);

        $this->postJson('/reserve', [
            'user_id' => (string) Str::uuid(),
            'tickets' => [$ticket->id],
        ], ['X-Queue-Token' => $this->queueToken()])->assertStatus(409);

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_it_returns_409_when_the_seat_lock_is_held_by_another_request(): void
    {
        $ticket = $this->createTicket();

        // Another in-flight request owns the per-seat lock: this one must bounce
        // without touching the seat.
        $lock = Cache::store('redis')->lock("seat:{$ticket->id}", 15);
        $this->assertTrue($lock->get());

        try {
            $this->postJson('/reserve', [
                'user_id' => (string) Str::uuid(),
                'tickets' => [$ticket->id],
            ], ['X-Queue-Token' => $this->queueToken()])->assertStatus(409);

            $this->assertSame(Ticket::STATUS_AVAILABLE, $ticket->refresh()->status);
            $this->assertDatabaseCount('reservations', 0);
        } finally {
            $lock->release();
        }
    }

    public function test_it_rejects_the_whole_reservation_when_one_seat_is_gone(): void
    {
        $available = $this->createTicket();
        $taken = $this->createTicket(Ticket::STATUS_UNAVAILABLE, 'A02');

        $this->postJson('/reserve', [
            'user_id' => (string) Str::uuid(),
            'tickets' => [$available->id, $taken->id],
        ], ['X-Queue-Token' => $this->queueToken()])->assertStatus(409);

        // Atomicity: the still-available seat must not be burned by the failure.
        $this->assertSame(Ticket::STATUS_AVAILABLE, $available->refresh()->status);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_it_releases_seat_locks_after_a_successful_reserve(): void
    {
        $ticket = $this->createTicket();

        $this->postJson('/reserve', [
            'user_id' => (string) Str::uuid(),
            'tickets' => [$ticket->id],
        ], ['X-Queue-Token' => $this->queueToken()])->assertCreated();

        $lock = Cache::store('redis')->lock("seat:{$ticket->id}", 1);
        $this->assertTrue($lock->get(), 'The per-seat lock must be released once the hold is committed');
        $lock->release();
    }
}
