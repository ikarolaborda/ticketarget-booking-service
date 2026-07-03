<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Ticket;
use App\Services\TicketCodeIssuer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class RefundReconcileTest extends BookingTestCase
{
    private const string SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.webhook_secret' => self::SECRET]);

        if (! Schema::hasTable('events')) {
            Schema::create('events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->timestampTz('date')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_the_webhook_completes_a_pending_refund_and_releases_the_seat(): void
    {
        [$bookingId, $ticketId] = $this->booking('pi_rc_1', Booking::STATUS_REFUND_PENDING);

        $this->postRefundedEvent('pi_rc_1', 5000, 5000)->assertOk();

        $this->assertSame(Booking::STATUS_REFUNDED, Booking::query()->find($bookingId)->status);
        $this->assertSame(Ticket::STATUS_AVAILABLE, Ticket::query()->find($ticketId)->status);
    }

    public function test_webhook_replay_is_idempotent(): void
    {
        [$bookingId, $ticketId] = $this->booking('pi_rc_2', Booking::STATUS_REFUND_PENDING);

        $this->postRefundedEvent('pi_rc_2', 5000, 5000)->assertOk();

        // Simulate the seat being re-sold before the replay arrives.
        Ticket::query()->where('id', $ticketId)->update(['status' => Ticket::STATUS_BOOKED]);

        $this->postRefundedEvent('pi_rc_2', 5000, 5000)->assertOk();

        // Replay must NOT yank the re-sold seat back, and the row stays refunded.
        $this->assertSame(Ticket::STATUS_BOOKED, Ticket::query()->find($ticketId)->status);
        $this->assertSame(Booking::STATUS_REFUNDED, Booking::query()->find($bookingId)->status);
    }

    public function test_a_full_external_refund_completes_every_live_row_on_the_charge(): void
    {
        [$b1, $t1] = $this->booking('pi_rc_3', Booking::STATUS_PAID, 'C01');
        [$b2, $t2] = $this->booking('pi_rc_3', Booking::STATUS_PAID, 'C02');

        $this->postRefundedEvent('pi_rc_3', 10000, 10000)->assertOk();

        $this->assertSame(Booking::STATUS_REFUNDED, Booking::query()->find($b1)->status);
        $this->assertSame(Booking::STATUS_REFUNDED, Booking::query()->find($b2)->status);
        $this->assertSame(Ticket::STATUS_AVAILABLE, Ticket::query()->find($t1)->status);
        $this->assertSame(Ticket::STATUS_AVAILABLE, Ticket::query()->find($t2)->status);
    }

    public function test_a_partial_external_refund_with_nothing_pending_touches_nothing(): void
    {
        [$bookingId, $ticketId] = $this->booking('pi_rc_4', Booking::STATUS_PAID);

        $this->postRefundedEvent('pi_rc_4', 2500, 5000)->assertOk();

        // Ambiguous on multi-seat charges: flagged for a human, state untouched.
        $this->assertSame(Booking::STATUS_PAID, Booking::query()->find($bookingId)->status);
        $this->assertSame(Ticket::STATUS_BOOKED, Ticket::query()->find($ticketId)->status);
    }

    public function test_pending_rows_complete_but_paid_siblings_on_the_same_charge_survive_a_partial(): void
    {
        [$pending] = $this->booking('pi_rc_5', Booking::STATUS_REFUND_PENDING, 'D01');
        [$paid, $paidTicket] = $this->booking('pi_rc_5', Booking::STATUS_PAID, 'D02');

        $this->postRefundedEvent('pi_rc_5', 5000, 10000)->assertOk();

        $this->assertSame(Booking::STATUS_REFUNDED, Booking::query()->find($pending)->status);
        $this->assertSame(Booking::STATUS_PAID, Booking::query()->find($paid)->status);
        $this->assertSame(Ticket::STATUS_BOOKED, Ticket::query()->find($paidTicket)->status);
    }

    public function test_a_refunded_ticket_no_longer_verifies(): void
    {
        [$bookingId] = $this->booking('pi_rc_6', Booking::STATUS_REFUNDED);
        $code = app(TicketCodeIssuer::class)->issue($bookingId);

        $this->getJson('/booking/verify?code='.urlencode($code))
            ->assertOk()
            ->assertExactJson(['valid' => false]);
    }

    /**
     * @return array{0: string, 1: string} booking id, ticket id
     */
    private function booking(string $chargeId, string $status, string $seat = 'B01'): array
    {
        $ticket = $this->createTicket(Ticket::STATUS_BOOKED, $seat.'-'.Str::random(4));

        $id = (string) Str::uuid();
        DB::table('bookings')->insert([
            'id' => $id, 'reservation_id' => (string) Str::uuid(), 'ticket_id' => $ticket->id,
            'user_id' => (string) Str::uuid(), 'email' => 'w@example.com', 'status' => $status,
            'charge_id' => $chargeId, 'amount' => '50.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$id, $ticket->id];
    }

    private function postRefundedEvent(string $paymentIntent, int $amountRefunded, int $amount)
    {
        $payload = json_encode([
            'id' => 'evt_'.Str::random(8),
            'object' => 'event',
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'object' => 'charge',
                'payment_intent' => $paymentIntent,
                'amount' => $amount,
                'amount_refunded' => $amountRefunded,
            ]],
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET);

        return $this->call('POST', '/booking/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }
}
