<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('category', 30);
            $table->string('subject', 200);
            $table->text('message');
            $table->string('status', 20)->index();            // open | in_progress | resolved | closed
            $table->text('response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'status']);
        });

        // Per-user e-mail choices; in-app notifications are always kept.
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('last_login_ip');
        });

        // Not part of the frozen invoice snapshot: only records that the
        // overdue reminder was sent.
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('overdue_notified_at')->nullable()->after('void_reason');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('overdue_notified_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notification_preferences'));
        Schema::dropIfExists('support_requests');
    }
};
