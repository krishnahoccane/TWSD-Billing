<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** New permissions and the existing roles that get them by default. */
    private const PERMISSIONS = [
        'accounts.reset_password' => ['Accounts reset password', 'accounts', false, ['super_admin', 'operations_admin', 'organisation_manager']],
        'accounts.manage_security' => ['Accounts manage security', 'accounts', false, ['super_admin', 'operations_admin', 'organisation_manager']],
        'accounts.reactivate_payment_suspended' => ['Accounts reactivate payment suspended', 'accounts', true, ['super_admin']],
        'integrations.whatsapp.manage' => ['Integrations whatsapp manage', 'integrations', true, ['super_admin']],
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // WhatsApp notifications: international format, digits only.
            $table->string('phone', 20)->nullable();
        });

        Schema::table('google_accounts', function (Blueprint $table) {
            // manual | non_payment — why a suspended mailbox is suspended.
            $table->string('suspension_type', 20)->nullable();
        });

        DB::table('google_accounts')->where('is_suspended', true)->update(['suspension_type' => 'manual']);

        // One row per reminder sent, so a reminder is never sent twice.
        Schema::create('invoice_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('stage', 20);               // reminder_1 | reminder_2 | final_1 | final_2 | final_3
            $table->timestamp('sent_at');

            $table->unique(['invoice_id', 'stage']);
        });

        // Automatic suspension for non-payment, and what each mailbox was before it.
        Schema::create('payment_suspensions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->index();     // active | lifted
            $table->json('invoice_ids');
            $table->string('reason', 500);
            $table->timestamp('suspended_at');
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('lift_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'status']);
        });

        Schema::create('payment_suspension_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_suspension_id')->constrained()->restrictOnDelete();
            $table->foreignId('google_account_id')->constrained('google_accounts')->restrictOnDelete();
            $table->string('status_before', 32);
            $table->string('suspension_type_before', 20)->nullable();
            // suspended: active before, suspended for non-payment (reactivated when paid)
            // already_suspended: suspended by hand before, left alone and never reactivated
            $table->string('action', 20);
            $table->timestamp('reactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_suspension_id', 'google_account_id'], 'psa_suspension_account_unique');
        });

        $now = now();

        foreach (self::PERMISSIONS as $name => [$label, $group, $sensitive, $roles]) {
            if (! DB::table('permissions')->where('name', $name)->exists()) {
                DB::table('permissions')->insert(['name' => $name, 'label' => $label, 'group' => $group,
                    'is_sensitive' => $sensitive, 'created_at' => $now, 'updated_at' => $now]);
            }

            $permissionId = DB::table('permissions')->where('name', $name)->value('id');

            foreach (DB::table('roles')->whereIn('name', $roles)->pluck('id') as $roleId) {
                if (! DB::table('role_permissions')->where(['role_id' => $roleId, 'permission_id' => $permissionId])->exists()) {
                    DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId,
                        'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::dropIfExists('payment_suspension_accounts');
        Schema::dropIfExists('payment_suspensions');
        Schema::dropIfExists('invoice_reminders');

        Schema::table('google_accounts', fn (Blueprint $table) => $table->dropColumn('suspension_type'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('phone'));
    }
};
