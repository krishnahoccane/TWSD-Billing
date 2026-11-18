<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Billing\Jobs\CalculateOrganisationInvoiceJob;
use App\Modules\Billing\Jobs\GenerateBillingCycleJob;
use App\Modules\Billing\Models\AccountBillingPeriod;
use App\Modules\Billing\Models\BillingAdjustment;
use App\Modules\Billing\Models\BillingCycle;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Models\OrganisationPrice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\PaymentAllocation;
use App\Modules\Billing\Services\BillingRunner;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\PaymentService;
use App\Modules\Billing\Services\PricingService;
use App\Modules\Billing\Support\GstState;
use App\Modules\Billing\Support\Money;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Enums\BillingStatus;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Services\GoogleAccountService;
use App\Modules\Organisations\Enums\OrganisationStatus;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Models\OrganisationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Feature\GoogleWorkspace\WorkspaceTestCase;

/**
 * All amounts are asserted in paise. The billed month is April 2026
 * (30 days) unless stated; "today" is 17 November 2026.
 */
class BillingEngineTest extends WorkspaceTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-17 10:00:00');
        config(['wcc.billing.default_tax_rate' => '18.00', 'wcc.billing.supplier_state_code' => '36', 'wcc.billing.timezone' => 'UTC']);
        $this->admin = $this->superAdmin();
    }

    private function organisation(?int $priceMinor = 25000, array $attributes = []): Organisation
    {
        $organisation = Organisation::factory()->create($attributes + ['gstin' => '36ABCDE1234F1Z5']);

        if ($priceMinor !== null) {
            app(PricingService::class)->setOrganisationPrice($organisation, $priceMinor, '2026-01-01', $this->admin);
        }

        return $organisation;
    }

    private function mailbox(Organisation $organisation, string $email, string $from = '2026-01-01', ?string $until = null, AccountStatus $status = AccountStatus::Active): GoogleAccount
    {
        $account = new GoogleAccount(['primary_email' => $email, 'first_name' => 'Box', 'last_name' => 'Owner']);
        $account->forceFill([
            'organisation_id' => $organisation->id, 'email_unique_key' => $email, 'account_status' => $status,
            'billing_status' => $until ? BillingStatus::NonBillable : BillingStatus::Billable, 'billable_from' => $from,
        ])->save();

        AccountBillingPeriod::query()->create([
            'google_account_id' => $account->id, 'organisation_id' => $organisation->id, 'starts_on' => $from, 'ends_on' => $until,
        ]);

        return $account;
    }

    private function generate(Organisation $organisation, string $month = '2026-04'): ?Invoice
    {
        $start = CarbonImmutable::parse($month.'-01');

        return app(InvoiceService::class)->generate($organisation, $start, $start->endOfMonth()->startOfDay(), $this->admin);
    }

    private function issued(Organisation $organisation, string $month = '2026-04'): Invoice
    {
        return app(InvoiceService::class)->issue($this->generate($organisation, $month), $this->admin);
    }

    public function test_money_helpers_never_use_floats(): void
    {
        $this->assertSame(25050, Money::parse('250.5'));
        $this->assertSame(123456789, Money::parse('1,234,567.89'));
        $this->assertSame('₹1,234.50', Money::format(123450));
        $this->assertSame('-₹0.05', Money::format(-5));
        $this->assertSame(333, Money::divideRound(10000, 30));       // 333.33 -> 333
        $this->assertSame(1667, Money::divideRound(10000 * 5, 30));  // 1666.67 -> 1667
        $this->assertSame(3, Money::divideRound(5, 2));              // half rounds up
        $this->assertSame(-3, Money::divideRound(-5, 2));            // symmetric for credits
        $this->assertSame(1800, Money::rateToBasisPoints('18'));

        $this->expectException(\InvalidArgumentException::class);
        Money::parse('12.345');
    }

    public function test_fixed_monthly_billing_for_a_full_month(): void
    {
        $organisation = $this->organisation();
        foreach (['a', 'b', 'c'] as $name) {
            $this->mailbox($organisation, "{$name}@acme.test");
        }

        $invoice = $this->generate($organisation);

        $this->assertSame(Invoice::DRAFT, $invoice->status);
        $this->assertNull($invoice->invoice_number);
        $this->assertSame(3, $invoice->items()->count());
        $this->assertSame(75000, $invoice->subtotal_minor);          // 3 × ₹250.00
        $this->assertSame(13500, $invoice->tax_minor);               // 18%
        $this->assertSame(88500, $invoice->total_minor);
        $this->assertSame(88500, $invoice->balance_minor);

        $line = $invoice->items()->first();
        $this->assertSame(['a@acme.test', 30, 30, 25000, 'organisation', 25000, 4500, 29500],
            [$line->account_email, $line->days, $line->days_in_period, $line->unit_price_minor, $line->price_source, $line->amount_minor, $line->tax_minor, $line->total_minor]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.generated', 'organisation_id' => $organisation->id]);
    }

    public function test_price_precedence_account_then_organisation_then_platform(): void
    {
        app(PricingService::class)->setPlatformPrice(20000, '2026-01-01', $this->admin);
        $own = $this->organisation(30000);
        $default = $this->organisation(null);
        $this->mailbox($own, 'normal@own.test');
        $special = $this->mailbox($own, 'special@own.test');
        $this->mailbox($default, 'box@default.test');
        app(PricingService::class)->setAccountOverride($special, 10000, '2026-01-01', 'Founder discount', $this->admin);

        $ownLines = $this->generate($own)->items()->get()->keyBy('account_email');
        $defaultLine = $this->generate($default)->items()->first();

        $this->assertSame([30000, 'organisation'], [$ownLines['normal@own.test']->amount_minor, $ownLines['normal@own.test']->price_source]);
        $this->assertSame([10000, 'account'], [$ownLines['special@own.test']->amount_minor, $ownLines['special@own.test']->price_source]);
        $this->assertSame([20000, 'platform'], [$defaultLine->amount_minor, $defaultLine->price_source]);
        $this->assertDatabaseHas('pricing_history', ['scope' => 'account', 'new_price_minor' => 10000]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'pricing.organisation_price_set', 'organisation_id' => $own->id]);
    }

    public function test_suspended_mailboxes_remain_billable(): void
    {
        $organisation = $this->organisation();
        $this->mailbox($organisation, 'suspended@acme.test', status: AccountStatus::Suspended);

        $invoice = $this->generate($organisation);

        $this->assertSame(25000, $invoice->subtotal_minor);
        $this->assertSame('suspended', $invoice->items()->first()->account_status);
    }

    public function test_real_suspension_and_soft_deletion_drive_the_billable_days(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        app(PricingService::class)->setOrganisationPrice($organisation, 30000, '2026-01-01', $this->admin);
        $service = app(GoogleAccountService::class);

        $this->travelTo('2026-04-01 09:00:00');
        $kept = $this->existingAccount($organisation, 'kept@acme.test');
        $leaver = $this->existingAccount($organisation, 'leaver@acme.test');
        foreach ([$kept, $leaver] as $account) {
            $account->forceFill(['billable_from' => '2026-04-01'])->save();
            AccountBillingPeriod::query()->create(['google_account_id' => $account->id, 'organisation_id' => $organisation->id, 'starts_on' => '2026-04-01']);
        }

        $this->travelTo('2026-04-10 09:00:00');
        $service->requestSuspension($kept, 'On leave', $this->admin);          // stays billable

        $this->travelTo('2026-04-16 09:00:00');
        $service->requestSoftDeletion($leaver->fresh(), 'Resigned', $this->admin); // not billed from the 16th

        $this->travelTo('2026-05-01 09:00:00');
        $lines = $this->generate($organisation)->items()->get()->keyBy('account_email');

        $this->assertSame([30, 30000], [$lines['kept@acme.test']->days, $lines['kept@acme.test']->amount_minor]);
        $this->assertSame('2026-04-15', $lines['leaver@acme.test']->period_end->toDateString());
        $this->assertSame([15, 15000], [$lines['leaver@acme.test']->days, $lines['leaver@acme.test']->amount_minor]);

        // May: the soft-deleted mailbox is no longer charged at all.
        $this->travelTo('2026-06-01 09:00:00');
        $may = $this->generate($organisation, '2026-05');
        $this->assertSame(['kept@acme.test'], $may->items()->pluck('account_email')->all());
    }

    public function test_mid_cycle_additions_and_deletions_are_prorated_by_calendar_day(): void
    {
        $organisation = $this->organisation(25000);
        $this->mailbox($organisation, 'joined@acme.test', '2026-04-21');                 // 10 days
        $this->mailbox($organisation, 'left@acme.test', '2026-01-01', '2026-04-14');     // 14 days
        $this->mailbox($organisation, 'oneday@acme.test', '2026-04-30');                 // 1 day
        $this->mailbox($organisation, 'before@acme.test', '2026-01-01', '2026-03-31');   // not in April
        $this->mailbox($organisation, 'after@acme.test', '2026-05-01');                  // not in April

        $lines = $this->generate($organisation)->items()->get()->keyBy('account_email');

        $this->assertCount(3, $lines);
        $this->assertSame([10, 8333], [$lines['joined@acme.test']->days, $lines['joined@acme.test']->amount_minor]);   // 250×10/30 = 83.333
        $this->assertSame([14, 11667], [$lines['left@acme.test']->days, $lines['left@acme.test']->amount_minor]);      // 250×14/30 = 116.667
        $this->assertSame([1, 833], [$lines['oneday@acme.test']->days, $lines['oneday@acme.test']->amount_minor]);
        $this->assertSame('2026-04-21', $lines['joined@acme.test']->period_start->toDateString());
    }

    public function test_a_price_change_inside_the_month_splits_the_line(): void
    {
        $organisation = $this->organisation(25000);
        app(PricingService::class)->setOrganisationPrice($organisation, 30000, '2026-04-16', $this->admin);
        $this->mailbox($organisation, 'box@acme.test');

        $lines = $this->generate($organisation)->items()->get();

        $this->assertCount(2, $lines);
        $this->assertSame(['2026-04-01', '2026-04-15', 15, 25000, 12500],
            [$lines[0]->period_start->toDateString(), $lines[0]->period_end->toDateString(), $lines[0]->days, $lines[0]->unit_price_minor, $lines[0]->amount_minor]);
        $this->assertSame(['2026-04-16', '2026-04-30', 15, 30000, 15000],
            [$lines[1]->period_start->toDateString(), $lines[1]->period_end->toDateString(), $lines[1]->days, $lines[1]->unit_price_minor, $lines[1]->amount_minor]);
        // The earlier price row was closed, not overwritten.
        $this->assertDatabaseHas('organisation_pricing', ['organisation_id' => $organisation->id, 'monthly_price_minor' => 25000, 'effective_until' => '2026-04-15 00:00:00']);
    }

    public function test_full_month_policy_can_be_configured(): void
    {
        config(['wcc.billing.proration_policy' => 'full_month']);
        $organisation = $this->organisation(25000);
        $this->mailbox($organisation, 'joined@acme.test', '2026-04-21');

        $this->assertSame(25000, $this->generate($organisation)->subtotal_minor);
    }

    public function test_gst_is_split_and_rounded_per_line_like_zoho_books(): void
    {
        config(['wcc.billing.supplier_state_code' => '36']);
        $sameState = $this->organisation(9995, ['gstin' => '36AAAAA0000A1Z5']);
        $otherState = $this->organisation(9995, ['gstin' => '27AAAAA0000A1Z5']);
        $byStateName = $this->organisation(9995, ['gstin' => null, 'state' => 'Maharashtra']);
        foreach (['one', 'two', 'three'] as $name) {
            $this->mailbox($sameState, "{$name}@same.test");
        }
        $this->mailbox($otherState, 'one@other.test');
        $this->mailbox($byStateName, 'one@name.test');

        // Same state: 99.95 × 9% = 8.9955 -> 9.00 CGST + 9.00 SGST per line; three lines = 54.00.
        $a = $this->generate($sameState);
        $this->assertSame(['intra_state', 1800, 5400, 2700, 2700, 0], [$a->tax_treatment, $a->tax_rate_bp, $a->tax_minor, $a->cgst_minor, $a->sgst_minor, $a->igst_minor]);
        $this->assertSame($a->tax_minor, (int) $a->items()->sum('tax_minor'));
        $this->assertSame($a->subtotal_minor + $a->tax_minor, $a->total_minor);

        // Other state: 99.95 × 18% = 17.991 -> 17.99 IGST.
        $b = $this->generate($otherState);
        $this->assertSame(['inter_state', 1799, 0, 0, 1799], [$b->tax_treatment, $b->tax_minor, $b->cgst_minor, $b->sgst_minor, $b->igst_minor]);
        $this->assertSame('inter_state', $this->generate($byStateName)->tax_treatment);

        $this->actingAs($this->admin)->get(route('invoices.show', $a))->assertOk()->assertSee('CGST 9.00%')->assertSee('SGST 9.00%')->assertDontSee('IGST');
        $this->get(route('invoices.show', $b))->assertOk()->assertSee('IGST 18.00%')->assertDontSee('CGST');
    }

    public function test_the_reported_case_matches_zoho_books(): void
    {
        // ₹72 a month, 17 of 31 days = 39.48. Zoho: CGST 3.55 + SGST 3.55 = 46.58 (a single 18% would give 7.11 and 46.59).
        $this->assertSame(['cgst' => 355, 'sgst' => 355, 'igst' => 0, 'tax' => 710], GstState::lineTax(3948, 1800, GstState::INTRA));
        $this->assertSame(711, GstState::lineTax(3948, 1800, GstState::INTER)['tax']);
        $this->assertSame('36', GstState::code(null, 'telangana'));
        $this->assertSame('07', GstState::code(null, 'New Delhi'));
        $this->assertSame('27', GstState::code('27AAAAA0000A1Z5', 'Telangana'));
        $this->assertSame(GstState::INTRA, GstState::treatment(null, '27'));       // supplier state not configured
        $this->assertSame(GstState::INTRA, GstState::treatment('36', null));       // customer state unknown
        $this->assertSame(GstState::INTER, GstState::treatment('36', null, 'AE')); // outside India
    }

    public function test_the_gst_rate_is_the_same_for_every_organisation(): void
    {
        $organisation = $this->organisation(10000);
        $this->mailbox($organisation, 'box@acme.test');
        // A leftover per-organisation setting is ignored.
        (new OrganisationSetting)->forceFill(['organisation_id' => $organisation->id, 'key' => 'billing.tax_rate', 'value' => '5'])->save();

        $invoice = $this->generate($organisation);
        $this->assertSame([1800, 1800, 11800], [$invoice->tax_rate_bp, $invoice->tax_minor, $invoice->total_minor]);

        $this->actingAs($this->admin)->get(route('pricing.organisations.show', $organisation))->assertOk()->assertSee('GST')->assertDontSee('Rate for this organisation');
    }

    public function test_only_one_invoice_can_exist_per_organisation_and_period(): void
    {
        $organisation = $this->organisation();
        $this->mailbox($organisation, 'box@acme.test');

        $first = $this->generate($organisation);
        $second = $this->generate($organisation);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(1, BillingCycle::query()->count());
        $this->assertSame(1, InvoiceItem::query()->count());

        // Retried / duplicated jobs do not create a second invoice either.
        CalculateOrganisationInvoiceJob::dispatch($organisation->id, '2026-04-01', '2026-04-30');
        CalculateOrganisationInvoiceJob::dispatch($organisation->id, '2026-04-01', '2026-04-30');
        $this->assertSame(1, Invoice::query()->count());

        // And the database itself refuses duplicates.
        try {
            BillingCycle::query()->create(['organisation_id' => $organisation->id, 'period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'status' => 'open']);
            $this->fail('A duplicate billing cycle must be rejected by the database.');
        } catch (QueryException) {
            $this->assertSame(1, BillingCycle::query()->count());
        }

        $this->expectException(QueryException::class);
        Invoice::query()->create(['organisation_id' => $organisation->id, 'billing_cycle_id' => $first->billing_cycle_id,
            'status' => 'draft', 'currency' => 'INR', 'period_start' => '2026-04-01', 'period_end' => '2026-04-30']);
    }

    public function test_an_issued_invoice_is_an_immutable_snapshot(): void
    {
        $organisation = $this->organisation(25000, ['name' => 'Original Name']);
        $account = $this->mailbox($organisation, 'box@acme.test');
        $invoice = $this->issued($organisation);
        $before = $invoice->fresh()->only(['subtotal_minor', 'tax_minor', 'total_minor', 'invoice_number', 'organisation_snapshot']);

        // Everything around the invoice changes afterwards.
        app(PricingService::class)->setOrganisationPrice($organisation, 99900, '2026-05-01', $this->admin);
        $organisation->forceFill(['name' => 'Renamed Ltd', 'gstin' => '27ZZZZZ9999Z1Z5'])->save();
        $account->forceFill(['primary_email' => 'renamed@acme.test', 'first_name' => 'Changed'])->save();
        (new OrganisationSetting)->forceFill(['organisation_id' => $organisation->id, 'key' => 'billing.tax_rate', 'value' => '28'])->save();

        $after = $invoice->fresh();
        $this->assertSame($before, $after->only(array_keys($before)));
        $this->assertSame('Original Name', $after->organisation_snapshot['name']);
        $line = $after->items()->first();
        $this->assertSame(['box@acme.test', 'Box Owner', 25000, 25000], [$line->account_email, $line->account_name, $line->unit_price_minor, $line->amount_minor]);

        // The model refuses to change frozen fields or lines, and to recalculate.
        foreach ([fn () => $after->update(['total_minor' => 1]), fn () => $after->update(['subtotal_minor' => 1]),
            fn () => $line->update(['amount_minor' => 1]), fn () => $line->delete(), fn () => $after->delete(),
            fn () => InvoiceItem::query()->create(['invoice_id' => $after->id, 'type' => 'mailbox', 'description' => 'x', 'amount_minor' => 1, 'total_minor' => 1]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An issued invoice must be immutable.');
            } catch (LogicException) {
                // expected
            }
        }

        try {
            app(InvoiceService::class)->recalculate($after, $this->admin);
            $this->fail('An issued invoice cannot be recalculated.');
        } catch (ValidationException) {
            $this->assertSame(29500, $invoice->fresh()->total_minor);
        }
    }

    public function test_a_price_cannot_be_backdated_into_an_invoiced_period(): void
    {
        $organisation = $this->organisation(25000);
        $account = $this->mailbox($organisation, 'box@acme.test');
        $this->issued($organisation);
        $pricing = app(PricingService::class);

        foreach ([fn () => $pricing->setOrganisationPrice($organisation, 100, '2026-04-10', $this->admin),
            fn () => $pricing->setAccountOverride($account, 100, '2026-04-30', null, $this->admin)] as $attempt) {
            try {
                $attempt();
                $this->fail('A price must not start inside an invoiced period.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('effective_from', $e->errors());
            }
        }

        $pricing->setOrganisationPrice($organisation, 30000, '2026-05-01', $this->admin);
        $this->assertSame(30000, $this->generate($organisation, '2026-05')->subtotal_minor);
    }

    public function test_invoice_numbers_are_consecutive_and_drafts_can_be_recalculated(): void
    {
        $organisation = $this->organisation(25000);
        $this->mailbox($organisation, 'box@acme.test');
        $other = $this->organisation(25000);
        $this->mailbox($other, 'box@other.test');

        $draft = $this->generate($organisation);
        $this->mailbox($organisation, 'late@acme.test');             // discovered before issue
        $draft = app(InvoiceService::class)->recalculate($draft, $this->admin);
        $this->assertSame(50000, $draft->subtotal_minor);

        $first = app(InvoiceService::class)->issue($draft, $this->admin);
        $second = $this->issued($other);
        $third = $this->issued($organisation, '2026-05');

        $this->assertSame(['26/TWSD-001', '26/TWSD-002', '26/TWSD-003'], [$first->invoice_number, $second->invoice_number, $third->invoice_number]);
        $this->assertSame(Invoice::ISSUED, $first->status);
        $this->assertSame('2026-11-17', $first->issue_date->toDateString());
        $this->assertSame('2026-12-02', $first->due_date->toDateString());   // +15 days
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.issued', 'user_id' => $this->admin->id]);

        $this->expectException(ValidationException::class);
        app(InvoiceService::class)->issue($first->fresh(), $this->admin);
    }

    public function test_invoice_numbering_restarts_each_year_and_follows_the_configured_format(): void
    {
        $organisation = $this->organisation(25000);
        $this->mailbox($organisation, 'box@acme.test');

        $this->assertSame('26/TWSD-001', $this->issued($organisation, '2026-04')->invoice_number);
        $this->assertSame('26/TWSD-002', $this->issued($organisation, '2026-05')->invoice_number);

        $this->travelTo('2027-01-05 10:00:00');
        $this->assertSame('27/TWSD-001', $this->issued($organisation, '2026-12')->invoice_number);

        config(['wcc.billing.invoice_number_format' => 'TWSD/{YYYY}/{SEQ}', 'wcc.billing.invoice_number_padding' => 4]);
        $this->assertSame('TWSD/2027/0001', $this->issued($organisation, '2026-11')->invoice_number);
    }

    public function test_partial_payment_then_full_payment(): void
    {
        $organisation = $this->organisation(25000);
        $this->mailbox($organisation, 'box@acme.test');
        $invoice = $this->issued($organisation);                       // ₹295.00
        $payments = app(PaymentService::class);

        $payments->recordVerified($organisation, 10000, '2026-11-18', 'zoho', 'zoho-pay-1', ['reference' => 'UTR1']);
        $invoice->refresh();
        $this->assertSame([Invoice::PARTIALLY_PAID, 10000, 19500], [$invoice->status, $invoice->amount_paid_minor, $invoice->balance_minor]);

        $payments->recordVerified($organisation, 19500, '2026-11-19', 'zoho', 'zoho-pay-2');
        $invoice->refresh();
        $this->assertSame([Invoice::PAID, 29500, 0], [$invoice->status, $invoice->amount_paid_minor, $invoice->balance_minor]);
        $this->assertSame(29500, $invoice->total_minor);               // the invoice itself never changed
        $this->assertSame(2, $invoice->allocations()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.recorded', 'organisation_id' => $organisation->id]);
    }

    public function test_overpayment_becomes_credit_that_is_applied_to_the_next_invoice(): void
    {
        $organisation = $this->organisation(25000);
        $this->mailbox($organisation, 'box@acme.test');
        $invoice = $this->issued($organisation);                       // ₹295.00
        $payments = app(PaymentService::class);

        $payment = $payments->recordVerified($organisation, 40000, '2026-11-18', 'zoho', 'zoho-over');

        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
        $this->assertSame(29500, $invoice->fresh()->amount_paid_minor);   // never more than the invoice total
        $this->assertSame(10500, $payment->fresh()->unallocated_minor);

        $next = $this->issued($organisation, '2026-05');
        $payments->applyCredit($organisation);

        $this->assertSame([Invoice::PARTIALLY_PAID, 10500, 19000], [$next->fresh()->status, $next->fresh()->amount_paid_minor, $next->fresh()->balance_minor]);
        $this->assertSame(0, $payment->fresh()->unallocated_minor);
    }

    public function test_payment_reconciliation_is_idempotent_and_allocates_oldest_first(): void
    {
        $organisation = $this->organisation(25000);
        $this->mailbox($organisation, 'box@acme.test');
        $april = $this->issued($organisation, '2026-04');
        $this->travelTo('2026-11-20 10:00:00');
        $may = $this->issued($organisation, '2026-05');
        $payments = app(PaymentService::class);

        // The same Zoho payment arrives by webhook and again by reconciliation.
        $one = $payments->recordVerified($organisation, 40000, '2026-11-21', 'zoho', 'zoho-777');
        $two = $payments->recordVerified($organisation, 40000, '2026-11-21', 'zoho', 'zoho-777');

        $this->assertTrue($one->is($two));
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame([Invoice::PAID, 0], [$april->fresh()->status, $april->fresh()->balance_minor]);            // oldest due first
        $this->assertSame([Invoice::PARTIALLY_PAID, 19000], [$may->fresh()->status, $may->fresh()->balance_minor]);
        $this->assertSame(40000, (int) PaymentAllocation::query()->sum('amount_minor'));

        // A payment for one organisation can never settle another organisation's invoice.
        $other = $this->organisation(25000);
        $this->expectException(ValidationException::class);
        $payments->recordVerified($other, 1000, '2026-11-21', 'zoho', 'zoho-x', invoice: $may);
    }

    public function test_super_admins_record_verified_offline_payments(): void
    {
        $organisation = $this->organisation(25000);
        $this->mailbox($organisation, 'box@acme.test');
        $invoice = $this->issued($organisation);                       // ₹295.00
        $this->actingAs($this->admin);

        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('Record an offline payment');

        $this->post(route('invoices.payments.store', $invoice), ['amount' => '500', 'paid_on' => '2026-11-17', 'reference' => 'UTR1', 'method' => 'banktransfer'])
            ->assertSessionHasErrorsIn('payment', 'amount');             // more than the balance
        $this->post(route('invoices.payments.store', $invoice), ['amount' => '100', 'paid_on' => '2099-01-01', 'reference' => 'UTR1', 'method' => 'banktransfer'])
            ->assertSessionHasErrorsIn('payment', 'paid_on');            // in the future
        $this->post(route('invoices.payments.store', $invoice), ['amount' => '100', 'paid_on' => '2026-11-17', 'reference' => 'UTR1', 'method' => 'bitcoin'])
            ->assertSessionHasErrorsIn('payment', 'method');

        $this->post(route('invoices.payments.store', $invoice), ['amount' => '295.00', 'paid_on' => '2026-11-17', 'reference' => 'UTR9', 'method' => 'banktransfer'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
        $this->assertDatabaseHas('payments', ['source' => 'offline', 'recorded_by' => $this->admin->id, 'reference' => 'UTR9', 'method' => 'banktransfer']);

        // Managers and billing managers cannot mark anything paid.
        $open = $this->issued($organisation, '2026-05');
        foreach ([$this->billingManager($organisation), $this->organisationManager($organisation)] as $user) {
            $this->actingAs($user)
                ->post(route('invoices.payments.store', $open), ['amount' => '1', 'paid_on' => '2026-11-17', 'reference' => 'FAKE', 'method' => 'cash'])->assertForbidden();
            $this->get(route('invoices.show', $open))->assertOk()->assertDontSee('Record an offline payment');
        }
        $this->assertSame(Invoice::ISSUED, $open->fresh()->status);

        // The switch still exists for organisations that want Zoho-only payments.
        config(['wcc.billing.allow_manual_payments' => false]);
        $this->actingAs($this->admin)
            ->post(route('invoices.payments.store', $open), ['amount' => '1', 'paid_on' => '2026-11-17', 'reference' => 'X', 'method' => 'cash'])->assertNotFound();
    }

    public function test_adjustments_credit_notes_and_voiding(): void
    {
        $organisation = $this->organisation(25000);
        $this->mailbox($organisation, 'box@acme.test');
        $this->actingAs($this->admin);

        $this->post(route('pricing.organisations.adjustments.store', $organisation), ['type' => 'credit', 'amount' => '50', 'description' => 'Outage goodwill', 'taxable' => 1])->assertSessionHasNoErrors();
        $this->post(route('pricing.organisations.adjustments.store', $organisation), ['type' => 'debit', 'amount' => '20.50', 'description' => 'Setup fee'])->assertSessionHasNoErrors();
        $this->post(route('pricing.organisations.adjustments.store', $organisation), ['type' => 'credit', 'amount' => '9999', 'description' => 'Too large'])->assertSessionHasNoErrors();

        $invoice = $this->generate($organisation);
        // 250.00 − 50.00 (taxable) + 20.50 (not taxable); the oversized credit waits.
        $this->assertSame([25000, -2950, 22050], [$invoice->subtotal_minor, $invoice->adjustments_minor, $invoice->taxable_minor]);
        $this->assertSame(4500 - 900, $invoice->tax_minor);
        $this->assertSame(25650, $invoice->total_minor);
        $this->assertSame(2, BillingAdjustment::query()->where('status', 'applied')->where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, BillingAdjustment::query()->where('status', 'approved')->whereNull('invoice_id')->count());

        $this->post(route('invoices.issue', $invoice))->assertSessionHasNoErrors();

        // Credit note: reduces what is owed, never the invoice.
        $this->post(route('invoices.credit-notes.store', $invoice), ['amount' => '99999', 'reason' => 'Too much'])->assertSessionHasErrors('amount');
        $this->post(route('invoices.credit-notes.store', $invoice), ['amount' => '56.50', 'reason' => 'Billing error'])->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame([25650, 5650, 20000, Invoice::PARTIALLY_PAID], [$invoice->total_minor, $invoice->credited_minor, $invoice->balance_minor, $invoice->status]);
        $this->assertDatabaseHas('credit_notes', ['invoice_id' => $invoice->id, 'credit_note_number' => 'CN-000001', 'amount_minor' => 5650]);

        // An invoice with credits or payments cannot be voided.
        $this->post(route('invoices.void', $invoice), ['reason' => 'Mistake'])->assertSessionHasErrors('invoice');

        $may = $this->issued($organisation, '2026-05');
        $this->post(route('invoices.void', $may), [])->assertSessionHasErrors('reason');
        $this->post(route('invoices.void', $may), ['reason' => 'Raised in error'])->assertSessionHasNoErrors();
        $this->assertSame([Invoice::VOID, 0], [$may->fresh()->status, $may->fresh()->balance_minor]);
        $this->assertNotNull($may->fresh()->invoice_number);          // the number is kept
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.voided', 'user_id' => $this->admin->id]);
    }

    public function test_billing_run_covers_active_and_suspended_organisations_and_isolates_failures(): void
    {
        $active = $this->organisation(25000);
        $suspended = $this->organisation(25000, ['status' => OrganisationStatus::Active]);
        $suspended->forceFill(['status' => OrganisationStatus::Suspended])->save();
        $inactive = $this->organisation(25000);
        $inactive->forceFill(['status' => OrganisationStatus::Inactive])->save();
        $unpriced = $this->organisation(null);
        $empty = $this->organisation(25000);
        foreach ([[$active, 'a'], [$suspended, 's'], [$inactive, 'i'], [$unpriced, 'u']] as [$organisation, $name]) {
            $this->mailbox($organisation, "{$name}@acme.test");
        }

        $summary = app(BillingRunner::class)->run(CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-04-30'), $this->admin);

        $this->assertSame(['generated' => 2, 'skipped' => 1, 'failed' => 1], $summary);
        $this->assertSame(1, Invoice::query()->where('organisation_id', $active->id)->count());
        $this->assertSame(1, Invoice::query()->where('organisation_id', $suspended->id)->count());
        $this->assertSame(0, Invoice::query()->where('organisation_id', $inactive->id)->count());
        $this->assertDatabaseHas('billing_cycles', ['organisation_id' => $empty->id, 'status' => 'skipped']);

        $failed = BillingCycle::query()->where('organisation_id', $unpriced->id)->firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('No price is configured for u@acme.test', $failed->error);

        // Fix the price and run again: only the failed organisation gets a new invoice.
        app(PricingService::class)->setPlatformPrice(20000, '2026-01-01', $this->admin);
        $again = app(BillingRunner::class)->run(CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-04-30'), $this->admin);
        $this->assertSame(3, $again['generated']);
        $this->assertSame(3, Invoice::query()->count());

    }

    public function test_moving_a_mailbox_splits_the_charge_between_organisations(): void
    {
        $from = $this->organisation(30000);
        $to = $this->organisation(30000);
        $this->travelTo('2026-04-01 09:00:00');
        $account = $this->mailbox($from, 'mover@acme.test', '2026-04-01');

        $this->travelTo('2026-04-11 09:00:00');
        app(GoogleAccountService::class)->assignOrganisation($account, $to, $this->admin);

        $this->travelTo('2026-05-02 09:00:00');
        $this->assertSame([10, 10000], [$this->generate($from)->items()->first()->days, $this->generate($from)->subtotal_minor]);
        $this->assertSame([20, 20000], [$this->generate($to)->items()->first()->days, $this->generate($to)->subtotal_minor]);
    }

    public function test_billing_screens_are_role_restricted_and_tenant_isolated(): void
    {
        $a = $this->organisation(25000);
        $b = $this->organisation(25000);
        $this->mailbox($a, 'box@a.test');
        $this->mailbox($b, 'box@b.test');
        $mine = $this->issued($a);
        $theirs = $this->issued($b);
        $draft = $this->generate($a, '2026-05');
        app(PaymentService::class)->recordVerified($b, 5000, '2026-11-18', 'zoho', 'pay-b', ['reference' => 'SECRET-B-REF']);
        $manager = $this->organisationManager($a);
        $billing = $this->billingManager($a);

        foreach ([$manager, $billing] as $user) {
            $this->actingAs($user);
            $this->get('/billing')->assertOk();
            $this->get('/invoices')->assertOk()->assertSee($mine->invoice_number)->assertDontSee($theirs->invoice_number)->assertDontSee('>Draft</a>', false);
            $this->get(route('invoices.show', $mine))->assertOk()->assertSee('box@a.test')->assertDontSee('Issue credit note');
            $this->get(route('invoices.show', $theirs))->assertNotFound();      // another organisation
            $this->get(route('invoices.show', $draft))->assertNotFound();       // drafts are internal
            $this->get('/invoices/'.$theirs->id)->assertNotFound();
            $this->get('/payments')->assertOk()->assertDontSee('SECRET-B-REF');
            $this->get('/payments/outstanding')->assertOk()->assertSee($mine->invoice_number)->assertDontSee($theirs->invoice_number);

            // No pricing, cycle, adjustment or invoice management.
            $this->get('/pricing')->assertForbidden();
            $this->get(route('pricing.organisations.show', $a))->assertForbidden();
            $this->post(route('pricing.organisations.price', $a), ['price' => '1', 'effective_from' => '2026-12-01'])->assertForbidden();
            $this->post(route('pricing.organisations.price', $b), ['price' => '1', 'effective_from' => '2026-12-01'])->assertNotFound();
            $this->post(route('pricing.platform.store'), ['price' => '1', 'effective_from' => '2026-12-01'])->assertForbidden();
            $this->post(route('pricing.organisations.adjustments.store', $a), ['type' => 'credit', 'amount' => '100', 'description' => 'Free money'])->assertForbidden();
            $this->get('/billing/cycles')->assertForbidden();
            $this->post('/billing/cycles', ['month' => '2026-10'])->assertForbidden();
            $this->post(route('invoices.void', $mine), ['reason' => 'nope'])->assertForbidden();
            $this->post(route('invoices.credit-notes.store', $mine), ['amount' => '295', 'reason' => 'nope'])->assertForbidden();
            $this->post(route('invoices.issue', $mine))->assertForbidden();
        }

        $this->assertSame(Invoice::ISSUED, $mine->fresh()->status);
        $this->assertSame(29500, $mine->fresh()->balance_minor);
        $this->assertDatabaseCount('billing_adjustments', 0);
        $this->assertSame(1, OrganisationPrice::query()->where('organisation_id', $a->id)->count());

        // A user with no billing role at all.
        $this->actingAs(User::factory()->create());
        $this->get('/billing')->assertForbidden();
        $this->get('/invoices')->assertForbidden();
        $this->get('/payments')->assertForbidden();
    }

    public function test_super_admin_screens_and_cycle_runs(): void
    {
        $organisation = $this->organisation(null);
        $this->mailbox($organisation, 'box@acme.test');
        $this->actingAs($this->admin);

        $this->get('/pricing')->assertOk();
        $this->post(route('pricing.platform.store'), ['price' => 'abc', 'effective_from' => '2026-01-01'])->assertSessionHasErrorsIn('platform', 'price');
        $this->post(route('pricing.platform.store'), ['price' => '199.99', 'effective_from' => '2026-01-01'])->assertSessionHasNoErrors();
        $this->post(route('pricing.organisations.price', $organisation), ['price' => '275', 'effective_from' => '2026-02-01'])->assertSessionHasNoErrors();
        $this->post(route('pricing.organisations.overrides.store', $organisation), ['email' => 'nobody@else.test', 'price' => '1', 'effective_from' => '2026-02-01'])
            ->assertSessionHasErrorsIn('override', 'email');
        $this->post(route('pricing.organisations.overrides.store', $organisation), ['email' => 'box@acme.test', 'price' => '100', 'effective_from' => '2026-03-01', 'reason' => 'Promo'])
            ->assertSessionHasNoErrors();
        $this->get(route('pricing.organisations.show', $organisation))->assertOk()->assertSee('box@acme.test')->assertSee('each rounded per line');

        // Only a completed month can be billed.
        $this->post('/billing/cycles', ['month' => '2026-11'])->assertSessionHasErrors('month');
        $this->post('/billing/cycles', ['month' => '2027-01'])->assertSessionHasErrors('month');

        Queue::fake();
        $this->post('/billing/cycles', ['month' => '2026-10'])->assertSessionHas('success');
        Queue::assertPushedOn('billing', GenerateBillingCycleJob::class, fn ($job) => $job->periodStart === '2026-10-01' && $job->periodEnd === '2026-10-31');
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.cycle_requested', 'user_id' => $this->admin->id]);

        // The scheduled job (no dates) bills the previous calendar month.
        (new GenerateBillingCycleJob)->handle(app(BillingRunner::class));
        Queue::assertPushed(CalculateOrganisationInvoiceJob::class, fn ($job) => $job->periodStart === '2026-10-01' && $job->organisationId === $organisation->id);

        $invoice = $this->generate($organisation, '2026-10');
        $this->assertSame(10000, $invoice->subtotal_minor);             // the mailbox override wins
        $this->get('/billing/cycles')->assertOk()->assertSee('Draft');
        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('mailbox price')->assertSee('Issue…');
        $this->get('/billing')->assertOk()->assertSee('₹100.00');       // monthly recurring revenue
    }

    public function test_billing_api_is_read_only_scoped_and_uses_decimal_strings(): void
    {
        $a = $this->organisation(25000);
        $b = $this->organisation(25000);
        $this->mailbox($a, 'box@a.test');
        $this->mailbox($b, 'box@b.test');
        $mine = $this->issued($a);
        $theirs = $this->issued($b);
        $draft = $this->generate($a, '2026-05');
        app(PaymentService::class)->recordVerified($a, 10000, '2026-11-18', 'zoho', 'pay-a');
        app(PaymentService::class)->recordVerified($b, 10000, '2026-11-18', 'zoho', 'pay-b');

        $this->getJson('/api/v1/invoices')->assertUnauthorized();
        Sanctum::actingAs($this->billingManager($a));

        $this->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->uuid)->assertJsonPath('data.0.total', '295.00')->assertJsonPath('data.0.balance', '195.00');
        $this->getJson('/api/v1/invoices/'.$mine->uuid)->assertOk()
            ->assertJsonPath('data.items.0.account_email', 'box@a.test')->assertJsonPath('data.items.0.unit_price', '250.00')
            ->assertJsonPath('data.tax_treatment', 'intra_state')->assertJsonPath('data.tax_rate_percent', '18.00')
            ->assertJsonPath('data.cgst', '22.50')->assertJsonPath('data.sgst', '22.50')->assertJsonPath('data.igst', '0.00');
        $this->getJson('/api/v1/invoices/'.$theirs->uuid)->assertNotFound();
        $this->getJson('/api/v1/invoices/'.$draft->uuid)->assertNotFound();
        $this->getJson('/api/v1/payments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', '100.00');
        $this->getJson('/api/v1/billing/pricing')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.monthly_price', '250.00');
        $this->getJson('/api/v1/billing/summary')->assertOk()
            ->assertJsonPath('data.monthly_recurring_revenue', '250.00')->assertJsonPath('data.billable_accounts', 1)
            ->assertJsonPath('data.invoiced', '295.00')->assertJsonPath('data.outstanding', '195.00');

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/billing/summary')->assertJsonPath('data.monthly_recurring_revenue', '500.00');
    }

    public function test_overdue_is_derived_from_the_due_date(): void
    {
        $organisation = $this->organisation(25000);
        $this->mailbox($organisation, 'box@acme.test');
        $invoice = $this->issued($organisation);

        $this->assertFalse($invoice->isOverdue());
        $this->travelTo('2026-12-03 10:00:00');                       // the day after the due date
        $this->assertTrue($invoice->fresh()->isOverdue());
        $this->assertSame('overdue', $invoice->fresh()->displayStatus());
        $this->actingAs($this->admin)->get('/invoices?status=overdue')->assertOk()->assertSee($invoice->invoice_number);
        $this->assertSame(29500, app(BillingRunner::class)->overview($this->admin)['overdue_minor']);

        app(PaymentService::class)->recordVerified($organisation, 29500, '2026-12-03', 'zoho', 'late-pay');
        $this->assertFalse($invoice->fresh()->isOverdue());
    }
}
