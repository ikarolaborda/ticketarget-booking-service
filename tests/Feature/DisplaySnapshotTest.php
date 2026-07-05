<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ReserveSeatsAction;
use App\Domain\Payment\PaymentGateway;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\FakePaymentGateway;

/**
 * Proves the display/reporting reads are booking-local: after a purchase the
 * catalog tables can disappear entirely and the endpoints still serve the
 * purchase-time snapshots.
 */
final class DisplaySnapshotTest extends BookingTestCase
{
    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        Mail::fake();
    }

    public function test_my_bookings_serves_snapshots_without_catalog_tables(): void
    {
        $eventId = (string) Str::uuid();
        DB::table('events')->insert([
            'id' => $eventId,
            'name' => 'Snapshot Fest',
            'date' => now()->addDays(20),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE, 'C07');
        $this->assignTicketToEvent($ticket->id, $eventId);

        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$ticket->id]);
        $this->confirm($reservation->id, $userId)->assertCreated();

        DB::table('tickets')->delete();
        DB::table('events')->delete();

        $response = $this->getJson('/booking/mine', [
            'Authorization' => 'Bearer '.$this->authToken($userId, 'snap@example.com'),
        ])->assertOk();

        $this->assertSame('C07', $response->json('tickets.0.seat'));
        $this->assertSame('standard', $response->json('tickets.0.type'));
        $this->assertSame('Snapshot Fest', $response->json('tickets.0.event_name'));
        $this->assertNotNull($response->json('tickets.0.event_date'));
    }

    public function test_reservation_rehydrates_from_the_reserve_time_snapshot(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_AVAILABLE, 'D01', '75.00');
        $userId = (string) Str::uuid();

        $reservation = app(ReserveSeatsAction::class)->execute($userId, [$ticket->id]);

        DB::table('tickets')->delete();

        $this->getJson('/booking/reservation/'.$reservation->id.'?user_id='.$userId)
            ->assertOk()
            ->assertJsonPath('reservation_id', $reservation->id)
            ->assertJsonPath('tickets.0.seat', 'D01')
            ->assertJsonPath('tickets.0.price', '75.00')
            ->assertJsonPath('tickets.0.status', 'unavailable');
    }

    public function test_reservation_falls_back_to_live_tickets_for_legacy_rows(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE, 'E01');
        $userId = (string) Str::uuid();

        // createHeldReservation writes no seats snapshot — a legacy-shape row.
        $reservation = $this->createHeldReservation($userId, [$ticket->id]);

        $this->getJson('/booking/reservation/'.$reservation->id.'?user_id='.$userId)
            ->assertOk()
            ->assertJsonPath('tickets.0.seat', 'E01')
            ->assertJsonPath('tickets.0.status', Ticket::STATUS_UNAVAILABLE);
    }

    public function test_admin_bookings_feed_serves_snapshots_without_catalog_tables(): void
    {
        $eventId = (string) Str::uuid();
        DB::table('events')->insert([
            'id' => $eventId,
            'name' => 'Feed Fest',
            'date' => now()->addDays(20),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE, 'F09');
        $this->assignTicketToEvent($ticket->id, $eventId);

        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$ticket->id]);
        $this->confirm($reservation->id, $userId)->assertCreated();

        DB::table('tickets')->delete();
        DB::table('events')->delete();

        $response = $this->getJson('/booking/admin/bookings', [
            'Authorization' => 'Bearer '.$this->adminAuthToken(),
        ])->assertOk();

        $this->assertSame('F09', $response->json('data.0.seat'));
        $this->assertSame('Feed Fest', $response->json('data.0.event_name'));
    }

    private function adminAuthToken(): string
    {
        $encode = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        $header = $encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $encode(json_encode([
            'iss' => config('auth_token.issuer'),
            'sub' => (string) Str::uuid(),
            'email' => 'admin@example.com',
            'name' => 'Admin',
            'is_admin' => true,
            'iat' => time(),
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
        $signature = $encode(hash_hmac('sha256', $header.'.'.$payload, (string) config('auth_token.secret'), true));

        return $header.'.'.$payload.'.'.$signature;
    }
}
