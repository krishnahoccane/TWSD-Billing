<?php

namespace Tests\Feature\Zoho;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Jobs\GenerateBillingCycleJob;
use App\Modules\Billing\Models\AccountBillingPeriod;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Services\BillingRunner;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\PricingService;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Enums\BillingStatus;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Integrations\Models\IntegrationSetting;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Zoho\Clients\LoggingZohoBooksClient;
use App\Modules\Zoho\Clients\MockZohoBooksClient;
use App\Modules\Zoho\Clients\ZohoBooksClient;
use App\Modules\Zoho\Contracts\ZohoBooksClientInterface;
use App\Modules\Zoho\Exceptions\ZohoException;
use App\Modules\Zoho\Exceptions\ZohoRateLimitException;
use App\Modules\Zoho\Exceptions\ZohoTransientException;
use App\Modules\Zoho\Jobs\ReconcileZohoPaymentJob;
use App\Modules\Zoho\Jobs\SyncInvoiceToZohoJob;
use App\Modules\Zoho\Models\WebhookEvent;
use App\Modules\Zoho\Models\ZohoMapping;
use App\Modules\Zoho\Services\ZohoConnection;
use App\Modules\Zoho\Services\ZohoSync;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoBooksTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-17 10:00:00');
        config(['wcc.billing.timezone' => 'UTC', 'wcc.billing.default_tax_rate' => '18.00', 'wcc.zoho.webhook_secret' => 'TestWebhookSecret123']);
        $this->admin = $this->superAdmin();
    }

    private function zoho(): MockZohoBooksClient
    {
        return app(MockZohoBooksClient::class);
    }

    private function sync(): ZohoSync
    {
        return app(ZohoSync::class);
    }

    private function organisation(string $code = 'ACME', int $price = 25000): Organisation
    {
        $organisation = Organisation::factory()->create(['code' => $code, 'name' => $code.' Ltd', 'billing_email' => strtolower($code).'@billing.test', 'gstin' => '36ABCDE1234F1Z5']);
        app(PricingService::class)->setOrganisationPrice($organisation, $price, '2026-01-01', $this->admin);

        $account = new GoogleAccount(['primary_email' => 'box@'.strtolower($code).'.test', 'first_name' => 'Box', 'last_name' => 'Owner']);
        $account->forceFill(['organisation_id' => $organisation->id, 'email_unique_key' => 'box@'.strtolower($code).'.test',
            'account_status' => AccountStatus::Active, 'billing_status' => BillingStatus::Billable, 'billable_from' => '2026-01-01'])->save();
        AccountBillingPeriod::query()->create(['google_account_id' => $account->id, 'organisation_id' => $organisation->id, 'starts_on' => '2026-01-01']);

        return $organisation;
    }

    private function issue(Organisation $organisation, string $month = '2026-04'): Invoice
    {
        $start = CarbonImmutable::parse($month.'-01');
        $service = app(InvoiceService::class);

        return $service->issue($service->generate($organisation, $start, $start->endOfMonth()->startOfDay(), $this->admin), $this->admin)->fresh();
    }

    public function test_tests_never_reach_live_zoho(): void
    {
        config(['wcc.zoho.enabled' => true]);
        app()->forgetInstance(ZohoBooksClientInterface::class);

        $client = app(ZohoBooksClientInterface::class);
        $this->assertInstanceOf(LoggingZohoBooksClient::class, $client);
        $this->assertInstanceOf(MockZohoBooksClient::class, $client->inner());
        $this->assertSame('mock', $this->sync()->mode());
    }

    public function test_issuing_an_invoice_sends_it_to_zoho_with_our_number(): void
    {
        $organisation = $this->organisation();
        $invoice = $this->issue($organisation);

        $mapping = $this->sync()->mapping($invoice);
        $this->assertSame(ZohoMapping::SYNCED, $mapping->sync_status);
        $this->assertNotNull($mapping->zoho_id);
        $number = now(config('wcc.billing.timezone'))->format('y').'/TWSD-001';
        $this->assertSame($number, $invoice->invoice_number);
        $this->assertSame($number, $mapping->zoho_number);
        $this->assertSame('sent', $mapping->zoho_status);
        $this->assertSame(29500, $mapping->zoho_total_minor);
        $this->assertStringContainsString('mock-invoice', $mapping->zoho_url);

        $remote = $this->zoho()->rawInvoice($mapping->zoho_id);
        $this->assertSame($number, $remote['invoice_number']);
        $this->assertSame($invoice->uuid, $remote['reference_number']);
        $this->assertSame('250.00', $remote['line_items'][0]['rate']);
        $this->assertStringContainsString('box@acme.test', $remote['line_items'][0]['description']);
        $this->assertSame('MOCK-GST18', $remote['line_items'][0]['tax_id']);

        // The customer is created once and reused.
        $contact = $this->zoho()->rawContact($this->sync()->mapping($organisation)->zoho_id);
        $this->assertSame('ACME Ltd (ACME)', $contact['contact_name']);
        $this->assertSame('36ABCDE1234F1Z5', $contact['gst_no']);
        $this->issue($organisation, '2026-05');
        $this->assertSame(1, $this->zoho()->callCount('contacts.create'));
        $this->assertSame(2, $this->zoho()->callCount('invoices.create'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.sent_to_zoho', 'organisation_id' => $organisation->id]);
        $this->assertDatabaseHas('integration_sync_logs', ['provider' => 'mock', 'operation' => 'invoices.create', 'status' => 'success']);
        // Drafts are never sent.
        $start = CarbonImmutable::parse('2026-06-01');
        $draft = app(InvoiceService::class)->generate($organisation, $start, $start->endOfMonth()->startOfDay(), $this->admin);
        $this->assertNull($this->sync()->mapping($draft));
    }

    public function test_retries_never_create_a_second_customer_or_invoice_in_zoho(): void
    {
        $organisation = $this->organisation();
        $invoice = $this->issue($organisation);

        (new SyncInvoiceToZohoJob($invoice->id))->handle($this->sync());
        (new SyncInvoiceToZohoJob($invoice->id))->handle($this->sync());
        $this->assertSame(1, $this->zoho()->callCount('invoices.create'));

        // Zoho created the invoice but the local record was lost: it is found again by number.
        ZohoMapping::query()->delete();
        $this->sync()->syncInvoice($invoice->fresh());

        $this->assertSame(1, $this->zoho()->callCount('invoices.create'));
        $this->assertSame(1, $this->zoho()->callCount('contacts.create'));
        $this->assertSame(ZohoMapping::SYNCED, $this->sync()->mapping($invoice)->sync_status);
    }

    public function test_a_zoho_failure_is_recorded_and_retried_by_reconciliation(): void
    {
        $organisation = $this->organisation();
        $this->zoho()->failNext('invoices.create', new ZohoException('Zoho Books error 1001: tax id is invalid.'));

        $invoice = $this->issue($organisation);

        $mapping = $this->sync()->mapping($invoice);
        $this->assertSame(ZohoMapping::FAILED, $mapping->sync_status);
        $this->assertStringContainsString('tax id is invalid', $mapping->last_error);
        $this->assertSame(Invoice::ISSUED, $invoice->fresh()->status);      // the local invoice is unaffected
        $this->assertDatabaseHas('integration_sync_logs', ['operation' => 'invoices.create', 'status' => 'failure']);
        $this->actingAs($this->admin)->get('/integrations/zoho')->assertOk()->assertSee('tax id is invalid');

        ReconcileZohoPaymentJob::dispatch();

        $this->assertSame(ZohoMapping::SYNCED, $this->sync()->mapping($invoice)->sync_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'zoho.reconciled']);
    }

    public function test_transient_errors_are_retried_and_rate_limits_released(): void
    {
        $organisation = $this->organisation();
        $this->zoho()->failNext('invoices.create', new ZohoTransientException('Zoho Books 503'));

        // Issuing succeeds even though Zoho is down; the failure is recorded for the retry.
        $invoice = $this->issue($organisation);
        $this->assertSame(Invoice::ISSUED, $invoice->status);
        $this->assertSame(ZohoMapping::FAILED, $this->sync()->mapping($invoice)->sync_status);

        // Inside the queue worker the same error is rethrown so the job is retried.
        $this->zoho()->failNext('invoices.find', new ZohoTransientException('Zoho Books 503'));
        try {
            (new SyncInvoiceToZohoJob($invoice->id))->handle($this->sync());
            $this->fail('A transient error must propagate so the queue retries the job.');
        } catch (ZohoTransientException) {
            $this->assertSame(Invoice::ISSUED, $invoice->fresh()->status);
        }

        $this->zoho()->failNext('invoices.find', new ZohoRateLimitException(retryAfter: 33));
        $job = (new SyncInvoiceToZohoJob($invoice->id))->withFakeQueueInteractions();
        $job->handle($this->sync());
        $job->assertReleased(delay: 33);

        (new SyncInvoiceToZohoJob($invoice->id))->handle($this->sync());
        $this->assertSame(ZohoMapping::SYNCED, $this->sync()->mapping($invoice)->sync_status);
    }

    public function test_a_payment_made_in_zoho_marks_the_invoice_paid_only_after_reconciliation(): void
    {
        $organisation = $this->organisation();
        $invoice = $this->issue($organisation);
        $zohoInvoiceId = $this->sync()->mapping($invoice)->zoho_id;

        $this->zoho()->simulatePayment($zohoInvoiceId, '100.00', '2026-11-18', 'online', 'RZP-1');
        $this->assertSame(Invoice::ISSUED, $invoice->fresh()->status, 'Nothing is trusted until it is read from Zoho.');

        $result = $this->sync()->reconcileOrganisation($organisation);

        $this->assertSame(1, $result['recorded']);
        $this->assertSame([Invoice::PARTIALLY_PAID, 10000, 19500], [$invoice->fresh()->status, $invoice->fresh()->amount_paid_minor, $invoice->fresh()->balance_minor]);
        $payment = Payment::query()->firstOrFail();
        $this->assertSame(['zoho', 10000, 'RZP-1'], [$payment->source, $payment->amount_minor, $payment->reference]);
        $this->assertStringStartsWith('zoho:', $payment->external_id);

        $this->zoho()->simulatePayment($zohoInvoiceId, '195.00', '2026-11-19');
        $this->sync()->reconcileOrganisation($organisation);
        $this->sync()->reconcileOrganisation($organisation);              // again: nothing new

        $this->assertSame(2, Payment::query()->count());
        $this->assertSame([Invoice::PAID, 0], [$invoice->fresh()->status, $invoice->fresh()->balance_minor]);
        $this->assertSame(ZohoMapping::SYNCED, $this->sync()->mapping($invoice)->sync_status);
    }

    public function test_differences_from_zoho_are_reported(): void
    {
        $organisation = $this->organisation();
        $invoice = $this->issue($organisation);
        $this->zoho()->simulatePayment($this->sync()->mapping($invoice)->zoho_id, '50.00', '2026-11-18');

        // Comparing alone (payment not yet recorded here) shows the difference.
        $mapping = $this->sync()->refreshInvoice($invoice->fresh());
        $this->assertSame(ZohoMapping::MISMATCH, $mapping->sync_status);
        $this->assertStringContainsString('Zoho balance ₹245.00 differs from ₹295.00', $mapping->last_error);

        $this->sync()->reconcileOrganisation($organisation);
        $this->assertSame(ZohoMapping::SYNCED, $this->sync()->mapping($invoice)->sync_status);

        // A credit note issued here must be mirrored in Zoho by hand: flagged.
        app(InvoiceService::class)->creditNote($invoice->fresh(), 4500, 'Goodwill', $this->admin);
        $this->assertSame(ZohoMapping::NEEDS_ATTENTION, $this->sync()->mapping($invoice)->sync_status);
        $this->sync()->reconcileOrganisation($organisation);
        $this->assertSame(ZohoMapping::NEEDS_ATTENTION, $this->sync()->mapping($invoice)->sync_status);
        $this->assertStringContainsString('Create the matching credit note in Zoho Books', $this->sync()->mapping($invoice)->last_error);
    }

    public function test_offline_payments_are_pushed_to_zoho_once(): void
    {
        $organisation = $this->organisation();
        $invoice = $this->issue($organisation);

        $this->actingAs($this->admin)->post(route('invoices.payments.store', $invoice), [
            'amount' => '295.00', 'paid_on' => '2026-11-17', 'reference' => 'UTR123', 'method' => 'banktransfer',
        ])->assertRedirect()->assertSessionHas('success');

        $payment = Payment::query()->firstOrFail();
        $this->assertSame(['offline', Invoice::PAID], [$payment->source, $invoice->fresh()->status]);
        $this->assertStringStartsWith('zoho:', $payment->external_id);

        $remote = $this->zoho()->getCustomerPayment(substr($payment->external_id, 5));
        $this->assertSame('295.00', $remote['amount']);
        $this->assertSame('banktransfer', $remote['payment_mode']);
        $this->assertStringContainsString('WCC-PAY-'.$payment->uuid, $remote['reference_number']);
        $this->assertSame('paid', $this->zoho()->rawInvoice($this->sync()->mapping($invoice)->zoho_id)['status']);

        // Reconciliation recognises our own payment and does not record it again.
        $this->sync()->reconcileOrganisation($organisation);
        $this->sync()->reconcileAll();
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, $this->zoho()->callCount('payments.create'));
        $this->assertSame(ZohoMapping::SYNCED, $this->sync()->mapping($invoice)->sync_status);
    }

    public function test_voiding_here_voids_in_zoho(): void
    {
        $organisation = $this->organisation();
        $invoice = $this->issue($organisation);

        $this->actingAs($this->admin)->post(route('invoices.void', $invoice), ['reason' => 'Raised in error'])->assertSessionHasNoErrors();

        $mapping = $this->sync()->mapping($invoice);
        $this->assertSame('void', $this->zoho()->rawInvoice($mapping->zoho_id)['status']);
        $this->assertSame([ZohoMapping::SYNCED, 'void'], [$mapping->sync_status, $mapping->zoho_status]);
    }

    public function test_webhooks_are_verified_deduplicated_and_only_trigger_an_api_read(): void
    {
        $organisation = $this->organisation();
        $invoice = $this->issue($organisation);
        $paymentId = $this->zoho()->simulatePayment($this->sync()->mapping($invoice)->zoho_id, '295.00', '2026-11-18', 'online', 'RZP-9');
        // The body claims more than was paid: only Zoho's API is believed.
        $body = json_encode(['payment' => ['payment_id' => $paymentId, 'amount' => '999999.00']]);

        $post = fn (array $headers, ?string $raw = null) => $this->call('POST', '/api/v1/webhooks/zoho', [], [], [],
            $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), $raw ?? $body);

        $post([])->assertStatus(401);
        $post(['X-WCC-Webhook-Token' => 'wrong-secret'])->assertStatus(401);
        $post(['X-Zoho-Webhook-Signature' => base64_encode('forged')])->assertStatus(401);
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertSame(Invoice::ISSUED, $invoice->fresh()->status);

        $post(['X-WCC-Webhook-Token' => 'TestWebhookSecret123'])->assertOk()->assertJson(['message' => 'Received.']);
        $post(['X-WCC-Webhook-Token' => 'TestWebhookSecret123'])->assertOk()->assertJson(['message' => 'Already received.']);

        $this->assertDatabaseCount('webhook_events', 1);
        $event = WebhookEvent::query()->firstOrFail();
        $this->assertSame(['processed', ['payment_id' => $paymentId]], [$event->status, $event->payload]);
        $this->assertSame([Invoice::PAID, 29500], [$invoice->fresh()->status, $invoice->fresh()->amount_paid_minor]);
        $this->assertSame(29500, Payment::query()->firstOrFail()->amount_minor);
        $this->assertSame(1, $this->zoho()->callCount('payments.get'));

        // A valid Zoho HMAC signature (sorted key-value pairs) is also accepted.
        $other = json_encode(['payment_id' => 'MOCKP-UNKNOWN', 'note' => 'x']);
        $post(['X-Zoho-Webhook-Signature' => base64_encode(hash_hmac('sha256', 'notexpayment_idMOCKP-UNKNOWN', 'TestWebhookSecret123', true))], $other)->assertOk();
        $this->assertSame('failed', WebhookEvent::query()->latest('id')->first()->status);  // unknown payment in Zoho: recorded, nothing paid

        config(['wcc.zoho.webhook_secret' => null]);
        $post(['X-WCC-Webhook-Token' => 'TestWebhookSecret123'])->assertStatus(503);
    }

    public function test_automatic_billing_issues_and_sends_while_manual_runs_create_drafts(): void
    {
        $organisation = $this->organisation();

        // Scheduled run for the previous month (October): issued and in Zoho.
        (new GenerateBillingCycleJob(autoIssue: true))->handle(app(BillingRunner::class));
        $october = Invoice::query()->whereDate('period_start', '2026-10-01')->firstOrFail();
        $this->assertSame(Invoice::ISSUED, $october->status);
        $this->assertNull($october->issued_by);
        $this->assertNotNull($october->invoice_number);
        $this->assertSame(ZohoMapping::SYNCED, $this->sync()->mapping($october)->sync_status);

        // A run started by a Super Admin for an older month: drafts only, nothing in Zoho.
        $this->actingAs($this->admin)->post('/billing/cycles', ['month' => '2026-08'])->assertSessionHas('success');
        $august = Invoice::query()->whereDate('period_start', '2026-08-01')->firstOrFail();
        $this->assertSame(Invoice::DRAFT, $august->status);
        $this->assertNull($this->sync()->mapping($august));
        $this->assertSame(1, $this->zoho()->callCount('invoices.create'));
        $this->assertSame($organisation->id, $august->organisation_id);
    }

    public function test_pay_link_and_pdf_are_available_to_the_organisation_only(): void
    {
        $mine = $this->organisation('MINE');
        $theirs = $this->organisation('THEIRS');
        $invoice = $this->issue($mine);
        $other = $this->issue($theirs);
        $billing = $this->billingManager($mine);

        $this->actingAs($billing)->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('Pay ₹295.00 (mock link)')->assertSee($this->sync()->mapping($invoice)->zoho_url)
            ->assertDontSee('Simulate online payment')->assertDontSee('Record an offline payment');

        $pdf = $this->get(route('invoices.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->get(route('invoices.show', $other))->assertNotFound();
        $this->get(route('invoices.pdf', $other))->assertNotFound();
        $this->post(route('invoices.simulate-zoho-payment', $invoice))->assertForbidden();

        // A draft has no PDF yet.
        $start = CarbonImmutable::parse('2026-07-01');
        $draft = app(InvoiceService::class)->generate($mine, $start, $start->endOfMonth()->startOfDay(), $this->admin);
        $this->actingAs($this->admin)->get(route('invoices.pdf', $draft))->assertNotFound();

        // Mock-only helper for trying the flow: pays and reconciles.
        $this->post(route('invoices.simulate-zoho-payment', $invoice))->assertSessionHas('success');
        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
    }

    public function test_zoho_screens_are_super_admin_only(): void
    {
        $organisation = $this->organisation();
        $invoice = $this->issue($organisation);

        foreach ([$this->organisationManager($organisation), $this->billingManager($organisation), $this->operationsAdmin($organisation)] as $user) {
            $this->actingAs($user);
            $this->get('/integrations/zoho')->assertForbidden();
            $this->post('/integrations/zoho/settings', ['organization_id' => '1'])->assertForbidden();
            $this->get('/integrations/zoho/connect')->assertForbidden();
            $this->get('/integrations/zoho/callback?code=x&state=y')->assertForbidden();
            $this->post('/integrations/zoho/disconnect')->assertForbidden();
            $this->post('/integrations/zoho/sync')->assertForbidden();
            $this->postJson('/api/v1/integrations/zoho/sync')->assertForbidden();
        }

        $this->actingAs($this->admin)->get('/integrations/zoho')->assertOk()->assertSee('Mock mode')->assertSee('/api/v1/webhooks/zoho');
        $this->post('/integrations/zoho/settings', ['organization_id' => 'abc'])->assertSessionHasErrors('organization_id');
        $this->post('/integrations/zoho/settings', ['organization_id' => '60012345678', 'tax_id' => '460000000038080'])->assertSessionHasNoErrors();
        $this->assertSame('460000000038080', app(ZohoConnection::class)->settings()['tax_id']);
        $this->post('/integrations/zoho/test')->assertSessionHas('success');
        $this->postJson('/api/v1/integrations/zoho/test')->assertOk()->assertJsonPath('data.mode', 'mock');
        $this->getJson('/api/v1/integrations/status')->assertOk()->assertJsonPath('data.zoho_books.mode', 'mock');
        $this->assertNotNull($invoice);
    }

    public function test_oauth_connect_stores_an_encrypted_refresh_token_and_never_exposes_it(): void
    {
        config(['wcc.zoho.client_id' => '1000.TESTCLIENT', 'wcc.zoho.client_secret' => 'client-secret-value',
            'wcc.zoho.redirect_uri' => 'http://localhost/integrations/zoho/callback', 'wcc.zoho.accounts_base_url' => 'https://accounts.zoho.in']);
        Http::preventStrayRequests();
        Http::fake(['https://accounts.zoho.in/oauth/v2/token' => Http::response([
            'access_token' => '1000.access-token-value', 'refresh_token' => '1000.refresh-token-value',
            'api_domain' => 'https://www.zohoapis.in', 'token_type' => 'Bearer', 'expires_in' => 3600,
        ])]);
        $this->actingAs($this->admin);

        $redirect = $this->get('/integrations/zoho/connect')->assertRedirect();
        $location = $redirect->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://accounts.zoho.in/oauth/v2/auth?', $location);
        $this->assertSame(['code', 'offline', 'consent', '1000.TESTCLIENT'], [$query['response_type'], $query['access_type'], $query['prompt'], $query['client_id']]);
        $this->assertStringContainsString('ZohoBooks.invoices.ALL', $query['scope']);

        // Forged or replayed callbacks are refused.
        $this->get('/integrations/zoho/callback?code=abc&state=forged')->assertRedirect('/integrations/zoho')->assertSessionHas('error');
        $this->assertFalse(app(ZohoConnection::class)->isConnected());

        $this->get('/integrations/zoho/connect');
        $state = session('zoho_oauth_state');
        // A non-Zoho accounts server is ignored; the configured one is used.
        $this->get('/integrations/zoho/callback?code=one-time-code&state='.$state.'&accounts-server='.urlencode('https://evil.example'))
            ->assertRedirect('/integrations/zoho')->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://accounts.zoho.in/oauth/v2/token'
            && $r['grant_type'] === 'authorization_code' && $r['code'] === 'one-time-code' && $r['client_secret'] === 'client-secret-value');

        $row = IntegrationSetting::query()->where('provider', 'zoho_books')->firstOrFail();
        $this->assertTrue(app(ZohoConnection::class)->isConnected());
        $this->assertStringNotContainsString('refresh-token-value', (string) $row->getRawOriginal('secrets'));
        $this->assertSame('https://www.zohoapis.in/books/v3', app(ZohoConnection::class)->apiBase());
        $this->assertSame('1000.access-token-value', app(ZohoConnection::class)->accessToken());

        $everything = AuditLog::query()->get()->toJson();
        $page = $this->get('/integrations/zoho')->assertOk()->getContent();
        foreach (['refresh-token-value', 'access-token-value', 'client-secret-value'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
            $this->assertStringNotContainsString($secret, $page);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'integration.zoho.connected', 'user_id' => $this->admin->id]);
    }

    public function test_live_client_uses_the_documented_requests_and_maps_errors(): void
    {
        config(['wcc.zoho.client_id' => 'id', 'wcc.zoho.client_secret' => 'secret', 'wcc.zoho.redirect_uri' => 'http://localhost/cb', 'wcc.zoho.accounts_base_url' => 'https://accounts.zoho.in']);
        $row = app(ZohoConnection::class)->settingRow();
        $row->secrets = ['refresh_token' => 'stored-refresh'];
        $row->settings = ['organization_id' => '600123', 'api_domain' => 'https://www.zohoapis.in'];
        $row->save();

        Http::preventStrayRequests();
        Http::fake([
            'https://accounts.zoho.in/oauth/v2/token' => Http::response(['access_token' => 'fresh-access', 'expires_in' => 3600]),
            'https://www.zohoapis.in/books/v3/invoices?*' => Http::sequence()
                ->push(['code' => 0, 'invoice' => ['invoice_id' => '9001', 'invoice_number' => 'INV-000777', 'status' => 'draft', 'total' => '295.00', 'balance' => '295.00', 'invoice_url' => 'https://zohosecure.in/inventory/abc']], 201)
                ->push(['code' => 1001, 'message' => 'Invoice number already exists.'], 400)
                ->push(['message' => 'Too many requests'], 429, ['Retry-After' => '12'])
                ->push(['message' => 'Server error'], 500)
                ->push(['code' => 57, 'message' => 'You are not authorized to perform this operation'], 401),
        ]);

        $client = new ZohoBooksClient(app(Factory::class), app(ZohoConnection::class));

        $created = $client->createInvoice(['customer_id' => '1', 'invoice_number' => 'INV-000777', 'line_items' => []]);
        $this->assertSame(['9001', 'https://zohosecure.in/inventory/abc'], [$created['invoice_id'], $created['invoice_url']]);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_starts_with($r->url(), 'https://www.zohoapis.in/books/v3/invoices?')
            && str_contains($r->url(), 'organization_id=600123') && str_contains($r->url(), 'ignore_auto_number_generation=true')
            && $r->hasHeader('Authorization', 'Zoho-oauthtoken fresh-access'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/oauth/v2/token') && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'stored-refresh');

        foreach ([ZohoException::class, ZohoRateLimitException::class, ZohoTransientException::class, ZohoTransientException::class] as $expected) {
            try {
                $client->createInvoice(['customer_id' => '1', 'line_items' => []]);
                $this->fail("Expected {$expected}");
            } catch (ZohoException $e) {
                $this->assertSame($expected, $e::class, $e->getMessage());
            }
        }
    }
}
