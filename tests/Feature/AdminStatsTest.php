<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Ticket;
use App\Services\CapacityLedgerProjector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdminStatsTest extends BookingTestCase
{
    public function test_the_stats_endpoint_requires_an_admin_token(): void
    {
        $this->getJson('/booking/admin/stats')->assertStatus(401);

        $this->getJson('/booking/admin/stats', ['Authorization' => 'Bearer garbage'])
            ->assertStatus(401);

        $this->getJson('/booking/admin/stats', $this->adminHeaders(isAdmin: false))
            ->assertStatus(403);
    }

    public function test_totals_follow_the_locked_revenue_semantics(): void
    {
        $this->seedBooking(amount: '100.00', status: Booking::STATUS_PAID, createdAt: Carbon::now('UTC'));
        $this->seedBooking(amount: '50.00', status: Booking::STATUS_PAID, createdAt: Carbon::now('UTC'));
        $this->seedBooking(amount: '25.00', status: Booking::STATUS_REFUND_PENDING, createdAt: Carbon::now('UTC')->subDays(3));
        $this->seedBooking(amount: '70.00', status: Booking::STATUS_REFUNDED, createdAt: Carbon::now('UTC'));

        $this->createHeldReservation((string) Str::uuid(), [(string) Str::uuid()], now()->addMinutes(5));
        $this->createHeldReservation((string) Str::uuid(), [(string) Str::uuid()], now()->subMinute());

        $response = $this->getJson('/booking/admin/stats', $this->adminHeaders())->assertOk();

        $response->assertJsonPath('totals.revenue_recognized', '175.00')
            ->assertJsonPath('totals.paid_amount', '150.00')
            ->assertJsonPath('totals.revenue_today', '150.00')
            ->assertJsonPath('totals.revenue_7d', '175.00')
            ->assertJsonPath('totals.tickets_sold', 3)
            ->assertJsonPath('totals.sold_today', 2)
            ->assertJsonPath('totals.refunded_amount', '70.00')
            ->assertJsonPath('totals.refunds_count', 1)
            ->assertJsonPath('totals.active_holds', 1)
            ->assertJsonPath('status_breakdown.paid', 2)
            ->assertJsonPath('status_breakdown.refund_pending', 1)
            ->assertJsonPath('status_breakdown.refunded', 1);
    }

    public function test_revenue_by_day_is_zero_filled_ascending_over_fourteen_utc_days(): void
    {
        $this->seedBooking(amount: '40.00', status: Booking::STATUS_PAID, createdAt: Carbon::now('UTC'));
        $this->seedBooking(amount: '25.00', status: Booking::STATUS_REFUND_PENDING, createdAt: Carbon::now('UTC')->subDays(3));

        $series = $this->getJson('/booking/admin/stats', $this->adminHeaders())
            ->assertOk()
            ->json('revenue_by_day');

        $this->assertCount(14, $series);
        $this->assertSame(Carbon::now('UTC')->subDays(13)->toDateString(), $series[0]['date']);
        $this->assertSame(Carbon::now('UTC')->toDateString(), $series[13]['date']);
        $this->assertSame('40.00', $series[13]['revenue']);
        $this->assertSame('25.00', $series[10]['revenue']);
        $this->assertSame('0.00', $series[0]['revenue']);
        $this->assertSame(0, $series[0]['bookings']);
    }

    public function test_top_events_report_sold_capacity_and_revenue_without_catalog_tables(): void
    {
        $eventId = $this->seedEvent('Big Gala');
        $soldTicket = $this->seedEventTicket($eventId, 'A01');

        $this->seedBooking(amount: '80.00', status: Booking::STATUS_PAID, createdAt: Carbon::now('UTC'), ticketId: $soldTicket);

        // Capacity comes from the ledger fed by catalog.events; two events
        // (a zone generation and a manual addition) sum to the total.
        app(CapacityLedgerProjector::class)->apply('ticket.generated:zone:'.Str::uuid(), $eventId, (string) Str::uuid(), 2);
        app(CapacityLedgerProjector::class)->apply('ticket.generated:manual:'.Str::uuid(), $eventId, null, 1);

        // Join-freedom proof: the stats endpoint must not touch catalog tables.
        DB::table('tickets')->delete();
        DB::table('events')->delete();

        $top = $this->getJson('/booking/admin/stats', $this->adminHeaders())
            ->assertOk()
            ->json('top_events');

        $this->assertCount(1, $top);
        $this->assertSame('Big Gala', $top[0]['name']);
        $this->assertSame(1, $top[0]['sold']);
        $this->assertSame(3, $top[0]['capacity']);
        $this->assertSame('80.00', $top[0]['revenue']);
    }

    public function test_top_events_capacity_is_null_when_the_ledger_has_no_rows(): void
    {
        $eventId = $this->seedEvent('Unseeded Fest');
        $soldTicket = $this->seedEventTicket($eventId, 'B01');

        $this->seedBooking(amount: '60.00', status: Booking::STATUS_PAID, createdAt: Carbon::now('UTC'), ticketId: $soldTicket);

        $top = $this->getJson('/booking/admin/stats', $this->adminHeaders())
            ->assertOk()
            ->json('top_events');

        $this->assertCount(1, $top);
        $this->assertNull($top[0]['capacity']);
    }

    public function test_the_capacity_ledger_deduplicates_replayed_events(): void
    {
        $eventId = (string) Str::uuid();
        $projector = app(CapacityLedgerProjector::class);

        $this->assertTrue($projector->apply('ticket.generated:zone:z1', $eventId, 'z1', 5));
        $this->assertFalse($projector->apply('ticket.generated:zone:z1', $eventId, 'z1', 5));

        $this->assertSame(5, (int) DB::table('catalog_capacity_ledger')->where('event_id', $eventId)->sum('count'));
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(bool $isAdmin = true): array
    {
        return ['Authorization' => 'Bearer '.$this->adminToken($isAdmin)];
    }

    private function adminToken(bool $isAdmin): string
    {
        $encode = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        $header = $encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $encode(json_encode([
            'iss' => config('auth_token.issuer'),
            'sub' => (string) Str::uuid(),
            'email' => 'admin@example.com',
            'name' => 'Admin',
            'is_admin' => $isAdmin,
            'iat' => time(),
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
        $signature = $encode(hash_hmac('sha256', $header.'.'.$payload, (string) config('auth_token.secret'), true));

        return $header.'.'.$payload.'.'.$signature;
    }

    private function seedBooking(string $amount, string $status, Carbon $createdAt, ?string $ticketId = null): Booking
    {
        $booking = new Booking;
        $booking->reservation_id = (string) Str::uuid();
        $booking->ticket_id = $ticketId ?? (string) Str::uuid();
        $booking->user_id = (string) Str::uuid();
        $booking->email = 'buyer@example.com';
        $booking->charge_id = 'pi_'.Str::random(10);
        $booking->amount = $amount;
        $booking->status = $status;
        $booking->created_at = $createdAt;
        $booking->updated_at = $createdAt;

        $catalog = DB::table('tickets')
            ->leftJoin('events', 'events.id', '=', 'tickets.event_id')
            ->where('tickets.id', $booking->ticket_id)
            ->first(['tickets.seat', 'tickets.type', 'tickets.event_id', 'events.name as event_name', 'events.date as event_date']);

        $booking->seat = $catalog->seat ?? null;
        $booking->ticket_type = $catalog->type ?? null;
        $booking->event_id = $catalog->event_id ?? null;
        $booking->event_name = $catalog->event_name ?? null;
        $booking->event_date = $catalog->event_date ?? null;

        $booking->save();

        return $booking;
    }

    private function seedEvent(string $name): string
    {
        $id = (string) Str::uuid();

        DB::table('events')->insert([
            'id' => $id,
            'name' => $name,
            'date' => now()->addMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedEventTicket(string $eventId, string $seat): string
    {
        $ticket = new Ticket;
        $ticket->event_id = $eventId;
        $ticket->seat = $seat;
        $ticket->price = '80.00';
        $ticket->type = 'standard';
        $ticket->status = Ticket::STATUS_BOOKED;
        $ticket->save();

        return $ticket->id;
    }
}
