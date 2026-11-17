<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One table for both workflows: adding a domain and removing one.
        Schema::create('domain_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('organisation_domain_id')->nullable()->constrained('organisation_domains')->restrictOnDelete();
            $table->string('type', 10);                       // add | remove
            $table->string('domain_name', 253)->index();
            $table->string('domain_type', 20)->default('secondary');
            $table->string('status', 24)->index();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('request_note', 500)->nullable();
            $table->json('checks')->nullable();               // attested dependency checks (removal)
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->string('verification_method', 20)->nullable();
            // A public DNS value, not a credential.
            $table->string('verification_token', 500)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failed_step', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('pending_since')->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'status']);
        });

        Schema::create('domain_verification_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_request_id')->constrained('domain_requests')->restrictOnDelete();
            $table->string('type', 24);                       // dns_txt | google_verify | google_domain
            $table->string('result', 10);                     // pass | fail | error
            $table->json('details')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['domain_request_id', 'created_at']);
        });

        // Append-only. No cascading deletes.
        Schema::create('domain_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('organisation_domain_id')->nullable()->constrained('organisation_domains')->restrictOnDelete();
            $table->foreignId('domain_request_id')->nullable()->constrained('domain_requests')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('domain_name', 253)->index();
            $table->string('event', 64);
            $table->json('details')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_history');
        Schema::dropIfExists('domain_verification_checks');
        Schema::dropIfExists('domain_requests');
    }
};
