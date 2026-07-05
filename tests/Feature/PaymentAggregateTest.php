<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ReconcileRefundAction;
use App\Domain\Payment\PaymentGateway;
use App\Models\Booking;
use App\Models\OutboxMessage;
use App\Models\Payment;
use App\Models\Ticket;
use App\Services\OutboxWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\FakePaymentGateway;

final class PaymentAggregateTest extends BookingTestCase
{
    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        Mail::fake();
    }

    public function test_it_creates_a_captured_payment_and_links_bookings(): void
    {
        $first = $this->createTicket(Ticket::STATUS_UNAVAILABLE, 'A01');
        $second = $this->createTicket(Ticket::STATUS_UNAVAILABLE, 'A02');
        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$first->id, $second->id]);

        $this->confirm($reservation->id, $userId)->assertCreated();

        $payment = Payment::query()->sole();
        $this->assertSame(Payment::STATUS_CAPTURED, $payment->status);
        $this->assertSame($reservation->id, $payment->reservation_id);
        $this->assertSame($reservation->id, $payment->idempotency_key);
        $this->assertSame('ch_fake_1', $payment->provider_payment_intent_id);
        $this->assertSame(10000, $payment->amountInCents());

        $bookings = Booking::query()->get();
        $this->assertCount(2, $bookings);

        foreach ($bookings as $booking) {
            $this->assertSame($payment->id, $booking->payment_id);
            $this->assertSame('ch_fake_1', $booking->charge_id);
        }

        $this->assertDatabaseHas('outbox_messages', ['event_key' => 'payment.captured:'.$payment->id]);
        $this->assertDatabaseHas('outbox_messages', ['event_key' => 'booking.confirmed:'.$reservation->id]);
    }

    public function test_it_snapshots_the_event_date_onto_bookings(): void
    {
        $eventId = (string) Str::uuid();
        DB::table('events')->insert([
            'id' => $eventId,
            'name' => 'Snapshot Show',
            'date' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        Ticket::query()->whereKey($ticket->id)->update(['event_id' => $eventId]);

        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$ticket->id]);

        $this->confirm($reservation->id, $userId)->assertCreated();

        $this->assertNotNull(Booking::query()->sole()->event_date);
    }

    public function test_it_marks_the_payment_refunded_when_confirmation_fails_after_charge(): void
    {
        $kept = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $stolen = $this->createTicket(Ticket::STATUS_BOOKED, 'A02');
        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$kept->id, $stolen->id]);

        $this->confirm($reservation->id, $userId)->assertStatus(422);

        $payment = Payment::query()->sole();
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->status);
        $this->assertSame($payment->amountInCents(), $payment->refundedAmountInCents());
        $this->assertDatabaseHas('outbox_messages', [
            'event_key' => 'payment.refunded:'.$payment->id.':'.$payment->refundedAmountInCents(),
        ]);
    }

    public function test_it_marks_the_payment_failed_when_the_charge_fails(): void
    {
        $this->gateway->failCharge = true;

        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$ticket->id]);

        $this->confirm($reservation->id, $userId);

        $payment = Payment::query()->sole();
        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertNotNull($payment->failure_reason);
    }

    public function test_it_reuses_the_payment_row_when_a_failed_confirmation_is_retried(): void
    {
        $kept = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $stolen = $this->createTicket(Ticket::STATUS_BOOKED, 'A02');
        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$kept->id, $stolen->id]);

        $this->confirm($reservation->id, $userId)->assertStatus(422);

        // The contested seat is put back on hold, so the retry can succeed.
        Ticket::query()->whereKey($stolen->id)->update(['status' => Ticket::STATUS_UNAVAILABLE]);

        $this->confirm($reservation->id, $userId)->assertCreated();

        $payment = Payment::query()->sole();
        $this->assertSame(Payment::STATUS_CAPTURED, $payment->status);
        $this->assertSame(0, $payment->refundedAmountInCents());
    }

    public function test_it_updates_the_payment_from_the_refund_webhook(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_UNAVAILABLE);
        $userId = (string) Str::uuid();
        $reservation = $this->createHeldReservation($userId, [$ticket->id]);
        $this->confirm($reservation->id, $userId)->assertCreated();

        $reconcile = app(ReconcileRefundAction::class);

        $reconcile->execute('ch_fake_1', 2500, 5000);
        $payment = Payment::query()->sole();
        $this->assertSame(Payment::STATUS_PARTIALLY_REFUNDED, $payment->status);
        $this->assertSame(2500, $payment->refundedAmountInCents());

        $reconcile->execute('ch_fake_1', 5000, 5000);
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->refresh()->status);

        // A replayed webhook carrying an older, smaller total never shrinks it.
        $reconcile->execute('ch_fake_1', 2500, 5000);
        $this->assertSame(5000, $payment->refresh()->refundedAmountInCents());
    }

    public function test_it_ignores_refund_webhooks_for_legacy_bookings_without_payment(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_BOOKED);

        $booking = new Booking;
        $booking->status = Booking::STATUS_REFUND_PENDING;
        $booking->reservation_id = (string) Str::uuid();
        $booking->ticket_id = $ticket->id;
        $booking->user_id = (string) Str::uuid();
        $booking->email = 'legacy@example.com';
        $booking->charge_id = 'pi_legacy';
        $booking->amount = '50.00';
        $booking->save();

        app(ReconcileRefundAction::class)->execute('pi_legacy', 5000, 5000);

        $this->assertSame(Booking::STATUS_REFUNDED, $booking->refresh()->status);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_it_refunds_using_the_event_date_snapshot_without_joining_events(): void
    {
        $ticket = $this->createTicket(Ticket::STATUS_BOOKED);
        $userId = (string) Str::uuid();

        // No events row exists for this ticket: without the snapshot the
        // policy check would refuse with 422.
        $booking = new Booking;
        $booking->status = Booking::STATUS_PAID;
        $booking->reservation_id = (string) Str::uuid();
        $booking->ticket_id = $ticket->id;
        $booking->user_id = $userId;
        $booking->email = 'snap@example.com';
        $booking->charge_id = 'pi_snap';
        $booking->amount = '50.00';
        $booking->event_date = now()->addDays(30);
        $booking->save();

        $this->postJson('/booking/'.$booking->id.'/refund', [], [
            'Authorization' => 'Bearer '.$this->authToken($userId, 'snap@example.com'),
        ])->assertSuccessful();

        $this->assertCount(1, $this->gateway->refunds);
        $this->assertSame('pi_snap', $this->gateway->refunds[0]['charge_id']);
        $this->assertSame(5000, $this->gateway->refunds[0]['amount']);
    }

    public function test_it_enqueues_the_same_outbox_event_at_most_once(): void
    {
        $writer = app(OutboxWriter::class);

        $writer->write('payment', 'p1', 'payment.captured', 'payment.captured:p1', ['payment_id' => 'p1']);
        $writer->write('payment', 'p1', 'payment.captured', 'payment.captured:p1', ['payment_id' => 'p1']);

        $this->assertSame(1, OutboxMessage::query()->count());
    }
}
