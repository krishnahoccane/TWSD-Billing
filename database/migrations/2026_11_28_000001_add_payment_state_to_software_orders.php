<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment state of storefront orders, so a bank-transfer order reported as
 * "pending" can later be confirmed, and a refund made on the storefront can
 * be recorded (POST /orders/{ord}/confirm-payment and /refund).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('software_orders', function (Blueprint $table) {
            $table->string('payment_status', 20)->default('paid')->after('payment_reference'); // pending | paid | refunded
            $table->timestamp('paid_at')->nullable()->after('payment_status');
            $table->timestamp('refunded_at')->nullable()->after('paid_at');
            $table->string('refund_reference', 120)->nullable()->after('refunded_at');
            $table->string('refund_reason', 500)->nullable()->after('refund_reference');
        });

        // Existing rows: the status was kept in details.payment_status.
        DB::table('software_orders')->orderBy('id')->each(function ($order) {
            $details = json_decode((string) $order->details, true) ?: [];
            $status = ($details['payment_status'] ?? 'paid') === 'pending' ? 'pending' : 'paid';
            DB::table('software_orders')->where('id', $order->id)->update([
                'payment_status' => $status,
                'paid_at' => $status === 'paid' ? $order->created_at : null,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('software_orders', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'paid_at', 'refunded_at', 'refund_reference', 'refund_reason']);
        });
    }
};
