<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\AccountBillingPeriod;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentSuspension;
use App\Modules\Billing\Models\PaymentSuspensionAccount;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\PaymentReminderService;
use App\Modules\Billing\Services\PaymentService;
use App\Modules\Billing\Services\PaymentSuspensionService;
use App\Modules\Billing\Services\PricingService;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Enums\BillingStatus;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Organisations\Models\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleWorkspace\WorkspaceTestCase;

/**
 * Reminders 2 and 1 days before the due date, on it, and 1 and 2 days
 * after; automatic suspension after that; automatic reactivation of
 * exactly the mailboxes it suspended once the invoice is paid.
 */
class PaymentSuspensionTest extends WorkspaceTestCase
{
    private Organisation $organisation;

    private Invoice $invoice;

    /** @var array<string, GoogleAccount> */
    private array $boxes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-02 10:00:00');
        config(['wcc.billing.timezone' => 'UTC', 'wcc.billing.default_due_days' => 6, 'wcc.billing.allow_manual_payments' => true]);
        $admin = $this->superAdmin();

        [$this->organisation] = $this->organisationWithDomain('acme.test');
        app(PricingService::class)->setOrganisationPrice($this->organisation, 10000, '2026-01-01', $admin);

        foreach (['one', 'two'] as $name) {
            $this->boxes[$name] = $this->billable($this->existingAccount($this->organisation, "{$name}@acme.test"));
        }

        // Suspended by hand before anything happens: must never be reactivated by a payment.
        $manual = $this->existingAccount($this->organisation, 'manual@acme.test', AccountStatus::Suspended);
        $manual->forceFill(['suspension_type' => 'manual', 'suspension_reason' => 'Left the company'])->save();
        $this->boxes['manual'] = $this->billable($manual);

        $service = app(InvoiceService::class);
        $start = CarbonImmutable::parse('2026-10-01');
        // Issued "Day 1" (2 Nov), due "Day 7" (8 Nov).
        $this->invoice = $service->issue($service->generate($this->organisation, $start, $start->endOfMonth()->startOfDay(), $admin), $admin)->fresh();
    }

    private function billable(GoogleAccount $account): GoogleAccount
    {
        $account->forceFill(['billing_status' => BillingStatus::Billable, 'billable_from' => '2026-01-01'])->save();
        AccountBillingPeriod::query()->create(['google_account_id' => $account->id, 'organisation_id' => $this->organisation->id, 'starts_on' => '2026-01-01']);

        return $account;
    }

    private function day(int $day): CarbonImmutable
    {
        // Day 1 = the invoice date (2 Nov); the due date is Day 7 (8 Nov).
        return CarbonImmutable::parse('2026-11-02')->addDays($day - 1);
    }

    /** @return list<string> */
    private function remindersSent(): array
    {
        return DB::table('invoice_reminders')->where('invoice_id', $this->invoice->id)->orderBy('id')->pluck('stage')->all();
    }

    public function test_reminder_schedule_from_day_5_to_day_9(): void
    {
        $this->assertSame('2026-11-08', $this->invoice->due_date->toDateString());
        $reminders = app(PaymentReminderService::class);

        $expected = [4 => [], 5 => ['reminder_1'], 6 => ['reminder_2'], 7 => ['final_1'], 8 => ['final_2'], 9 => ['final_3'], 10 => []];
        $sent = [];

        foreach ($expected as $day => $stages) {
            $reminders->run($this->day($day));
            $reminders->run($this->day($day)); // twice: still one reminder per stage
            $sent = array_merge($sent, $stages);
            $this->assertSame($sent, $this->remindersSent(), "Day {$day}");
        }

        $manager = $this->organisationManager($this->organisation);
        $this->assertSame(0, $manager->notifications()->count(), 'Created after the reminders were sent.');

        $body = DB::table('notifications')->where('data', 'like', '%Final reminder: email accounts due for suspension%')->value('data');
        $this->assertNotNull($body);
        $this->assertStringContainsString('will be suspended automatically on 11 Nov 2026', $body);
    }

    public function test_paying_cancels_the_remaining_reminders(): void
    {
        $reminders = app(PaymentReminderService::class);
        $reminders->run($this->day(5));

        app(PaymentService::class)->recordVerified($this->organisation, $this->invoice->total_minor, '2026-11-05', 'offline', invoice: $this->invoice, actor: $this->superAdmin());

        foreach ([6, 7, 8, 9] as $day) {
            $reminders->run($this->day($day));
        }

        $this->assertSame(['reminder_1'], $this->remindersSent());
        $this->assertSame(['suspended' => 0, 'reactivated' => 0], app(PaymentSuspensionService::class)->run($this->day(10)));
    }

    public function test_unpaid_after_two_grace_days_suspends_active_mailboxes_and_payment_reactivates_only_those(): void
    {
        $suspensions = app(PaymentSuspensionService::class);

        // Day 9 (due + 2) is still a grace day.
        $this->assertSame(0, $suspensions->run($this->day(9))['suspended']);

        // Day 10: suspended.
        $this->assertSame(2, $suspensions->run($this->day(10))['suspended']);
        $this->assertSame(0, $suspensions->run($this->day(10))['suspended'], 'Idempotent.');

        foreach (['one', 'two'] as $name) {
            $box = $this->boxes[$name]->fresh();
            $this->assertSame(AccountStatus::Suspended, $box->account_status);
            $this->assertSame('non_payment', $box->suspension_type);
            $this->assertTrue($this->workspace()->rawUser("{$name}@acme.test")['suspended']);
        }

        $suspension = PaymentSuspension::query()->sole();
        $this->assertSame([
            'manual@acme.test' => ['already_suspended', 'suspended', 'manual'],
            'one@acme.test' => ['suspended', 'active', null],
            'two@acme.test' => ['suspended', 'active', null],
        ], $suspension->accounts()->with('account')->get()->mapWithKeys(fn (PaymentSuspensionAccount $row) => [
            $row->account->primary_email => [$row->action, $row->status_before, $row->suspension_type_before],
        ])->sortKeys()->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.accounts_suspended_for_non_payment', 'organisation_id' => $this->organisation->id]);

        // Managers and Operations Admins cannot reactivate a mailbox suspended for non-payment.
        $one = $this->boxes['one']->fresh();
        $this->actingAs($this->organisationManager($this->organisation))->get(route('accounts.show', $one))->assertOk()
            ->assertSee('Suspended for non-payment')->assertDontSee("open-modal', 'reactivate'", false);
        $this->actingAs($this->organisationManager($this->organisation))->post(route('accounts.reactivate', $one))->assertForbidden();
        $this->actingAs($this->operationsAdmin($this->organisation))->post(route('accounts.reactivate', $one))->assertForbidden();
        // …but can still reactivate one they suspended by hand themselves.
        $this->assertTrue($this->organisationManager($this->organisation)->can('reactivate', $this->boxes['manual']->fresh()));

        // Payment confirmed: reactivated automatically (no scheduler needed).
        $this->travelTo('2026-11-12 10:00:00');
        app(PaymentService::class)->recordVerified($this->organisation, $this->invoice->total_minor, '2026-11-12', 'offline', invoice: $this->invoice, actor: $this->superAdmin());

        foreach (['one', 'two'] as $name) {
            $this->assertSame(AccountStatus::Active, $this->boxes[$name]->fresh()->account_status, $name);
            $this->assertNull($this->boxes[$name]->fresh()->suspension_type);
        }

        $manual = $this->boxes['manual']->fresh();
        $this->assertSame(AccountStatus::Suspended, $manual->account_status, 'Manually suspended mailboxes stay suspended.');
        $this->assertSame('manual', $manual->suspension_type);
        $this->assertTrue($this->workspace()->rawUser('manual@acme.test')['suspended']);

        $this->assertSame(PaymentSuspension::LIFTED, $suspension->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.accounts_reactivated_after_payment', 'organisation_id' => $this->organisation->id]);
    }

    public function test_super_admin_can_reactivate_a_payment_suspended_mailbox_by_hand(): void
    {
        app(PaymentSuspensionService::class)->run($this->day(10));
        $one = $this->boxes['one']->fresh();

        $this->actingAs($this->superAdmin())->post(route('accounts.reactivate', $one))->assertSessionHasNoErrors();

        $this->assertSame(AccountStatus::Active, $one->fresh()->account_status);
    }

    public function test_a_rounding_balance_never_suspends(): void
    {
        $this->invoice->forceFill(['amount_paid_minor' => $this->invoice->total_minor - 1])->save();
        app(InvoiceService::class)->settle($this->invoice->fresh());

        $this->assertSame(0, app(PaymentReminderService::class)->run($this->day(7)));
        $this->assertSame(0, app(PaymentSuspensionService::class)->run($this->day(10))['suspended']);
    }

    public function test_another_organisation_is_not_affected(): void
    {
        [$other] = $this->organisationWithDomain('other.test');
        $theirs = $this->existingAccount($other, 'box@other.test');

        app(PaymentSuspensionService::class)->run($this->day(10));

        $this->assertSame(AccountStatus::Active, $theirs->fresh()->account_status);
        $this->assertSame(0, PaymentSuspension::query()->where('organisation_id', $other->id)->count());
    }
}
