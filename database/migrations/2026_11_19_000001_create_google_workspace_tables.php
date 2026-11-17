<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->unique();
            $table->json('settings')->nullable();          // non-secret configuration
            $table->text('secrets')->nullable();           // encrypted at rest (tokens; Phase F)
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('google_organisational_units', function (Blueprint $table) {
            $table->id();
            $table->string('google_org_unit_id')->nullable()->unique();
            $table->string('name');
            $table->string('path', 500)->unique();
            $table->string('parent_path', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('google_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Null = unassigned (waiting for Super Admin review). Never
            // changed by synchronisation, only by an explicit admin action.
            $table->foreignId('organisation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained('organisation_domains')->restrictOnDelete();
            // Google's immutable user id is the external identity.
            $table->string('google_user_id', 64)->nullable()->unique();
            $table->string('primary_email', 254)->index();
            // Equals primary_email while the address is in use; null once the
            // account is permanently deleted, so the address can be reissued
            // while the historical row is kept.
            $table->string('email_unique_key', 254)->nullable()->unique();
            $table->string('first_name', 60);
            $table->string('last_name', 60);
            $table->string('display_name')->nullable();
            $table->string('recovery_email')->nullable();
            $table->string('recovery_phone', 32)->nullable();
            $table->string('organisational_unit_path', 500)->default('/');
            $table->string('account_status', 32)->index();
            $table->boolean('is_suspended')->default(false);
            $table->string('billing_status', 20)->default('pending')->index();
            // Display cache only; the billing engine resolves prices (Phase E).
            $table->decimal('monthly_price', 12, 2)->nullable();
            $table->date('billable_from')->nullable();
            $table->string('suspension_reason', 500)->nullable();
            $table->string('deletion_reason', 500)->nullable();
            $table->date('deletion_effective_date')->nullable();
            // In-flight operation, for idempotent jobs and reconciliation.
            $table->string('pending_operation', 32)->nullable()->index();
            $table->string('previous_status', 32)->nullable();
            $table->timestamp('pending_since')->nullable();
            $table->text('last_error')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('google_created_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('missing_from_google_at')->nullable();
            $table->timestamp('permanently_deleted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            // Lifecycle soft-deletion timestamp. Rows are never hidden or removed.
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'account_status']);
        });

        // No cascading deletes anywhere below: history outlives the account.
        Schema::create('google_account_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_account_id')->constrained('google_accounts')->restrictOnDelete();
            $table->foreignId('organisation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->json('details')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['google_account_id', 'created_at']);
        });

        Schema::create('google_api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('operation', 64)->index();
            $table->string('target', 254)->nullable();
            $table->string('status', 20)->index();
            $table->string('error_type', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('google_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 32)->index();
            $table->string('status', 20)->index();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unassigned')->default(0);
            $table->unsignedInteger('missing')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->json('details')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('google_account_id')->constrained('google_accounts')->restrictOnDelete();
            $table->foreignId('organisation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->json('checks');
            $table->string('status', 20)->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('api_response')->nullable();
            $table->timestamps();
        });

        // One-time, expiring, encrypted initial passwords (decision D-07).
        Schema::create('account_secrets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('google_account_id')->constrained('google_accounts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('payload')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'account_secrets', 'account_deletion_requests', 'google_sync_logs', 'google_api_logs',
            'google_account_history', 'google_accounts', 'google_organisational_units', 'integration_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
