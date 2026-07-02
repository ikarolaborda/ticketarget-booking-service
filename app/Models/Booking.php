<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Booking extends Model
{
    use HasUuids;

    public const string STATUS_PAID = 'paid';
    public const string STATUS_REFUND_PENDING = 'refund_pending';
    public const string STATUS_REFUNDED = 'refunded';

    protected $fillable = ['reservation_id', 'ticket_id', 'user_id', 'email', 'charge_id', 'amount', 'status'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
