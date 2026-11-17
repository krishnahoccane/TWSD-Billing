<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('label');
            $table->string('description')->nullable();
            // platform | organisation | both — where the role may be assigned.
            $table->string('scope', 20);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 96)->unique();
            $table->string('label');
            $table->string('group', 64)->index();
            $table->boolean('is_sensitive')->default(false);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['role_id', 'permission_id']);
        });

        // Platform-level role assignments (not tied to an organisation).
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
        });

        // Organisation-scoped role assignments. A user may hold roles in
        // many organisations; there is no organisation_id on users.
        Schema::create('organisation_user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['organisation_id', 'user_id', 'role_id'], 'org_user_role_unique');
            $table->index(['user_id', 'organisation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_user_roles');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
