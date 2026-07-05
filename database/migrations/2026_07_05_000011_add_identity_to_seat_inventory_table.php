<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inventory ownership cutover (DDD remediation Phase 2 -> owner):
        // booking needs the static seat identity locally so reserve/confirm
        // never read catalog tables. Identity is immutable post-generation
        // (catalog freezes topology); source records provenance
        // (seed | event | transition) for audit and rerunnable seeding.
        Schema::table('seat_inventory', function (Blueprint $table): void {
            $table->string('seat')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('type')->nullable();
            $table->uuid('zone_id')->nullable();
            $table->string('source')->nullable();
            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('seat_inventory', function (Blueprint $table): void {
            $table->dropIndex(['event_id', 'status']);
            $table->dropColumn(['seat', 'price', 'type', 'zone_id', 'source']);
        });
    }
};
