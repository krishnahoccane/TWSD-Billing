<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Local record <-> Zoho Books record. One mapping per local record and
        // per Zoho record, so retries can never create a second customer,
        // invoice or payment in Zoho.
        Schema::create('zoho_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('mappable_type', 100);
            $table->unsignedBigInteger('mappable_id');
            $table->string('zoho_entity', 30);                  // contact | invoice | payment
            $table->string('zoho_id', 64)->nullable();
            $table->string('zoho_organisation_id', 64)->nullable();
            $table->string('sync_status', 20)->index();         // pending | synced | failed | mismatch | needs_attention
            $table->string('zoho_number', 64)->nullable();
            $table->string('zoho_status', 30)->nullable();
            $table->string('zoho_url', 500)->nullable();        // customer-facing invoice / payment page
            $table->bigInteger('zoho_total_minor')->nullable();
            $table->bigInteger('zoho_balance_minor')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['mappable_type', 'mappable_id'], 'zoho_mapping_local_unique');
            $table->unique(['zoho_organisation_id', 'zoho_entity', 'zoho_id'], 'zoho_mapping_remote_unique');
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_id', 128);
            $table->string('event_type', 60)->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->index();              // received | processed | ignored | failed
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });

        Schema::create('integration_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('operation', 60)->index();
            $table->string('target', 120)->nullable();
            $table->string('status', 20)->index();
            $table->json('details')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_logs');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('zoho_mappings');
    }
};
