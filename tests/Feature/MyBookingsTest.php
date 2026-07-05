<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Ticket;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class MyBookingsTest extends BookingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The events table is owned by the Event service (shared data plane).
        if (! Schema::hasTable('events')) {
            Schema::create('events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->timestampTz('date')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_it_rejects_anonymous_and_invalid_bearer_requests(): void
    {
        $this->getJson('/booking/mine')->assertStatus(401);

        $this->getJson('/booking/mine', [
            'Authorization' => 'Bearer '.$this->authToken((string) Str::uuid(), 'x@y.z', -10),
        ])->assertStatus(401);
    }

    public function test_it_returns_only_the_callers_tickets_newest_first(): void
    {
        $mine = (string) Str::uuid();
        $other = (string) Str::uuid();
        $eventId = (string) Str::uuid();

        DB::table('events')->insert([
            'id' => $eventId,
            'name' => 'Big Show',
            'date' => now()->addMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mineOld = $this->bookingRow($mine, $eventId, 'A01', '2026-01-01 10:00:00');
        $mineNew = $this->bookingRow($mine, $eventId, 'A02', '2026-02-01 10:00:00');
        $this->bookingRow($other, $eventId, 'B01', '2026-03-01 10:00:00');

        $response = $this->getJson('/booking/mine', [
            'Authorization' => 'Bearer '.$this->authToken($mine, 'mine@example.com'),
        ]);

        $response->assertOk()->assertJsonCount(2, 'tickets');

        $tickets = $response->json('tickets');
        $this->assertSame(['A02', 'A01'], array_column($tickets, 'seat'));
        $this->assertSame('Big Show', $tickets[0]['event_name']);
        $this->assertSame($mineNew, $tickets[0]['reservation_id']);
        $this->assertSame($mineOld, $tickets[1]['reservation_id']);
        $this->assertArrayHasKey('amount', $tickets[0]);
        $this->assertArrayHasKey('charge_id', $tickets[0]);
        $this->assertArrayHasKey('purchased_at', $tickets[0]);
    }

    public function test_it_tolerates_missing_joined_rows(): void
    {
        $mine = (string) Str::uuid();

        // Booking whose ticket row vanished (orphan): must not 500.
        $booking = new Booking;
        $booking->reservation_id = (string) Str::uuid();
        $booking->ticket_id = (string) Str::uuid();
        $booking->user_id = $mine;
        $booking->email = 'mine@example.com';
        $booking->charge_id = 'ch_x';
        $booking->amount = '10.00';
        $booking->save();

        $response = $this->getJson('/booking/mine', [
            'Authorization' => 'Bearer '.$this->authToken($mine, 'mine@example.com'),
        ]);

        $response->assertOk()->assertJsonCount(1, 'tickets');
        $this->assertNull($response->json('tickets.0.seat'));
        $this->assertNull($response->json('tickets.0.event_name'));
    }

    private function bookingRow(string $userId, string $eventId, string $seat, string $createdAt): string
    {
        $ticket = $this->createTicket(Ticket::STATUS_BOOKED, $seat);
        $ticket->timestamps = false;
        DB::table('tickets')->where('id', $ticket->id)->update(['event_id' => $eventId]);

        $event = DB::table('events')->where('id', $eventId)->first();

        $reservationId = (string) Str::uuid();
        DB::table('bookings')->insert([
            'id' => (string) Str::uuid(),
            'reservation_id' => $reservationId,
            'ticket_id' => $ticket->id,
            'user_id' => $userId,
            'email' => 'mine@example.com',
            'charge_id' => 'ch_'.$seat,
            'amount' => '50.00',
            'seat' => $seat,
            'ticket_type' => 'standard',
            'event_id' => $eventId,
            'event_name' => $event->name ?? null,
            'event_date' => $event->date ?? null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $reservationId;
    }
}
