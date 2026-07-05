<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            // Purchase-time catalog snapshots (DDD remediation P4): display and
            // reporting reads stop joining event-context tables. Deliberately
            // NOT live projections — a catalog edit after purchase must not
            // rewrite receipts/history.
            $table->string('seat')->nullable();
            $table->string('ticket_type')->nullable();
            $table->string('event_name')->nullable();
            $table->uuid('event_id')->nullable()->index();
        });

        Schema::table('reservations', function (Blueprint $table): void {
            $table->json('seats')->nullable();
        });

        $this->backfillBookings();
        $this->backfillReservations();
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['seat', 'ticket_type', 'event_name', 'event_id']);
        });

        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn('seats');
        });
    }

    /**
     * Fill-missing-only and rerunnable: the joins are still reachable during
     * the shared-DB shadow period, so legacy rows get their snapshots now and
     * the readers can drop the joins entirely.
     */
    private function backfillBookings(): void
    {
        DB::table('bookings')
            ->whereNull('seat')
            ->orderBy('id')
            ->chunkById(500, function ($bookings): void {
                $tickets = DB::table('tickets')
                    ->leftJoin('events', 'events.id', '=', 'tickets.event_id')
                    ->whereIn('tickets.id', $bookings->pluck('ticket_id'))
                    ->get([
                        'tickets.id',
                        'tickets.seat',
                        'tickets.type',
                        'tickets.event_id',
                        'events.name as event_name',
                        'events.date as event_date',
                    ])
                    ->keyBy('id');

                foreach ($bookings as $booking) {
                    $ticket = $tickets[$booking->ticket_id] ?? null;

                    if ($ticket === null) {
                        continue;
                    }

                    DB::table('bookings')->where('id', $booking->id)->update([
                        'seat' => $ticket->seat,
                        'ticket_type' => $ticket->type,
                        'event_name' => $ticket->event_name,
                        'event_id' => $ticket->event_id,
                        'event_date' => $booking->event_date ?? $ticket->event_date,
                    ]);
                }
            });
    }

    private function backfillReservations(): void
    {
        DB::table('reservations')
            ->whereNull('seats')
            ->orderBy('id')
            ->chunkById(500, function ($reservations): void {
                foreach ($reservations as $reservation) {
                    $ticketIds = json_decode((string) $reservation->ticket_ids, true);

                    if (! is_array($ticketIds) || $ticketIds === []) {
                        continue;
                    }

                    $seats = DB::table('tickets')
                        ->whereIn('id', $ticketIds)
                        ->orderBy('seat')
                        ->get(['id', 'event_id', 'seat', 'price', 'type'])
                        ->map(static fn (object $t): array => [
                            'id' => $t->id,
                            'event_id' => $t->event_id,
                            'seat' => $t->seat,
                            'price' => number_format((float) $t->price, 2, '.', ''),
                            'type' => $t->type,
                        ])
                        ->values()
                        ->all();

                    if ($seats === []) {
                        continue;
                    }

                    DB::table('reservations')->where('id', $reservation->id)->update([
                        'seats' => json_encode($seats, JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }
};
