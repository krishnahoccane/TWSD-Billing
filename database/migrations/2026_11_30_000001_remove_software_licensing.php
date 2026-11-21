<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Software licensing (MusicDesk / LabelDesk) was removed from this
 * application on 2026-11-20: it is handled inside the MusicDesk application.
 *
 * Databases that already ran the licensing migrations
 * (2026_11_27_000001, 2026_11_28_000001, 2026_11_29_000001) lose those tables,
 * permissions and the organisations.currency column here. Databases that never
 * ran them are unaffected.
 */
return new class extends Migration
{
    private const TABLES = [
        'software_download_links', 'storefront_idempotency_keys', 'storefront_webhook_events', 'software_releases',
        'software_orders', 'software_license_events', 'software_activations', 'software_licenses',
        'software_plan_prices', 'software_plans', 'software_products', 'storefront_customers', 'storefronts',
    ];

    private const PERMISSIONS = ['licensing.view', 'licensing.manage', 'licensing.storefronts.manage'];

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        if (Schema::hasColumn('organisations', 'currency')) {
            Schema::table('organisations', fn (Blueprint $table) => $table->dropColumn('currency'));
        }

        $ids = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        // The license-token signing key.
        DB::table('integration_settings')->where('provider', 'licensing')->delete();

        // The removed migrations' entries, so `migrate:status` stays clean.
        DB::table('migrations')->whereIn('migration', [
            '2026_11_27_000001_create_licensing_tables',
            '2026_11_28_000001_add_payment_state_to_software_orders',
            '2026_11_29_000001_add_downloads_and_usd_amounts',
        ])->delete();
    }

    public function down(): void
    {
        // Not restorable: licensing lives in the MusicDesk application.
    }
};
