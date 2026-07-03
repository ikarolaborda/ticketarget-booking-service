<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Newest-first booking feed for the live dashboard widget and the bookings
 * data table. Pagination uses an opaque composite cursor (created_at, id) —
 * strictly ordered even if id generation were ever non-monotonic.
 */
final readonly class AdminBookingsController
{
    private const int DEFAULT_LIMIT = 25;

    private const int MAX_LIMIT = 100;

    public function __invoke(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->integer('limit', self::DEFAULT_LIMIT), 1), self::MAX_LIMIT);
        $cursor = $this->decodeCursor((string) $request->query('cursor', ''));

        $query = DB::table('bookings')
            ->leftJoin('tickets', 'tickets.id', '=', 'bookings.ticket_id')
            ->leftJoin('events', 'events.id', '=', 'tickets.event_id')
            ->select(
                'bookings.id',
                'bookings.created_at',
                'bookings.email',
                'bookings.amount',
                'bookings.status',
                'tickets.seat',
                'events.name AS event_name'
            )
            ->orderByDesc('bookings.created_at')
            ->orderByDesc('bookings.id')
            ->limit($limit);

        if ($cursor !== null) {
            [$createdAt, $id] = $cursor;
            $query->where(function ($q) use ($createdAt, $id): void {
                $q->where('bookings.created_at', '<', $createdAt)
                    ->orWhere(function ($qq) use ($createdAt, $id): void {
                        $qq->where('bookings.created_at', '=', $createdAt)
                            ->where('bookings.id', '<', $id);
                    });
            });
        }

        $rows = $query->get();

        $data = $rows->map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'created_at' => Carbon::parse((string) $row->created_at)->toIso8601String(),
            'email' => $row->email !== null ? (string) $row->email : null,
            'amount' => number_format((float) $row->amount, 2, '.', ''),
            'status' => (string) $row->status,
            'seat' => $row->seat !== null ? (string) $row->seat : null,
            'event_name' => $row->event_name !== null ? (string) $row->event_name : null,
        ])->all();

        $last = $rows->last();

        return response()->json([
            'data' => $data,
            'next_cursor' => ($last !== null && count($data) === $limit)
                ? $this->encodeCursor((string) $last->created_at, (string) $last->id)
                : null,
        ]);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function decodeCursor(string $cursor): ?array
    {
        if ($cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false || ! str_contains($decoded, '|')) {
            return null;
        }

        [$createdAt, $id] = explode('|', $decoded, 2);

        return [$createdAt, $id];
    }

    private function encodeCursor(string $createdAt, string $id): string
    {
        return rtrim(strtr(base64_encode($createdAt.'|'.$id), '+/', '-_'), '=');
    }
}
