<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing engine. All money is stored as integer minor units (paise) in
 * *_minor columns — never as floats. Tax rates are basis points
 * (1800 = 18.00%). No table here cascades on delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Platform default prices (lowest precedence), effective-dated.
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('service_type', 50)->default('google_workspace_email');
            $table->string('billing_model', 30)->default('fixed_monthly');
            $table->unsignedBigInteger('monthly_price_minor');
            $table->char('currency', 3);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['service_type', 'effective_from']);
        });

        Schema::create('organisation_pricing', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('service_type', 50)->default('google_workspace_email');
            $table->string('billing_model', 30)->default('fixed_monthly');
            $table->unsignedBigInteger('monthly_price_minor');
            $table->char('currency', 3);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['organisation_id', 'service_type', 'effective_from'], 'org_pricing_lookup');
        });

        Schema::create('account_pricing_overrides', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('google_account_id')->constrained('google_accounts')->restrictOnDelete();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('monthly_price_minor');
            $table->char('currency', 3);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['google_account_id', 'effective_from']);
        });

        Schema::create('pricing_history', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20);                     // platform | organisation | account
            $table->foreignId('organisation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('google_account_id')->nullable()->constrained('google_accounts')->restrictOnDelete();
            $table->string('action', 20);
            $table->unsignedBigInteger('old_price_minor')->nullable();
            $table->unsignedBigInteger('new_price_minor')->nullable();
            $table->char('currency', 3);
            $table->date('effective_from')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // The days on which a mailbox is chargeable to an organisation.
        Schema::create('account_billing_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_account_id')->constrained('google_accounts')->restrictOnDelete();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();             // inclusive; null = still billable
            $table->timestamps();

            $table->index(['organisation_id', 'starts_on', 'ends_on'], 'billing_periods_lookup');
            $table->index(['google_account_id', 'ends_on']);
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->string('name', 50)->primary();
            $table->string('prefix', 20);
            $table->unsignedInteger('padding')->default(6);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });

        Schema::create('billing_cycles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->index();           // open | invoiced | skipped | failed
            $table->text('error')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            // One billing cycle per organisation per period.
            $table->unique(['organisation_id', 'period_start', 'period_end'], 'billing_cycle_unique');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            // One invoice per billing cycle (so one per organisation per period).
            $table->foreignId('billing_cycle_id')->unique()->constrained()->restrictOnDelete();
            $table->string('invoice_number', 32)->nullable()->unique();   // assigned when issued
            $table->string('status', 20)->index();           // draft | issued | partially_paid | paid | void
            $table->char('currency', 3);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->bigInteger('subtotal_minor')->default(0);       // mailbox charges
            $table->bigInteger('adjustments_minor')->default(0);    // credits (-) and debits (+)
            $table->bigInteger('taxable_minor')->default(0);
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->string('tax_treatment', 20)->default('unspecified'); // intra_state | inter_state | unspecified
            $table->bigInteger('cgst_minor')->default(0);
            $table->bigInteger('sgst_minor')->default(0);
            $table->bigInteger('igst_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('amount_paid_minor')->default(0);
            $table->bigInteger('credited_minor')->default(0);
            $table->bigInteger('balance_minor')->default(0);
            $table->json('organisation_snapshot')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['organisation_id', 'status']);
            $table->index('due_date');
        });

        Schema::create('billing_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('type', 10);                      // credit | debit
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('description', 255);
            $table->boolean('taxable')->default(true);
            $table->string('status', 20)->index();           // pending | approved | applied | cancelled
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);                      // mailbox | adjustment
            $table->foreignId('google_account_id')->nullable()->constrained('google_accounts')->restrictOnDelete();
            $table->foreignId('billing_adjustment_id')->nullable()->constrained()->restrictOnDelete();
            // Snapshot: what was charged, for whom, at what price.
            $table->string('description', 255);
            $table->string('account_email', 254)->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_status', 32)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedSmallInteger('days')->nullable();
            $table->unsignedSmallInteger('days_in_period')->nullable();
            $table->unsignedBigInteger('unit_price_minor')->nullable();
            $table->string('price_source', 20)->nullable();  // account | organisation | platform
            $table->bigInteger('amount_minor');
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['invoice_id', 'sort']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->bigInteger('unallocated_minor')->default(0);
            $table->char('currency', 3);
            $table->string('method', 30)->nullable();
            $table->string('reference', 100)->nullable();
            $table->date('paid_on');
            $table->string('source', 20);                    // zoho | manual
            // Idempotency key from the authoritative system (Zoho payment id).
            $table->string('external_id', 100)->nullable()->unique();
            $table->string('status', 20)->default('verified');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'paid_on']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['payment_id', 'invoice_id']);
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('credit_note_number', 32)->unique();
            $table->unsignedBigInteger('amount_minor');      // tax inclusive
            $table->char('currency', 3);
            $table->string('reason', 500);
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->timestamps();
        });

        // Mailboxes that already exist become billable periods from the
        // dates Phase C recorded for them.
        $now = now();

        foreach (DB::table('google_accounts')->whereNotNull('organisation_id')->whereNotNull('billable_from')->orderBy('id')->cursor() as $account) {
            $endsOn = $account->deletion_effective_date
                ? date('Y-m-d', strtotime($account->deletion_effective_date.' -1 day'))
                : null;

            if ($endsOn !== null && $endsOn < substr((string) $account->billable_from, 0, 10)) {
                continue;
            }

            DB::table('account_billing_periods')->insert([
                'google_account_id' => $account->id,
                'organisation_id' => $account->organisation_id,
                'starts_on' => substr((string) $account->billable_from, 0, 10),
                'ends_on' => $endsOn,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach ([
            'credit_notes', 'payment_allocations', 'payments', 'invoice_items', 'billing_adjustments', 'invoices',
            'billing_cycles', 'number_sequences', 'account_billing_periods', 'pricing_history',
            'account_pricing_overrides', 'organisation_pricing', 'pricing_plans',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
