<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Booking extends Model
{
    use HasUuids;

    protected $fillable = ['reservation_id', 'ticket_id', 'user_id', 'charge_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
