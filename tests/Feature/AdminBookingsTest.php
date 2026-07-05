<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdminBookingsTest extends BookingTestCase
{
    public function test_the_feed_requires_an_admin_token(): void
    {
        $this->getJson('/booking/admin/bookings')->assertStatus(401);

        $this->getJson('/booking/admin/bookings', $this->headers(isAdmin: false))->assertStatus(403);
    }

    public function test_the_feed_is_newest_first_with_joined_context(): void
    {
        $ticket = $this->createTicket();
        $old = $this->seedBooking('10.00', Carbon::now('UTC')->subMinutes(5), $ticket->id);
        $new = $this->seedBooking('20.00', Carbon::now('UTC'));

        $data = $this->getJson('/booking/admin/bookings', $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertSame([$new->id, $old->id], array_column($data, 'id'));
        $this->assertSame('20.00', $data[0]['amount']);
        $this->assertSame('A01', $data[1]['seat']);
        $this->assertSame('buyer@example.com', $data[0]['email']);
    }

    public function test_cursor_pagination_returns_every_row_exactly_once(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->seedBooking('10.00', Carbon::now('UTC')->subMinutes($i))->id;
        }

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $url = '/booking/admin/bookings?limit=2'.($cursor !== null ? '&cursor='.$cursor : '');
            $body = $this->getJson($url, $this->headers())->assertOk()->json();
            $seen = array_merge($seen, array_column($body['data'], 'id'));
            $cursor = $body['next_cursor'];
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame(5, count($seen));
        $this->assertSame(count($seen), count(array_unique($seen)));
        $this->assertEqualsCanonicalizing($ids, $seen);
    }

    public function test_a_malformed_cursor_is_ignored(): void
    {
        $this->seedBooking('10.00', Carbon::now('UTC'));

        $this->getJson('/booking/admin/bookings?cursor=%%%not-base64%%%', $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * @return array<string, string>
     */
    private function headers(bool $isAdmin = true): array
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

        return ['Authorization' => 'Bearer '.$header.'.'.$payload.'.'.$signature];
    }

    private function seedBooking(string $amount, Carbon $createdAt, ?string $ticketId = null): Booking
    {
        $booking = new Booking;
        $booking->reservation_id = (string) Str::uuid();
        $booking->ticket_id = $ticketId ?? (string) Str::uuid();
        $booking->user_id = (string) Str::uuid();
        $booking->email = 'buyer@example.com';
        $booking->charge_id = 'pi_'.Str::random(10);
        $booking->amount = $amount;
        $booking->status = Booking::STATUS_PAID;
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
}
