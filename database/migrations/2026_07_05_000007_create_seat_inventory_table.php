<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shadow-mode booking-owned inventory (DDD remediation Phase 2).
        // While dual-write runs, tickets.status remains the source of truth;
        // booking:verify-inventory reports drift before any cutover.
        Schema::create('seat_inventory', function (Blueprint $table): void {
            $table->uuid('ticket_id')->primary();
            $table->uuid('event_id')->nullable()->index();
            $table->string('status')->index();
            $table->uuid('reservation_id')->nullable()->index();
            $table->timestampTz('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_inventory');
    }
};
