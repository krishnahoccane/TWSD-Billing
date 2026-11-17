<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('code', 32)->unique();
            $table->string('pan', 10)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('primary_contact_name')->nullable();
            $table->string('primary_contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('billing_email')->nullable();
            $table->text('registered_address')->nullable();
            $table->text('billing_address')->nullable();
            $table->char('country', 2)->default('IN');
            $table->string('state', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('notes')->nullable();
            // Users are never hard-deleted through the application; restrict
            // protects authorship history if someone tries at the DB level.
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisations');
    }
};
