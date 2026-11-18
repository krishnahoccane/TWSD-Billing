<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Billing\Models\AccountBillingPeriod;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\BillingPeriodTracker;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\PricingService;
use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Organisations\Models\Organisation;
use Carbon\CarbonImmutable;
use Tests\Feature\GoogleWorkspace\WorkspaceTestCase;

/**
 * Correcting the date a mailbox is billed from. Invoices are calculated from
 * the billing period, so that is what must move (editing billable_from alone
 * changes nothing). "Today" is 18 November 2026.
 */
class BillingStartTest extends WorkspaceTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-18 10:00:00');
        config(['wcc.billing.timezone' => 'UTC', 'wcc.billing.default_tax_rate' => '18.00']);
        $this->admin = $this->superAdmin();
    }

    /** @return array{0: Organisation, 1: OrganisationDomain, 2: GoogleAccount} */
    private function billedMailbox(): array
    {
        [$organisation, $domain] = $this->organisationWithDomain('sriyash.test');
        app(PricingService::class)->setOrganisationPrice($organisation, 31000, '2026-01-01', $this->admin);

        $account = $this->existingAccount($organisation, 'hello@sriyash.test');
        $account->forceFill(['domain_id' => $domain->id, 'google_created_at' => '2025-02-11 09:00:00', 'billable_from' => '2026-11-18'])->save();
        app(BillingPeriodTracker::class)->open($account->fresh()); // assigned today

        return [$organisation, $domain, $account->fresh()];
    }

    private function october(Organisation $organisation): ?Invoice
    {
        return app(InvoiceService::class)->generate($organisation, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), $this->admin);
    }

    public function test_moving_the_billing_start_back_bills_the_earlier_month(): void
    {
        [$organisation, , $account] = $this->billedMailbox();

        // Assigned today: October has nothing to bill.
        $this->assertNull($this->october($organisation));

        $this->actingAs($this->admin)->get(route('accounts.show', $account))->assertOk()->assertSee('Change billing start');

        $this->from(route('accounts.show', $account))
            ->post(route('accounts.billing-start', $account), ['billable_from' => '2026-09-30', 'reason' => 'Mailbox in use since September'])
            ->assertRedirect(route('accounts.show', $account))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'billed from 30 Sep 2026') && str_contains($m, 'October 2026'));

        $this->assertSame('2026-09-30', $account->fresh()->billable_from->toDateString());
        $this->assertSame('2026-09-30', AccountBillingPeriod::query()->where('google_account_id', $account->id)->whereNull('ends_on')->first()->starts_on->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.billing_start_changed', 'organisation_id' => $organisation->id]);
        $this->assertDatabaseHas('google_account_history', ['google_account_id' => $account->id, 'event' => 'billing_start_changed']);

        // Re-running October (the skipped cycle) now produces a full-month line.
        $invoice = $this->october($organisation);
        $this->assertNotNull($invoice);
        $this->assertSame(31000, $invoice->subtotal_minor);
    }

    public function test_invalid_dates_and_issued_invoices_are_refused(): void
    {
        [$organisation, , $account] = $this->billedMailbox();
        $this->actingAs($this->admin)->from(route('accounts.show', $account));
        $post = fn (string $date) => $this->post(route('accounts.billing-start', $account), ['billable_from' => $date, 'reason' => 'Correction']);

        $post('2026-11-25')->assertSessionHasErrorsIn('billing', 'billable_from');   // future
        $post('2025-01-01')->assertSessionHasErrorsIn('billing', 'billable_from');   // before Google created it
        $this->post(route('accounts.billing-start', $account), ['billable_from' => '2026-10-01'])->assertSessionHasErrorsIn('billing', 'reason');

        // An issued invoice covering the dates is never recalculated.
        $post('2026-10-01')->assertSessionHasNoErrors();
        app(InvoiceService::class)->issue($this->october($organisation), $this->admin);
        $post('2026-09-01')->assertSessionHasErrorsIn('billing', 'billable_from');
        $post('2026-11-01')->assertSessionHasErrorsIn('billing', 'billable_from');

        $this->assertSame('2026-10-01', AccountBillingPeriod::query()->where('google_account_id', $account->id)->whereNull('ends_on')->first()->starts_on->toDateString());
    }

    public function test_the_start_cannot_overlap_an_earlier_organisations_period(): void
    {
        [$organisation, , $account] = $this->billedMailbox();
        $other = Organisation::factory()->create();

        // Billed to $other until 9 Nov, then to $organisation from 10 Nov.
        AccountBillingPeriod::query()->where('google_account_id', $account->id)->update(['starts_on' => '2026-11-10']);
        AccountBillingPeriod::query()->create(['google_account_id' => $account->id, 'organisation_id' => $other->id, 'starts_on' => '2026-06-01', 'ends_on' => '2026-11-09']);

        $this->actingAs($this->admin)->from(route('accounts.show', $account))
            ->post(route('accounts.billing-start', $account), ['billable_from' => '2026-11-01', 'reason' => 'Correction'])
            ->assertSessionHasErrorsIn('billing', 'billable_from');
    }

    public function test_organisation_managers_cannot_change_the_billing_start(): void
    {
        [$organisation, , $account] = $this->billedMailbox();

        $this->actingAs($this->organisationManager($organisation))
            ->get(route('accounts.show', $account))->assertOk()->assertDontSee('Change billing start');
        $this->post(route('accounts.billing-start', $account), ['billable_from' => '2026-09-30', 'reason' => 'Cheaper'])->assertForbidden();

        $this->actingAs($this->organisationManager(Organisation::factory()->create()))
            ->post(route('accounts.billing-start', $account), ['billable_from' => '2026-09-30', 'reason' => 'Cheaper'])->assertNotFound();

        $this->assertSame('2026-11-18', $account->fresh()->billable_from->toDateString());
    }

    public function test_organisation_page_shows_billable_count_and_links_domains_to_their_mailboxes(): void
    {
        [$organisation, $domain, $account] = $this->billedMailbox();
        [$otherOrganisation, $otherDomain] = $this->organisationWithDomain('elsewhere.test');
        $this->existingAccount($otherOrganisation, 'someone@elsewhere.test');

        $this->actingAs($this->organisationManager($organisation))
            ->get(route('organisations.show', $organisation))->assertOk()
            ->assertSee('Billable email accounts')
            ->assertSee(route('domains.show', $domain), false);

        $this->get(route('domains.show', $domain))->assertOk()
            ->assertSee('hello@sriyash.test')
            ->assertSee(route('accounts.show', $account), false)
            ->assertDontSee('someone@elsewhere.test');

        // Organisation A cannot open Organisation B's domain or its mailbox list.
        $this->get(route('domains.show', $otherDomain))->assertNotFound();
    }
}
