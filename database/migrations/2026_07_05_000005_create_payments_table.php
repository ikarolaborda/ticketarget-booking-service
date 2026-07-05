<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // One logical payment per reservation: the reservation is the unit
            // of charge, refund and reconciliation.
            $table->uuid('reservation_id')->unique();
            $table->string('provider')->default('stripe');
            $table->string('provider_payment_intent_id')->nullable()->index();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('status')->index();
            $table->decimal('refunded_amount', 10, 2)->default(0);
            $table->string('failure_reason')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
