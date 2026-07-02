<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            // Existing rows are backfilled to 'paid' by the column default.
            $table->string('status')->default('paid')->index()->after('email');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropUnique(['ticket_id']);
        });

        // Anti-double-book invariant now applies only to LIVE bookings, so a
        // refunded seat can be resold. Predicate lists live statuses explicitly
        // (not "<> refunded") so future statuses opt in deliberately.
        DB::statement(
            "CREATE UNIQUE INDEX bookings_ticket_id_live_unique ON bookings (ticket_id) WHERE status IN ('paid', 'refund_pending')",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bookings_ticket_id_live_unique');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->unique('ticket_id');
            $table->dropColumn('status');
        });
    }
};
