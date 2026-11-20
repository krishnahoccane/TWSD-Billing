<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Software licensing (LabelDesk, sold through the musicdesk.tech storefront).
 *
 * This application is the system of record for customers (organisations),
 * plans and prices, licenses, domain activations and releases. Payment and
 * invoicing for these sales happen on the storefront (Razorpay); the
 * storefront reports each paid order here and this application applies it.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'licensing.view' => ['Licensing view', 'licensing', false, ['super_admin', 'organisation_manager', 'billing_manager']],
        'licensing.manage' => ['Licensing manage', 'licensing', true, ['super_admin']],
        'licensing.storefronts.manage' => ['Licensing storefronts manage', 'licensing', true, ['super_admin']],
    ];

    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            // Currency the customer buys software in (USD, EUR, GBP, INR…).
            $table->char('currency', 3)->nullable();
        });

        Schema::create('storefronts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 40)->unique();              // musicdesk
            $table->string('name');
            $table->string('api_key_hash', 64)->nullable()->unique(); // sha256 of the key; the key is shown once
            $table->string('api_key_hint', 12)->nullable();          // last characters, to recognise it
            $table->string('webhook_url', 500)->nullable();
            $table->text('webhook_secret')->nullable();              // encrypted
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        // Customers a storefront may act for (created by it, or linked by a Super Admin).
        Schema::create('storefront_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storefront_id')->constrained()->restrictOnDelete();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['storefront_id', 'organisation_id']);
        });

        Schema::create('software_products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('storefront_id')->constrained()->restrictOnDelete();
            $table->string('code', 40)->unique();              // labeldesk
            $table->string('name');
            $table->string('key_prefix', 8)->default('LDSK');  // license keys look like LDSK-XXXX-XXXX-XXXX-XXXX
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('software_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('software_product_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);                        // starter | business | enterprise
            $table->string('name');
            $table->string('blurb', 500)->nullable();
            $table->json('features')->nullable();
            $table->unsignedSmallInteger('included_domains')->default(1);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['software_product_id', 'code']);
        });

        // One row per plan, kind and currency (per-customer currency).
        Schema::create('software_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('software_plan_id')->constrained()->restrictOnDelete();
            $table->string('kind', 30);                        // annual | lifetime | extra_domain | extra_domain_lifetime | updates_renewal
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['software_plan_id', 'kind', 'currency']);
        });

        Schema::create('software_licenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 20)->unique();            // LIC-24A7
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('software_product_id')->constrained()->restrictOnDelete();
            $table->foreignId('software_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('storefront_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('billing_type', 20);                // annual | lifetime
            $table->string('status', 20)->index();             // pending | active | suspended | revoked (expiring/expired are derived)
            $table->string('key_hash', 64)->unique();          // sha256 of the normalised key, for the license server
            $table->text('key_encrypted');                     // APP_KEY-encrypted, revealed on request (audited)
            $table->string('key_last4', 4);
            $table->unsignedSmallInteger('domains_included');
            $table->unsignedSmallInteger('domains_extra')->default(0);
            $table->timestamp('purchased_at');
            $table->timestamp('expires_at')->nullable();       // null for lifetime
            $table->timestamp('support_until')->nullable();
            $table->string('max_version', 20)->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->bigInteger('price_paid_minor')->default(0);
            $table->char('currency', 3);
            $table->timestamp('pending_until')->nullable();    // bank transfer grace
            $table->string('status_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create('software_activations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('software_license_id')->constrained()->restrictOnDelete();
            $table->string('domain', 253);
            $table->string('environment', 20);                 // production | staging
            $table->string('status', 20)->index();             // pending | active | deactivated
            $table->string('instance_id', 100)->nullable();
            $table->string('server_ip', 45)->nullable();
            $table->string('version', 20)->nullable();
            $table->string('php_version', 20)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_check_in_at')->nullable();
            $table->boolean('check_in_requested')->default(false);
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->index(['domain', 'status']);
            $table->index(['software_license_id', 'status']);
        });

        // License timeline (shown to the customer; admin changes are also in audit_logs).
        Schema::create('software_license_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('software_license_id')->constrained()->restrictOnDelete();
            $table->string('event', 60);
            $table->json('details')->nullable();
            $table->string('actor', 120)->nullable();          // "Super Admin name", "storefront:musicdesk (usr_…)", "license server"
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // Paid orders reported by a storefront (it collects payment and invoices).
        Schema::create('software_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('storefront_id')->constrained()->restrictOnDelete();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('software_license_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('kind', 30);                        // new_license | renewal | extra_domains | change_plan | convert_lifetime | updates_renewal
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('payment_provider', 30)->nullable(); // razorpay
            $table->string('payment_reference', 120)->nullable();
            $table->string('invoice_number', 60)->nullable();  // the storefront's own invoice
            $table->string('acting_user', 100)->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->unique(['storefront_id', 'idempotency_key']);
        });

        Schema::create('software_releases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('software_product_id')->constrained()->restrictOnDelete();
            $table->string('version', 20);
            $table->string('channel', 20)->default('stable');
            $table->timestamp('released_at');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->json('requirements')->nullable();
            $table->json('notes')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->unique(['software_product_id', 'version']);
        });

        // Outgoing signed webhooks to storefronts (at-least-once, retried).
        Schema::create('storefront_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();                    // evt_<uuid>
            $table->foreignId('storefront_id')->constrained()->restrictOnDelete();
            $table->string('type', 60)->index();
            $table->json('payload');
            $table->string('status', 20)->index();             // pending | delivered | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        // Idempotency-Key replay store for storefront POSTs (24 h).
        Schema::create('storefront_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storefront_id')->constrained()->restrictOnDelete();
            $table->string('key', 100);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('status_code');
            $table->json('response');
            $table->timestamp('created_at')->useCurrent()->index();

            $table->unique(['storefront_id', 'key']);
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

        foreach (['storefront_idempotency_keys', 'storefront_webhook_events', 'software_releases', 'software_orders',
            'software_license_events', 'software_activations', 'software_licenses', 'software_plan_prices',
            'software_plans', 'software_products', 'storefront_customers', 'storefronts'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('organisations', fn (Blueprint $table) => $table->dropColumn('currency'));
    }
};
