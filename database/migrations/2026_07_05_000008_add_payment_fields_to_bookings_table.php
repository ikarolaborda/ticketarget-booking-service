<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->uuid('payment_id')->nullable()->index();

            // Refund-policy snapshot taken at confirmation time, so the policy
            // check no longer needs a cross-context join for new bookings.
            $table->timestampTz('event_date')->nullable();
        });

        $this->backfillPayments();
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['payment_id', 'event_date']);
        });
    }

    /**
     * One payment per (reservation_id, charge_id) group. Amounts are summed
     * across the group because a single charge covers every seat in the
     * reservation. Refunded totals for historical rows are conservative:
     * exact tiers were never stored per charge, so only all-refunded groups
     * are marked refunded and later webhooks reconcile the precise amount.
     */
    private function backfillPayments(): void
    {
        $groups = DB::table('bookings')
            ->select('reservation_id', 'charge_id')
            ->selectRaw('sum(amount) as total')
            ->whereNull('payment_id')
            ->groupBy('reservation_id', 'charge_id')
            ->orderBy('reservation_id')
            ->get();

        $seen = [];

        foreach ($groups as $group) {
            if (isset($seen[$group->reservation_id])) {
                continue;
            }

            $seen[$group->reservation_id] = true;

            $statuses = DB::table('bookings')
                ->where('reservation_id', $group->reservation_id)
                ->where('charge_id', $group->charge_id)
                ->pluck('status');

            $allRefunded = $statuses->isNotEmpty()
                && $statuses->every(fn (string $status): bool => $status === 'refunded');

            $paymentId = (string) Str::uuid();

            DB::table('payments')->insert([
                'id' => $paymentId,
                'reservation_id' => $group->reservation_id,
                'provider' => 'stripe',
                'provider_payment_intent_id' => $group->charge_id,
                'amount' => $group->total,
                'currency' => 'brl',
                'status' => $allRefunded ? 'refunded' : 'captured',
                'refunded_amount' => $allRefunded ? $group->total : 0,
                'idempotency_key' => $group->reservation_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('bookings')
                ->where('reservation_id', $group->reservation_id)
                ->where('charge_id', $group->charge_id)
                ->update(['payment_id' => $paymentId]);
        }
    }
};
