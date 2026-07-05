<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Booking-local projection of catalog ticket.generated events; an
        // event's capacity is SUM(count) over its rows. One row per consumed
        // integration event — the unique event_key turns at-least-once Kafka
        // delivery into effectively-once application (insertOrIgnore).
        Schema::create('catalog_capacity_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_key')->unique();
            $table->uuid('event_id')->index();
            $table->uuid('zone_id')->nullable();
            $table->unsignedInteger('count');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_capacity_ledger');
    }
};
