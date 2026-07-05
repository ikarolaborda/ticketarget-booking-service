<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('aggregate_type');
            $table->uuid('aggregate_id');
            $table->string('event_type');

            // Deterministic per semantic event, so a retried application path
            // enqueues the same event at most once (insertOrIgnore).
            $table->string('event_key')->unique();
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestampTz('published_at')->nullable()->index();
            $table->timestampTz('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
