<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SeatInventory extends Model
{
    public const string STATUS_AVAILABLE = 'available';

    public const string STATUS_HELD = 'held';

    public const string STATUS_BOOKED = 'booked';

    protected $table = 'seat_inventory';

    protected $primaryKey = 'ticket_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}
