<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every domain and domain alias the Workspace customer has, as last read
     * from Google. Platform-level inventory: it has no organisation of its own.
     * A row is "unassigned" until a Super Admin maps it to an organisation
     * (organisation_domain_id). Rows are never deleted when a domain disappears
     * from Google; missing_from_google_at is set instead.
     */
    public function up(): void
    {
        Schema::create('google_domains', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('domain_name', 253)->unique();
            $table->string('alias_of', 253)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('verified')->default(false);
            $table->foreignId('organisation_domain_id')->nullable()->constrained('organisation_domains')->restrictOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('missing_from_google_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_domains');
    }
};
