<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Booking-owned projection of catalog event.created/event.updated
        // events: the confirm-time name/date snapshot reads this instead of
        // the catalog's events table (the last cross-context read on the
        // purchase path before schema isolation).
        Schema::create('catalog_event_directory', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->string('name');
            $table->timestampTz('event_date')->nullable();
            // Canonical UTC 'Y-m-d H:i:s.u' — zero-padded so lexical order is
            // chronological on both sqlite (text) and pgsql (varchar), which
            // keeps the monotonic guard a plain string comparison.
            $table->string('occurred_at', 26);
            $table->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_event_directory');
    }
};
