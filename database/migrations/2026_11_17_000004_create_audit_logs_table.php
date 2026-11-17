<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // No cascading deletes: audit history must outlive what it describes.
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('user_email')->nullable();
            $table->string('user_role', 191)->nullable();
            $table->foreignId('organisation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 96);
            $table->string('resource_type', 96)->nullable();
            $table->string('resource_id', 64)->nullable();
            $table->json('previous_data')->nullable();
            $table->json('new_data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->string('status', 20)->default('success');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organisation_id', 'created_at']);
            $table->index(['resource_type', 'resource_id']);
            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
