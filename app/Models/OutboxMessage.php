<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class OutboxMessage extends Model
{
    use HasUuids;

    protected $table = 'outbox_messages';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
