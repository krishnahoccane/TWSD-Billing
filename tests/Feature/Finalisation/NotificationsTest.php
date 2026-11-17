<?php

namespace Tests\Feature\Finalisation;

use App\Models\User;
use App\Modules\Billing\Jobs\SendOverdueInvoiceRemindersJob;
use App\Modules\Billing\Models\AccountBillingPeriod;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\PaymentService;
use App\Modules\Billing\Services\PricingService;
use App\Modules\Domains\Models\DomainRequest;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Enums\BillingStatus;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Notifications\AppNotification;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\NotificationEvents;
use App\Modules\Organisations\Models\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\GoogleWorkspace\WorkspaceTestCase;

class NotificationsTest extends WorkspaceTestCase
{
    private function titles(User $user): array
    {
        return $user->fresh()->notifications()->pluck('data')->pluck('title')->all();
    }

    public function test_mailbox_events_reach_only_the_owning_organisations_users(): void
    {
        [$a, $domainA] = $this->organisationWithDomain('alpha.test');
        [$b] = $this->organisationWithDomain('beta.test');
        $managerA = $this->organisationManager($a);
        $colleagueA = $this->organisationManager($a);
        $billingA = $this->billingManager($a);
        $managerB = $this->organisationManager($b);

        $this->actingAs($managerA)->post('/accounts', ['organisation' => $a->uuid, 'domain' => $domainA->uuid,
            'username' => 'new.person', 'first_name' => 'New', 'last_name' => 'Person'])->assertSessionHasNoErrors();
        $account = GoogleAccount::query()->firstOrFail();
        $this->post(route('accounts.suspend', $account), ['reason' => 'Long leave'])->assertSessionHasNoErrors();

        foreach ([$managerA, $colleagueA] as $user) {
            $this->assertEqualsCanonicalizing(['Email account suspended', 'Email account created'], $this->titles($user));
        }
        $this->assertSame([], $this->titles($managerB), 'Another organisation must never be told.');
        $this->assertSame([], $this->titles($billingA), 'Billing managers do not see mailbox events.');

        $notification = $colleagueA->fresh()->notifications()->get()->first(fn ($n) => $n->data['type'] === 'account.suspended');
        $this->assertSame('new.person@alpha.test was suspended. Reason: Long leave. Suspended mailboxes remain billable.', $notification->data['body']);
        $this->assertSame(route('accounts.show', $account), $notification->data['url']);
        $this->assertSame($a->name, $notification->data['organisation']);
    }

    public function test_failures_reach_the_technical_team_and_are_throttled(): void
    {
        [$organisation, $domain] = $this->organisationWithDomain('alpha.test');
        $admin = $this->superAdmin();
        $ops = $this->operationsAdmin();
        $manager = $this->organisationManager($organisation);

        $this->workspace()->failNext('users.insert', new WorkspaceException('Invalid Input'), 2);
        foreach (['one', 'two'] as $name) {
            $this->actingAs($manager)->post('/accounts', ['organisation' => $organisation->uuid, 'domain' => $domain->uuid,
                'username' => $name, 'first_name' => 'X', 'last_name' => 'Y']);
        }

        $this->assertSame(2, collect($this->titles($manager))->filter(fn ($t) => $t === 'Email account could not be created')->count());
        $this->assertContains('Email account could not be created', $this->titles($admin));
        $this->assertContains('Email account could not be created', $this->titles($ops));

        // Repeated integration errors are sent at most once an hour per kind.
        $events = app(NotificationEvents::class);
        $events->zohoError('invoice sync', 'Zoho Books 500');
        $events->zohoError('invoice sync', 'Zoho Books 500');
        $this->assertSame(1, collect($this->titles($admin))->filter(fn ($t) => $t === 'Zoho Books integration error')->count());
        $this->assertNotContains('Zoho Books integration error', $this->titles($ops), 'Zoho errors go to those who manage Zoho.');
    }

    public function test_domain_and_billing_events(): void
    {
        $organisation = Organisation::factory()->create();
        $manager = $this->organisationManager($organisation);
        $billing = $this->billingManager($organisation);
        $admin = $this->superAdmin();
        $approver = $this->superAdmin();

        $this->actingAs($manager)->post('/domains/requests', ['organisation' => $organisation->uuid, 'domain_name' => 'newco.test']);
        $request = DomainRequest::query()->firstOrFail();
        $this->assertContains('Domain request to review', $this->titles($admin));
        $this->assertNotContains('Domain request to review', $this->titles($manager));

        $this->actingAs($approver)->post(route('domains.requests.approve', $request));
        $this->assertContains('Domain request approved', $this->titles($manager));

        // Billing: invoice issued and payment received go to billing viewers of that organisation.
        app(PricingService::class)->setOrganisationPrice($organisation, 25000, '2026-01-01', $admin);
        $account = new GoogleAccount(['primary_email' => 'box@newco.test', 'first_name' => 'B', 'last_name' => 'O']);
        $account->forceFill(['organisation_id' => $organisation->id, 'email_unique_key' => 'box@newco.test', 'account_status' => AccountStatus::Active,
            'billing_status' => BillingStatus::Billable, 'billable_from' => '2026-01-01'])->save();
        AccountBillingPeriod::query()->create(['google_account_id' => $account->id, 'organisation_id' => $organisation->id, 'starts_on' => '2026-01-01']);
        $start = CarbonImmutable::parse('2026-04-01');
        $service = app(InvoiceService::class);
        $invoice = $service->issue($service->generate($organisation, $start, $start->endOfMonth()->startOfDay(), $admin), $admin);

        foreach ([$manager, $billing] as $user) {
            $this->assertContains('New invoice '.$invoice->invoice_number, $this->titles($user));
        }

        app(PaymentService::class)->recordVerified($organisation, 29500, now()->toDateString(), 'offline', details: ['reference' => 'UTR5'], invoice: $invoice, actor: $admin);
        $this->assertContains('Payment received', $this->titles($billing));

        // Overdue reminders: once per invoice.
        $second = $service->issue($service->generate($organisation, $start->addMonth(), $start->addMonth()->endOfMonth()->startOfDay(), $admin), $admin);
        $this->travel(40)->days();
        (new SendOverdueInvoiceRemindersJob)->handle(app(NotificationEvents::class));
        (new SendOverdueInvoiceRemindersJob)->handle(app(NotificationEvents::class));
        $this->assertSame(1, collect($this->titles($billing))->filter(fn ($t) => $t === 'Invoice '.$second->invoice_number.' is overdue')->count());
        $this->assertNotNull($second->fresh()->overdue_notified_at);
        $this->assertNotContains('Invoice '.$invoice->invoice_number.' is overdue', $this->titles($billing), 'A paid invoice is never overdue.');
        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
    }

    public function test_email_follows_each_users_preferences(): void
    {
        $organisation = Organisation::factory()->create();
        $manager = $this->organisationManager($organisation);
        $notification = new AppNotification(NotificationType::AccountSuspended->value, 'T', 'B');

        $this->assertSame(['database', 'mail'], $notification->via($manager));
        $this->assertSame(['database'], (new AppNotification(NotificationType::AccountCreated->value, 'T', 'B'))->via($manager), 'Routine events are in-app only by default.');

        $this->actingAs($manager)->get('/account/notifications')->assertOk()->assertSee('Email account suspended')->assertDontSee('Zoho Books integration error');
        $this->post('/account/notifications', ['mail' => [NotificationType::AccountCreated->value]])->assertSessionHas('success');

        $manager->refresh();
        $this->assertSame(['database'], $notification->via($manager));
        $this->assertSame(['database', 'mail'], (new AppNotification(NotificationType::AccountCreated->value, 'T', 'B'))->via($manager));

        Notification::fake();
        $this->workspace()->seedDomain('y.test');
        app(NotificationEvents::class)->accountSuspended($this->existingAccount($organisation, 'x@y.test'));
        Notification::assertSentTo($manager, AppNotification::class, fn ($n, $channels) => $channels === ['database']);
    }

    public function test_notification_pages_show_only_the_users_own_notifications(): void
    {
        [$a, $domain] = $this->organisationWithDomain('alpha.test');
        $manager = $this->organisationManager($a);
        $other = $this->organisationManager(Organisation::factory()->create());
        $account = $this->existingAccount($a, 'staff@alpha.test');
        app(NotificationEvents::class)->accountReactivated($account);
        $id = $manager->notifications()->value('id');

        $this->actingAs($manager)->get('/notifications')->assertOk()->assertSee('Email account reactivated')->assertSee('1 unread');
        $this->get('/dashboard')->assertSee('Email account reactivated');                  // header panel

        $this->actingAs($other)->get('/notifications/'.$id.'/open')->assertNotFound();
        $this->assertNull($manager->fresh()->notifications()->first()->read_at);

        $this->actingAs($manager)->get('/notifications/'.$id.'/open')->assertRedirect(route('accounts.show', $account));
        $this->assertNotNull($manager->fresh()->notifications()->first()->read_at);

        // A stored link that leaves the application is not followed.
        $manager->notifications()->first()->forceFill(['data' => ['title' => 'x', 'url' => 'https://evil.example/phish']])->save();
        $this->get('/notifications/'.$id.'/open')->assertRedirect(route('notifications.index'));

        app(NotificationEvents::class)->accountReactivated($account);
        $this->post('/notifications/read')->assertSessionHas('success');
        $this->assertSame(0, $manager->fresh()->unreadNotifications()->count());
    }
}
