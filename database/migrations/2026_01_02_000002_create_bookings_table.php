<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('reservation_id')->index();
            $table->uuid('ticket_id');
            $table->uuid('user_id')->index();
            $table->string('charge_id');
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            // A ticket can be booked at most once — the ultimate anti-double-book guard.
            $table->unique('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
