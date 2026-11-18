<?php

namespace Tests\Feature\Zoho;

use App\Modules\Billing\Models\AccountBillingPeriod;
use App\Modules\Billing\Models\CreditNote;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\PricingService;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Enums\BillingStatus;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Zoho\Clients\MockZohoBooksClient;
use App\Modules\Zoho\Models\ZohoMapping;
use App\Modules\Zoho\Services\ZohoSync;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * GST is calculated the way Zoho Books calculates it, and any remaining
 * paise of rounding difference never leave an invoice "partially paid".
 */
class ZohoGstRoundingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-17 10:00:00');
        config(['wcc.billing.timezone' => 'UTC', 'wcc.billing.default_tax_rate' => '18.00', 'wcc.billing.supplier_state_code' => '36']);
    }

    private function zoho(): MockZohoBooksClient
    {
        return app(MockZohoBooksClient::class);
    }

    private function sync(): ZohoSync
    {
        return app(ZohoSync::class);
    }

    /** ₹72 a month, billable 15–31 Oct (17 of 31 days) = ₹39.48: the reported case. */
    private function issueReportedCase(): Invoice
    {
        $admin = $this->superAdmin();
        $organisation = Organisation::factory()->create(['code' => 'SRIYASH', 'gstin' => '36AABCS1234K1Z5']);
        app(PricingService::class)->setOrganisationPrice($organisation, 7200, '2026-01-01', $admin);

        $account = new GoogleAccount(['primary_email' => 'hello@sriyash.test', 'first_name' => 'Hello', 'last_name' => 'Box']);
        $account->forceFill(['organisation_id' => $organisation->id, 'email_unique_key' => 'hello@sriyash.test',
            'account_status' => AccountStatus::Active, 'billing_status' => BillingStatus::Billable, 'billable_from' => '2026-10-15'])->save();
        AccountBillingPeriod::query()->create(['google_account_id' => $account->id, 'organisation_id' => $organisation->id, 'starts_on' => '2026-10-15']);

        $service = app(InvoiceService::class);
        $start = CarbonImmutable::parse('2026-10-01');

        return $service->issue($service->generate($organisation, $start, $start->endOfMonth()->startOfDay(), $admin), $admin)->fresh();
    }

    public function test_the_reported_invoice_now_matches_zoho_to_the_paisa(): void
    {
        $invoice = $this->issueReportedCase();

        $this->assertSame([3948, 355, 355, 0, 710, 4658], [$invoice->subtotal_minor, $invoice->cgst_minor, $invoice->sgst_minor,
            $invoice->igst_minor, $invoice->tax_minor, $invoice->total_minor]);

        $mapping = $this->sync()->mapping($invoice);
        $this->assertSame(ZohoMapping::SYNCED, $mapping->sync_status);
        $this->assertSame(4658, $mapping->zoho_total_minor);

        // Paying Zoho's total settles the invoice here too.
        $this->zoho()->simulatePayment($mapping->zoho_id, '46.58', '2026-11-18');
        $this->sync()->reconcileOrganisation($invoice->organisation);

        $this->assertSame([Invoice::PAID, 0], [$invoice->fresh()->status, $invoice->fresh()->balance_minor]);
        $this->assertSame(0, CreditNote::query()->count());
    }

    public function test_a_rounding_remainder_is_written_off_when_zoho_shows_the_invoice_paid(): void
    {
        $invoice = $this->issueReportedCase();
        $zohoId = $this->sync()->mapping($invoice)->zoho_id;

        // Zoho arrived at one paisa less than we did (as with invoices issued before this fix).
        $this->zoho()->overrideInvoiceTotal($zohoId, '46.57');
        $this->sync()->refreshInvoice($invoice->fresh());
        $this->assertSame(ZohoMapping::SYNCED, $this->sync()->mapping($invoice)->sync_status, 'A rounding difference is not a mismatch.');

        $this->zoho()->simulatePayment($zohoId, '46.57', '2026-11-18');
        $this->sync()->reconcileOrganisation($invoice->organisation);

        $invoice->refresh();
        $this->assertSame([Invoice::PAID, 0, 4657, 1], [$invoice->status, $invoice->balance_minor, $invoice->amount_paid_minor, $invoice->credited_minor]);
        $note = CreditNote::query()->sole();
        $this->assertSame(1, $note->amount_minor);
        $this->assertStringContainsString('rounding', $note->reason);
        $this->assertSame(ZohoMapping::SYNCED, $this->sync()->mapping($invoice)->sync_status, 'Not flagged for a credit note in Zoho.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.rounding_written_off', 'organisation_id' => $invoice->organisation_id]);
        $this->assertSame(4658, $invoice->total_minor, 'The issued invoice itself is never changed.');
    }

    public function test_zoho_total_a_paisa_higher_settles_without_leaving_credit(): void
    {
        $invoice = $this->issueReportedCase();
        $zohoId = $this->sync()->mapping($invoice)->zoho_id;

        $this->zoho()->overrideInvoiceTotal($zohoId, '46.59');
        $this->zoho()->simulatePayment($zohoId, '46.59', '2026-11-18');
        $this->sync()->reconcileOrganisation($invoice->organisation);

        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
        $this->assertSame(0, Payment::query()->sole()->unallocated_minor, 'The extra paisa is rounding, not credit for the next invoice.');
        $this->assertSame(ZohoMapping::SYNCED, $this->sync()->mapping($invoice)->sync_status);
    }

    public function test_real_differences_are_still_flagged_and_never_written_off(): void
    {
        $invoice = $this->issueReportedCase();
        $zohoId = $this->sync()->mapping($invoice)->zoho_id;

        $this->zoho()->overrideInvoiceTotal($zohoId, '40.00');
        $this->zoho()->simulatePayment($zohoId, '40.00', '2026-11-18');
        $this->sync()->reconcileOrganisation($invoice->organisation);

        $this->assertSame(Invoice::PARTIALLY_PAID, $invoice->fresh()->status);
        $this->assertSame(0, CreditNote::query()->count());
        $this->assertSame(ZohoMapping::MISMATCH, $this->sync()->mapping($invoice)->sync_status);
    }
}
