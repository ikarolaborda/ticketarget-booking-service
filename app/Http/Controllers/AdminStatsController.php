<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only sales aggregates for the admin dashboard. All day boundaries are
 * UTC (documented in the API contract); revenue_recognized counts money
 * currently held (paid + refund_pending), refunded money is reported
 * separately. The daily series reflects CURRENT booking status — a refund
 * removes its sale from the recognized series.
 */
final readonly class AdminStatsController
{
    private const array RECOGNIZED = [Booking::STATUS_PAID, Booking::STATUS_REFUND_PENDING];
    private const int SERIES_DAYS = 14;
    private const int TOP_EVENTS = 8;

    public function __invoke(): JsonResponse
    {
        $now = Carbon::now('UTC');

        return response()->json([
            'generated_at' => $now->toIso8601String(),
            'timezone' => 'UTC',
            'totals' => $this->totals($now),
            'status_breakdown' => $this->statusBreakdown(),
            'revenue_by_day' => $this->revenueByDay($now),
            'top_events' => $this->topEvents(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(Carbon $now): array
    {
        $recognized = Booking::query()->whereIn('status', self::RECOGNIZED);

        $base = fn () => (clone $recognized);

        $refunds = Booking::query()->where('status', Booking::STATUS_REFUNDED);

        return [
            'revenue_recognized' => $this->money((clone $recognized)->sum('amount')),
            'paid_amount' => $this->money(Booking::query()->where('status', Booking::STATUS_PAID)->sum('amount')),
            'revenue_today' => $this->money($base()->where('created_at', '>=', $now->copy()->startOfDay())->sum('amount')),
            'revenue_7d' => $this->money($base()->where('created_at', '>=', $now->copy()->subDays(7))->sum('amount')),
            'tickets_sold' => $base()->count(),
            'sold_today' => $base()->where('created_at', '>=', $now->copy()->startOfDay())->count(),
            'refunded_amount' => $this->money((clone $refunds)->sum('amount')),
            'refunds_count' => (clone $refunds)->count(),
            'active_holds' => Reservation::query()
                ->where('status', Reservation::STATUS_HELD)
                ->where('expires_at', '>', $now)
                ->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function statusBreakdown(): array
    {
        return Booking::query()
            ->select('status', DB::raw('COUNT(*) AS n'))
            ->groupBy('status')
            ->pluck('n', 'status')
            ->map(static fn ($n): int => (int) $n)
            ->all();
    }

    /**
     * Last 14 UTC calendar days including today, zero-filled, ascending —
     * the chart never has to gap-fill. Aggregated in PHP over the bounded
     * window so the date-bucketing stays portable across pgsql and sqlite.
     *
     * @return list<array{date: string, revenue: string, bookings: int}>
     */
    private function revenueByDay(Carbon $now): array
    {
        $windowStart = $now->copy()->startOfDay()->subDays(self::SERIES_DAYS - 1);

        $rows = Booking::query()
            ->whereIn('status', self::RECOGNIZED)
            ->where('created_at', '>=', $windowStart)
            ->get(['created_at', 'amount']);

        $buckets = [];
        for ($i = 0; $i < self::SERIES_DAYS; $i++) {
            $buckets[$windowStart->copy()->addDays($i)->toDateString()] = ['revenue' => 0.0, 'bookings' => 0];
        }

        foreach ($rows as $row) {
            $day = Carbon::parse((string) $row->created_at)->setTimezone('UTC')->toDateString();
            if (isset($buckets[$day])) {
                $buckets[$day]['revenue'] += (float) $row->amount;
                $buckets[$day]['bookings']++;
            }
        }

        $series = [];
        foreach ($buckets as $date => $bucket) {
            $series[] = [
                'date' => $date,
                'revenue' => $this->money($bucket['revenue']),
                'bookings' => $bucket['bookings'],
            ];
        }

        return $series;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topEvents(): array
    {
        $sales = DB::table('bookings')
            ->join('tickets', 'tickets.id', '=', 'bookings.ticket_id')
            ->join('events', 'events.id', '=', 'tickets.event_id')
            ->whereIn('bookings.status', self::RECOGNIZED)
            ->select(
                'events.id',
                'events.name',
                'events.date',
                DB::raw('COUNT(*) AS sold'),
                DB::raw('SUM(bookings.amount) AS revenue')
            )
            ->groupBy('events.id', 'events.name', 'events.date')
            ->orderByDesc(DB::raw('SUM(bookings.amount)'))
            ->limit(self::TOP_EVENTS)
            ->get();

        if ($sales->isEmpty()) {
            return [];
        }

        $capacities = DB::table('tickets')
            ->whereIn('event_id', $sales->pluck('id'))
            ->select('event_id', DB::raw('COUNT(*) AS capacity'))
            ->groupBy('event_id')
            ->pluck('capacity', 'event_id');

        return $sales->map(fn (object $row): array => [
            'event_id' => (string) $row->id,
            'name' => (string) $row->name,
            'date' => $row->date !== null ? Carbon::parse((string) $row->date)->toIso8601String() : null,
            'sold' => (int) $row->sold,
            'capacity' => isset($capacities[$row->id]) ? (int) $capacities[$row->id] : null,
            'revenue' => $this->money((float) $row->revenue),
        ])->all();
    }

    private function money(float|int|string|null $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }
}
