<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('type', 20)->default('general'); // general | billing | technical
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('designation', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['organisation_id', 'type']);
        });

        Schema::create('organisation_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['organisation_id', 'key']);
        });

        Schema::create('organisation_domains', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            // A domain belongs to exactly one organisation.
            $table->string('domain_name', 253)->unique();
            $table->string('google_domain_id')->nullable();
            $table->string('domain_type', 20)->default('secondary'); // primary | secondary | alias
            // "unverified" = not yet checked against Google Workspace.
            $table->string('verification_status', 20)->default('unverified');
            $table->string('mx_status', 20)->default('unknown');
            $table->boolean('is_primary')->default(false);
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['organisation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_domains');
        Schema::dropIfExists('organisation_settings');
        Schema::dropIfExists('organisation_contacts');
    }
};
