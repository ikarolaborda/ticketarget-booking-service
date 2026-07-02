<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Reservation extends Model
{
    use HasUuids;

    public const string STATUS_HELD = 'held';
    public const string STATUS_CONFIRMED = 'confirmed';
    public const string STATUS_RELEASED = 'released';

    protected $fillable = ['user_id', 'ticket_ids', 'status', 'expires_at'];

    protected function casts(): array
    {
        return ['ticket_ids' => 'array', 'expires_at' => 'immutable_datetime'];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
