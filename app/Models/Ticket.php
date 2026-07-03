<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Read/update view of the tickets owned by the Event service (shared data plane).
 * Booking only ever transitions status; it never creates tickets.
 */
final class Ticket extends Model
{
    use HasUuids;

    public const string STATUS_AVAILABLE = 'available';
    public const string STATUS_UNAVAILABLE = 'unavailable';
    public const string STATUS_BOOKED = 'booked';


    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }
}
