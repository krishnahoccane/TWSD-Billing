<?php

namespace Tests\Feature\Licensing;

use App\Modules\Licensing\Jobs\SendLicenseExpiryEventsJob;
use App\Modules\Licensing\Models\SoftwareActivation;
use App\Modules\Licensing\Models\SoftwareLicense;
use App\Modules\Licensing\Models\SoftwareOrder;
use App\Modules\Licensing\Models\SoftwarePlan;
use App\Modules\Licensing\Models\SoftwareProduct;
use App\Modules\Licensing\Models\SoftwareRelease;
use App\Modules\Licensing\Models\Storefront;
use App\Modules\Licensing\Services\LicenseService;
use App\Modules\Licensing\Services\StorefrontService;
use App\Modules\Licensing\Services\StorefrontWebhooks;
use App\Modules\Licensing\Support\LicenseToken;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StorefrontApiTest extends TestCase
{
    private Storefront $storefront;

    private string $key;

    private SoftwareProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['https://musicdesk.test/*' => Http::response(['ok' => true])]);

        $admin = $this->superAdmin();
        [$this->storefront, $this->key] = app(StorefrontService::class)->create('musicdesk', 'MusicDesk', 'https://musicdesk.test/webhooks/wcc', $admin);
        app(StorefrontService::class)->rotateWebhookSecret($this->storefront, $admin);
        $this->storefront->refresh();

        $this->product = SoftwareProduct::query()->create(['storefront_id' => $this->storefront->id, 'code' => 'labeldesk', 'name' => 'LabelDesk', 'key_prefix' => 'LDSK']);

        foreach ([['starter', 'Starter', 1, 49900], ['business', 'Business', 3, 129900]] as [$code, $name, $domains, $usd]) {
            $plan = SoftwarePlan::query()->create(['software_product_id' => $this->product->id, 'code' => $code, 'name' => $name, 'included_domains' => $domains, 'features' => ["{$domains} production domains"]]);
            $plan->prices()->create(['kind' => 'annual', 'currency' => 'USD', 'amount_minor' => $usd]);
            $plan->prices()->create(['kind' => 'annual', 'currency' => 'INR', 'amount_minor' => $usd * 80]);
        }

        SoftwareRelease::query()->create(['software_product_id' => $this->product->id, 'version' => '4.3.0', 'released_at' => now()->subDays(5), 'notes' => [['kind' => 'new', 'text' => 'Royalty statements']]]);
    }

    /** @param  array<string, string>  $headers */
    private function api(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json($method, '/api/v1/storefront'.$uri, $data, ['Authorization' => 'Bearer '.$this->key] + $headers);
    }

    /** @return array<string, mixed> */
    private function buy(string $plan = 'business', string $email = 'nova@lowtide.test', ?string $customerId = null, ?string $domain = null): array
    {
        return $this->api('POST', '/orders', [
            'kind' => 'new_license',
            'customer' => $customerId ? ['id' => $customerId] : ['name' => 'Nova Reyes', 'email' => $email, 'company' => 'Lowtide Distribution Ltd.', 'country' => 'GB', 'vat_id' => 'GB284119307'],
            'product' => 'labeldesk', 'plan_code' => $plan, 'billing_type' => 'annual',
            'primary_domain' => $domain ?? 'https://www.Portal.Lowtide.co/',
            'amount' => ['amount' => 129900, 'currency' => 'USD'],
            'payment_provider' => 'razorpay', 'payment_reference' => 'pay_ABC123', 'invoice_number' => 'MD-0001',
        ], ['Idempotency-Key' => (string) Str::uuid(), 'X-Acting-User' => 'usr_1'])->assertCreated()->json();
    }

    public function test_api_key_is_required_and_hashed(): void
    {
        $this->json('GET', '/api/v1/storefront/catalog/labeldesk')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->json('GET', '/api/v1/storefront/catalog/labeldesk', [], ['Authorization' => 'Bearer sk_live_wrong'])->assertStatus(401);
        $this->assertSame(hash('sha256', $this->key), $this->storefront->fresh()->api_key_hash);
        $this->assertStringNotContainsString($this->key, (string) DB::table('storefronts')->value('webhook_secret'));
    }

    public function test_catalog_in_the_customers_currency(): void
    {
        $this->api('GET', '/catalog/labeldesk?currency=INR')->assertOk()
            ->assertJsonPath('product.latest_version', '4.3.0')
            ->assertJsonPath('plans.1.code', 'business')
            ->assertJsonPath('plans.1.included_domains', 3)
            ->assertJsonPath('plans.1.prices.0.currency', 'INR')
            ->assertJsonPath('plans.1.prices.0.amount', 129900 * 80)
            ->assertJsonCount(1, 'plans.1.prices');

        $this->api('GET', '/products/labeldesk/releases')->assertOk()->assertJsonPath('data.0.version', '4.3.0');
        $this->api('GET', '/catalog/other')->assertNotFound()->assertJsonPath('error.code', 'product_not_found');
    }

    public function test_a_paid_order_creates_customer_license_and_pre_approved_domain_once(): void
    {
        $key = (string) Str::uuid();
        $payload = ['kind' => 'new_license', 'customer' => ['name' => 'Nova', 'email' => 'nova@lowtide.test', 'company' => 'Lowtide', 'country' => 'GB'],
            'product' => 'labeldesk', 'plan_code' => 'business', 'billing_type' => 'annual', 'primary_domain' => 'portal.lowtide.co',
            'amount' => ['amount' => 129900, 'currency' => 'USD'], 'payment_reference' => 'pay_1'];

        $first = $this->api('POST', '/orders', $payload, ['Idempotency-Key' => $key])->assertCreated();
        $again = $this->api('POST', '/orders', $payload, ['Idempotency-Key' => $key])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('id'), $again->json('id'));
        $this->api('POST', '/orders', ['kind' => 'renewal'] + $payload, ['Idempotency-Key' => $key])->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_reused');
        $this->api('POST', '/orders', $payload)->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');

        $this->assertSame(1, SoftwareLicense::query()->count());
        $this->assertSame(1, SoftwareOrder::query()->count());

        $organisation = Organisation::query()->sole();
        $this->assertSame(['Lowtide', 'GB', 'USD'], [$organisation->name, $organisation->country, $organisation->currency]);
        $this->assertTrue($this->storefront->servesCustomer($organisation));

        $first->assertJsonPath('customer_id', 'cus_'.$organisation->uuid)
            ->assertJsonPath('license.plan_code', 'business')
            ->assertJsonPath('license.status', 'active')
            ->assertJsonPath('license.domains_included', 3)
            ->assertJsonPath('license.activations.0.domain', 'portal.lowtide.co')
            ->assertJsonPath('license.activations.0.status', 'pending');
        $this->assertStringStartsWith('LDSK-••••', $first->json('license.key_masked'));
        $this->assertStringNotContainsString(SoftwareLicense::query()->sole()->key_encrypted, $first->getContent(), 'The full key is never listed.');

        // Webhooks: signed, to the storefront.
        Http::assertSent(function (HttpRequest $request) {
            if ($request->url() !== 'https://musicdesk.test/webhooks/wcc') {
                return false;
            }

            preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $request->header('WCC-Signature')[0] ?? '', $m);

            return $m !== [] && hash_equals(StorefrontWebhooks::signature((string) $this->storefront->fresh()->webhook_secret, $request->body(), (int) $m[1]), $request->header('WCC-Signature')[0])
                && $request['type'] === 'license.created' && str_starts_with($request['id'], 'evt_');
        });
    }

    public function test_customers_and_licenses_are_isolated(): void
    {
        $mine = $this->buy();
        $theirs = $this->buy('starter', 'other@else.test', domain: 'other.example.com');

        // A refused order leaves nothing behind (the domain is taken).
        $this->api('POST', '/orders', ['kind' => 'new_license', 'customer' => ['email' => 'x@y.test', 'company' => 'X', 'country' => 'IN'],
            'product' => 'labeldesk', 'plan_code' => 'starter', 'billing_type' => 'annual', 'primary_domain' => 'portal.lowtide.co',
            'amount' => ['amount' => 1, 'currency' => 'INR']], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);
        $this->assertSame(2, SoftwareLicense::query()->count());
        $this->assertSame(0, Organisation::query()->where('name', 'X')->count());

        $headers = ['X-Customer-Id' => $mine['customer_id']];

        $this->api('GET', '/licenses', [], $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine['license']['id']);
        $this->api('GET', '/licenses/'.$theirs['license']['id'], [], $headers)->assertNotFound()->assertJsonPath('error.code', 'license_not_found');
        $this->api('POST', '/licenses/'.$theirs['license']['id'].'/reveal-key', [], $headers)->assertNotFound();
        $this->api('GET', '/customers/'.$theirs['customer_id'], [], $headers)->assertForbidden();
        $this->api('GET', '/licenses')->assertStatus(400)->assertJsonPath('error.code', 'customer_required');

        // An organisation that is not this storefront's customer does not exist for it.
        $workspaceOnly = Organisation::factory()->create();
        $this->api('GET', '/customers/cus_'.$workspaceOnly->uuid)->assertNotFound();

        // Another storefront's key sees nothing of this one.
        [, $otherKey] = app(StorefrontService::class)->create('otherstore', 'Other', null, $this->superAdmin());
        $this->json('GET', '/api/v1/storefront/catalog/labeldesk', [], ['Authorization' => 'Bearer '.$otherKey])->assertNotFound();
        $this->json('GET', '/api/v1/storefront/licenses/'.$mine['license']['id'], [], ['Authorization' => 'Bearer '.$otherKey] + $headers)->assertNotFound();
    }

    public function test_domain_rules_and_reveal(): void
    {
        $order = $this->buy('starter');
        $headers = ['X-Customer-Id' => $order['customer_id']];
        $licenseId = $order['license']['id'];

        // Starter = 1 production domain, already used by the primary domain.
        $this->api('POST', "/licenses/{$licenseId}/activations", ['domain' => 'second.lowtide.co'], $headers + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('error.code', 'domain_limit_reached');
        // Staging is free.
        $this->api('POST', "/licenses/{$licenseId}/activations", ['domain' => 'staging.lowtide.co'], $headers + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('environment', 'staging');

        // Buy an extra domain (paid on the storefront), then it fits.
        $this->api('POST', '/orders', ['kind' => 'extra_domains', 'license_id' => $licenseId, 'extra_domains' => 1, 'amount' => ['amount' => 14900, 'currency' => 'USD']],
            $headers + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('license.domains_extra', 1);
        $this->api('POST', "/licenses/{$licenseId}/activations", ['domain' => 'second.lowtide.co'], $headers + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        // A domain can be active on one license only.
        $other = $this->buy('business', 'b@else.test', domain: 'b.example.com');
        $this->api('POST', "/licenses/{$other['license']['id']}/activations", ['domain' => 'second.lowtide.co'], ['X-Customer-Id' => $other['customer_id'], 'Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('error.code', 'domain_in_use');

        $key = $this->api('POST', "/licenses/{$licenseId}/reveal-key", [], $headers)->assertOk()->json('key');
        $this->assertMatchesRegularExpression('/^LDSK-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $key);
        $this->assertDatabaseHas('audit_logs', ['action' => 'licensing.license_key_revealed']);
        $this->assertSame(0, DB::table('audit_logs')->where('new_data', 'like', '%'.$key.'%')->count());
    }

    public function test_license_server_activates_checks_in_and_deactivates_with_signed_tokens(): void
    {
        $order = $this->buy();
        $license = SoftwareLicense::query()->sole();
        $key = (string) $license->key_encrypted;

        $this->postJson('/api/license/v1/activate', ['key' => 'LDSK-AAAA-BBBB-CCCC-DDDD', 'domain' => 'portal.lowtide.co'])->assertNotFound()->assertJsonPath('error.code', 'invalid_key');

        $activated = $this->postJson('/api/license/v1/activate', ['key' => strtolower($key), 'domain' => 'portal.lowtide.co', 'version' => '4.3.0', 'php_version' => '8.3'])
            ->assertOk()->assertJsonPath('status', 'active');
        $claims = app(LicenseToken::class)->verify($activated->json('token'));
        $this->assertSame(['lic_'.$license->uuid, 'portal.lowtide.co', 'business'], [$claims['lic'], $claims['dom'], $claims['plan']]);

        $activation = SoftwareActivation::query()->where('domain', 'portal.lowtide.co')->sole();
        $this->assertSame(['active', '4.3.0'], [$activation->status, $activation->version], 'The pre-approved domain was taken over.');

        // The portal asks for a check-in; the next check-in reports it and clears it.
        $this->api('POST', "/licenses/{$order['license']['id']}/activations/act_{$activation->uuid}/check-in-request", [], ['X-Customer-Id' => $order['customer_id']])->assertOk();
        $this->postJson('/api/license/v1/check-in', ['token' => $activated->json('token'), 'version' => '4.3.1'])->assertOk()->assertJsonPath('check_in_requested', true);
        $this->assertSame('4.3.1', $activation->fresh()->version);

        // A tampered token is refused.
        $this->postJson('/api/license/v1/check-in', ['token' => $activated->json('token').'x'])->assertStatus(401);

        // Suspended by a Super Admin: installs are refused.
        $license->forceFill(['status' => SoftwareLicense::SUSPENDED])->save();
        $this->postJson('/api/license/v1/check-in', ['token' => $activated->json('token')])->assertForbidden()->assertJsonPath('error.code', 'license_suspended');
        $license->forceFill(['status' => SoftwareLicense::ACTIVE])->save();

        $this->postJson('/api/license/v1/deactivate', ['token' => $activated->json('token')])->assertNoContent();
        $this->assertSame('deactivated', $activation->fresh()->status);

        $this->getJson('/api/license/v1/public-key')->assertOk()->assertJsonPath('alg', 'EdDSA');
    }

    public function test_renewal_order_extends_an_annual_license_by_a_year(): void
    {
        $order = $this->buy();
        $before = SoftwareLicense::query()->sole()->expires_at;

        $this->api('POST', '/orders', ['kind' => 'renewal', 'license_id' => $order['license']['id'], 'amount' => ['amount' => 129900, 'currency' => 'USD']],
            ['X-Customer-Id' => $order['customer_id'], 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $this->assertSame($before->addYear()->toDateString(), SoftwareLicense::query()->sole()->expires_at->toDateString());
        $this->api('GET', "/licenses/{$order['license']['id']}/events", [], ['X-Customer-Id' => $order['customer_id']])->assertOk()
            ->assertJsonFragment(['event' => 'renewed']);
    }

    public function test_expiry_webhooks_are_sent_once_per_notice(): void
    {
        $this->buy();
        $license = SoftwareLicense::query()->sole();
        $license->forceFill(['expires_at' => now()->addDays(13)])->save();

        (new SendLicenseExpiryEventsJob)->handle(app(LicenseService::class), app(StorefrontWebhooks::class));
        (new SendLicenseExpiryEventsJob)->handle(app(LicenseService::class), app(StorefrontWebhooks::class));

        $this->assertSame(1, DB::table('storefront_webhook_events')->where('type', 'license.expiring')->count());
        $this->assertSame(1, $license->events()->where('event', 'expiring_14_days')->count());
        $this->api('GET', '/licenses/'.$license->publicId(), [], ['X-Customer-Id' => 'cus_'.$license->organisation->uuid])->assertJsonPath('status', 'expiring');
    }
}
