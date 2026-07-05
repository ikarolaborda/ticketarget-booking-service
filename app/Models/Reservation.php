<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property list<array{id: string, event_id: string|null, seat: string, price: string, type: string}>|null $seats
 */
final class Reservation extends Model
{
    use HasUuids;

    public const string STATUS_HELD = 'held';

    public const string STATUS_CONFIRMED = 'confirmed';

    public const string STATUS_RELEASED = 'released';

    protected function casts(): array
    {
        return [
            'ticket_ids' => 'array',
            'seats' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
