<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Ticket;
use App\Services\TicketCodeIssuer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class TicketVerifyTest extends BookingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('events')) {
            Schema::create('events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->timestampTz('date')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_a_genuine_code_verifies_with_the_minimal_scanner_payload(): void
    {
        $bookingId = $this->bookedTicket('Big Show', 'A05');
        $code = app(TicketCodeIssuer::class)->issue($bookingId);

        $this->getJson('/booking/verify?code='.urlencode($code))
            ->assertOk()
            ->assertJson(['valid' => true, 'seat' => 'A05', 'event_name' => 'Big Show'])
            ->assertJsonMissingPath('email')
            ->assertJsonMissingPath('amount');
    }

    public function test_forged_malformed_and_unknown_codes_all_fail_identically(): void
    {
        $bookingId = $this->bookedTicket('Big Show', 'A06');
        $genuine = app(TicketCodeIssuer::class)->issue($bookingId);

        foreach ([
            $genuine.'x',
            'v1.not-base64.sig',
            'v2.'.explode('.', $genuine)[1].'.'.explode('.', $genuine)[2],
            'garbage',
            '',
            app(TicketCodeIssuer::class)->issue((string) Str::uuid()),
        ] as $bad) {
            $this->getJson('/booking/verify?code='.urlencode($bad))
                ->assertOk()
                ->assertExactJson(['valid' => false]);
        }
    }

    public function test_my_bookings_rows_carry_a_verifiable_ticket_code(): void
    {
        $userId = (string) Str::uuid();
        $this->bookedTicket('Big Show', 'A07', $userId);

        $response = $this->getJson('/booking/mine', [
            'Authorization' => 'Bearer '.$this->authToken($userId, 'qr@example.com'),
        ]);

        $code = $response->assertOk()->json('tickets.0.ticket_code');
        $this->assertNotNull(app(TicketCodeIssuer::class)->verify($code));
    }

    private function bookedTicket(string $eventName, string $seat, ?string $userId = null): string
    {
        $eventId = (string) Str::uuid();
        DB::table('events')->insert([
            'id' => $eventId, 'name' => $eventName, 'date' => now()->addMonth(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $ticket = $this->createTicket(Ticket::STATUS_BOOKED, $seat);
        $this->assignTicketToEvent($ticket->id, $eventId);

        $bookingId = (string) Str::uuid();
        DB::table('bookings')->insert([
            'id' => $bookingId, 'reservation_id' => (string) Str::uuid(),
            'ticket_id' => $ticket->id, 'user_id' => $userId ?? (string) Str::uuid(),
            'email' => 'qr@example.com', 'charge_id' => 'ch_qr', 'amount' => '50.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $bookingId;
    }
}
