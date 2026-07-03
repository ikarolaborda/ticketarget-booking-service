<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Booking;
use App\Models\Reservation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

final class TicketsConfirmationMail extends Mailable
{
    /**
     * @param  Collection<int, Booking>  $bookings
     */
    public function __construct(
        public readonly Reservation $reservation,
        public readonly Collection $bookings,
        /** @var array<string, string> ticket id => seat label */
        public readonly array $seats,
        /** @var array<string, string> booking id => signed entry code */
        public readonly array $codes = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Ticketarget tickets are confirmed');
    }

    public function content(): Content
    {
        $lines = $this->bookings
            ->map(fn (Booking $booking): string => sprintf(
                "  Seat %s — R$ %s\n  Entry code: %s",
                $this->seats[$booking->ticket_id] ?? $booking->ticket_id,
                $booking->amount,
                $this->codes[$booking->id] ?? 'available in My Tickets',
            ))
            ->implode("\n");

        $total = $this->bookings->sum(fn (Booking $booking): float => (float) $booking->amount);

        $text = "Booking confirmed!\n\n"
            ."Reservation: {$this->reservation->id}\n"
            ."Your seats:\n{$lines}\n\n"
            .sprintf("Total paid: R$ %.2f\n\n", $total)
            ."Present this email at the venue entrance. Enjoy the show!\n"
            .'— Ticketarget';

        return new Content(htmlString: nl2br(e($text)));
    }
}
