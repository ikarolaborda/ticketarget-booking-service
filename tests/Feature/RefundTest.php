<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payment\PaymentGateway;
use App\Models\Booking;
use App\Models\Ticket;
use App\Services\TicketCodeIssuer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\FakePaymentGateway;

final class RefundTest extends BookingTestCase
{
    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        if (! Schema::hasTable('events')) {
            Schema::create('events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->timestampTz('date')->nullable();
                $table->timestamps();
            });
        }

        // Frozen clock: tier boundaries are money-impacting.
        Carbon::setTestNow('2026-07-01 12:00:00');
        CarbonImmutable::setTestNow('2026-07-01 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_full_refund_exactly_seven_days_before_the_event(): void
    {
        [$bookingId, $userId] = $this->paidBooking(eventAt: '2026-07-08 12:00:00', amount: '100.00');

        $this->postJson('/booking/'.$bookingId.'/refund', [], $this->bearer($userId))
            ->assertOk()
            ->assertJson(['status' => 'refund_pending', 'refund_tier' => 100, 'amount_cents' => 10000]);

        $this->assertSame(Booking::STATUS_REFUND_PENDING, Booking::query()->find($bookingId)->status);
        $this->assertSame(10000, $this->gateway->refunds[0]['amount']);
        $this->assertSame('refund:'.$bookingId, $this->gateway->refunds[0]['idempotency_key']);
    }

    public function test_a_half_refund_exactly_forty_eight_hours_before(): void
    {
        [$bookingId, $userId] = $this->paidBooking(eventAt: '2026-07-03 12:00:00', amount: '100.00');

        $this->postJson('/booking/'.$bookingId.'/refund', [], $this->bearer($userId))
            ->assertOk()
            ->assertJson(['refund_tier' => 50, 'amount_cents' => 5000]);
    }

    public function test_refunds_are_refused_inside_forty_eight_hours_and_after_the_event(): void
    {
        [$closeId, $closeUser] = $this->paidBooking(eventAt: '2026-07-03 11:59:00');
        [$pastId, $pastUser] = $this->paidBooking(eventAt: '2026-06-30 12:00:00', seat: 'B02');

        $this->postJson('/booking/'.$closeId.'/refund', [], $this->bearer($closeUser))->assertStatus(422);
        $this->postJson('/booking/'.$pastId.'/refund', [], $this->bearer($pastUser))->assertStatus(422);

        $this->assertSame([], $this->gateway->refunds);
        $this->assertSame(Booking::STATUS_PAID, Booking::query()->find($closeId)->status);
    }

    public function test_only_the_owner_or_a_valid_entry_code_may_refund(): void
    {
        [$bookingId] = $this->paidBooking(eventAt: '2026-07-20 12:00:00');

        // Stranger with a valid token for a DIFFERENT user: uniform 403.
        $this->postJson('/booking/'.$bookingId.'/refund', [], $this->bearer((string) Str::uuid()))
            ->assertStatus(403);
        // Unknown booking id: same 403, no existence leak.
        $this->postJson('/booking/'.Str::uuid().'/refund', [], $this->bearer((string) Str::uuid()))
            ->assertStatus(403);

        // Guest with the ticket's signed entry code succeeds.
        $code = app(TicketCodeIssuer::class)->issue($bookingId);
        $this->postJson('/booking/'.$bookingId.'/refund', ['code' => $code])->assertOk();
    }

    public function test_a_second_refund_request_conflicts_and_charges_stripe_once(): void
    {
        [$bookingId, $userId] = $this->paidBooking(eventAt: '2026-07-20 12:00:00');

        $this->postJson('/booking/'.$bookingId.'/refund', [], $this->bearer($userId))->assertOk();
        $this->postJson('/booking/'.$bookingId.'/refund', [], $this->bearer($userId))->assertStatus(409);

        $this->assertCount(1, $this->gateway->refunds);
    }

    public function test_a_gateway_failure_reverts_to_paid_and_returns_402(): void
    {
        [$bookingId, $userId] = $this->paidBooking(eventAt: '2026-07-20 12:00:00');
        $this->gateway->failRefund = true;

        $this->postJson('/booking/'.$bookingId.'/refund', [], $this->bearer($userId))->assertStatus(402);

        $this->assertSame(Booking::STATUS_PAID, Booking::query()->find($bookingId)->status);
    }

    public function test_a_refunded_seat_can_be_resold_but_live_duplicates_still_throw(): void
    {
        [$bookingId] = $this->paidBooking(eventAt: '2026-07-20 12:00:00');
        $booking = Booking::query()->find($bookingId);

        // Live duplicate: the partial unique index must still protect the seat.
        try {
            $this->insertBooking($booking->ticket_id, (string) Str::uuid());
            $this->fail('Expected unique violation for a live booking duplicate');
        } catch (UniqueConstraintViolationException) {
        }

        $booking->status = Booking::STATUS_REFUNDED;
        $booking->save();

        // Refunded seat: resale insert now succeeds.
        $this->insertBooking($booking->ticket_id, (string) Str::uuid());
        $this->assertSame(2, Booking::query()->where('ticket_id', $booking->ticket_id)->count());
    }

    /**
     * @return array{0: string, 1: string} booking id, user id
     */
    private function paidBooking(string $eventAt, string $amount = '50.00', string $seat = 'A01'): array
    {
        $eventId = (string) Str::uuid();
        DB::table('events')->insert([
            'id' => $eventId, 'name' => 'Refund Show', 'date' => $eventAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $ticket = $this->createTicket(Ticket::STATUS_BOOKED, $seat.'-'.substr($eventId, 0, 4));
        $this->assignTicketToEvent($ticket->id, $eventId);

        $userId = (string) Str::uuid();
        $bookingId = $this->insertBooking($ticket->id, $userId, $amount);

        return [$bookingId, $userId];
    }

    private function insertBooking(string $ticketId, string $userId, string $amount = '50.00'): string
    {
        $id = (string) Str::uuid();
        DB::table('bookings')->insert([
            'id' => $id, 'reservation_id' => (string) Str::uuid(), 'ticket_id' => $ticketId,
            'user_id' => $userId, 'email' => 'r@example.com', 'status' => Booking::STATUS_PAID,
            'charge_id' => 'pi_refund_test', 'amount' => $amount,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function bearer(string $userId): array
    {
        return ['Authorization' => 'Bearer '.$this->authToken($userId, 'r@example.com')];
    }
}
