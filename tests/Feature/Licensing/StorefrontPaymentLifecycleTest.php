<?php

namespace Tests\Feature\Licensing;

use App\Modules\Licensing\Models\SoftwareLicense;
use App\Modules\Licensing\Models\SoftwareOrder;
use App\Modules\Licensing\Models\SoftwarePlan;
use App\Modules\Licensing\Models\SoftwareProduct;
use App\Modules\Licensing\Models\Storefront;
use App\Modules\Licensing\Models\StorefrontWebhookEvent;
use App\Modules\Licensing\Services\StorefrontService;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /customers, POST /orders/{ord}/confirm-payment and POST /orders/{ord}/refund:
 * the storefront creates customers at sign-up, confirms bank transfers and reports refunds.
 */
class StorefrontPaymentLifecycleTest extends TestCase
{
    private Storefront $storefront;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['https://musicdesk.test/*' => Http::response(['ok' => true])]);

        $admin = $this->superAdmin();
        [$this->storefront, $this->key] = app(StorefrontService::class)->create('musicdesk', 'MusicDesk', 'https://musicdesk.test/webhooks/wcc', $admin);
        app(StorefrontService::class)->rotateWebhookSecret($this->storefront, $admin);
        $this->storefront->refresh();

        $product = SoftwareProduct::query()->create(['storefront_id' => $this->storefront->id, 'code' => 'labeldesk', 'name' => 'LabelDesk', 'key_prefix' => 'LDSK']);
        SoftwarePlan::query()->create(['software_product_id' => $product->id, 'code' => 'starter', 'name' => 'Starter', 'included_domains' => 1, 'features' => []]);
    }

    /** @param  array<string, string>  $headers */
    private function api(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json($method, '/api/v1/storefront'.$uri, $data, ['Authorization' => 'Bearer '.$this->key] + $headers);
    }

    /** @return array<string, mixed> */
    private function order(array $over = [], ?string $customerId = null): array
    {
        return $this->api('POST', '/orders', array_merge([
            'kind' => 'new_license',
            'customer' => $customerId ? ['id' => $customerId] : ['name' => 'Ravi', 'email' => 'ravi@bassline.test', 'company' => 'Bassline Music', 'country' => 'IN'],
            'product' => 'labeldesk', 'plan_code' => 'starter', 'billing_type' => 'annual',
            'amount' => ['amount' => 10832046, 'currency' => 'INR'], 'payment_provider' => 'bank_transfer',
            'payment_reference' => 'ORD-1', 'payment_status' => 'pending', 'invoice_number' => 'MD-2026-000006',
        ], $over), array_filter(['Idempotency-Key' => (string) Str::uuid(), 'X-Customer-Id' => $customerId]))->assertCreated()->json();
    }

    private function webhookTypes(): array
    {
        return StorefrontWebhookEvent::query()->orderBy('id')->pluck('type')->all();
    }

    public function test_storefront_creates_a_customer_at_sign_up(): void
    {
        $key = (string) Str::uuid();
        $body = ['company' => 'Analytical Records', 'email' => 'Ada@Analytical.test', 'name' => 'Ada', 'country' => 'gb', 'vat_id' => 'GB123', 'currency' => 'gbp'];

        $first = $this->api('POST', '/customers', $body, ['Idempotency-Key' => $key])->assertCreated()
            ->assertJsonPath('company', 'Analytical Records')->assertJsonPath('billing_email', 'ada@analytical.test')
            ->assertJsonPath('country', 'GB')->assertJsonPath('currency', 'GBP');
        $id = $first->json('id');
        $this->assertStringStartsWith('cus_', $id);

        // Same key → same customer; no Idempotency-Key → refused.
        $this->api('POST', '/customers', $body, ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('id', $id)->assertHeader('Idempotent-Replayed', 'true');
        $this->api('POST', '/customers', $body)->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
        $this->assertSame(1, Organisation::query()->where('name', 'Analytical Records')->count());

        // Linked to this storefront: readable, and usable for the first order.
        $this->assertTrue($this->storefront->customers()->where('organisations.uuid', substr($id, 4))->exists());
        $this->api('GET', '/customers/'.$id)->assertOk()->assertJsonPath('id', $id);
        $this->assertSame($id, $this->order(['payment_status' => 'paid'], $id)['customer_id']);

        $this->api('POST', '/customers', ['company' => 'X'], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);
    }

    public function test_confirming_a_bank_transfer_activates_the_pending_license_once(): void
    {
        $order = $this->order();
        $this->assertSame('pending', $order['payment_status']);
        $this->assertSame('pending', $order['license']['status']);
        $this->assertNull($order['paid_at']);

        $res = $this->api('POST', '/orders/'.$order['id'].'/confirm-payment', ['payment_reference' => 'NEFT-778', 'paid_at' => '2026-11-25T10:00:00Z'],
            ['X-Customer-Id' => $order['customer_id'], 'X-Acting-User' => 'admin@musicdesk.test'])->assertOk()
            ->assertJsonPath('payment_status', 'paid')->assertJsonPath('payment_reference', 'NEFT-778')
            ->assertJsonPath('paid_at', '2026-11-25T10:00:00Z')
            ->assertJsonPath('license.status', 'active')->assertJsonPath('license.pending_until', null);

        $license = SoftwareLicense::query()->where('uuid', substr($order['license']['id'], 4))->first();
        $this->assertSame(SoftwareLicense::ACTIVE, $license->status);
        $this->assertTrue($license->events()->where('event', 'payment_confirmed')->exists());
        $this->assertContains('license.updated', $this->webhookTypes());

        // Again: unchanged, still 200.
        $this->api('POST', '/orders/'.$order['id'].'/confirm-payment', [], ['X-Customer-Id' => $order['customer_id']])->assertOk()
            ->assertJsonPath('payment_reference', 'NEFT-778');
        $this->assertSame(1, $license->events()->where('event', 'payment_confirmed')->count());
        $this->assertSame($res->json('paid_at'), '2026-11-25T10:00:00Z');
    }

    public function test_a_late_payment_still_activates_an_expired_pending_license(): void
    {
        $order = $this->order();
        SoftwareLicense::query()->where('uuid', substr($order['license']['id'], 4))->update(['pending_until' => now()->subDay()]);
        $this->api('GET', '/licenses/'.$order['license']['id'], [], ['X-Customer-Id' => $order['customer_id']])->assertJsonPath('status', 'expired');

        $this->api('POST', '/orders/'.$order['id'].'/confirm-payment', [], ['X-Customer-Id' => $order['customer_id']])->assertOk()->assertJsonPath('license.status', 'active');
    }

    public function test_refunding_a_new_license_order_revokes_the_license(): void
    {
        $order = $this->order(['payment_status' => 'paid', 'payment_provider' => 'razorpay', 'payment_reference' => 'pay_1']);
        $this->assertSame('active', $order['license']['status']);

        $this->api('POST', '/orders/'.$order['id'].'/refund', ['refund_reference' => 'rfnd_9', 'reason' => '30-day money-back guarantee'], ['X-Customer-Id' => $order['customer_id']])
            ->assertOk()->assertJsonPath('payment_status', 'refunded')->assertJsonPath('refund_reference', 'rfnd_9')
            ->assertJsonPath('license.status', 'revoked')->assertJsonPath('license.status_reason', '30-day money-back guarantee');

        $this->assertContains('license.revoked', $this->webhookTypes());
        $this->assertNotNull(SoftwareOrder::query()->first()->refunded_at);

        // Idempotent; and a refunded order can't be confirmed.
        $this->api('POST', '/orders/'.$order['id'].'/refund', [], ['X-Customer-Id' => $order['customer_id']])->assertOk()->assertJsonPath('refund_reference', 'rfnd_9');
        $this->api('POST', '/orders/'.$order['id'].'/confirm-payment', [], ['X-Customer-Id' => $order['customer_id']])->assertStatus(409)->assertJsonPath('error.code', 'order_refunded');
    }

    public function test_refunding_a_renewal_keeps_the_license_and_records_it(): void
    {
        $first = $this->order(['payment_status' => 'paid']);
        $renewal = $this->api('POST', '/orders', [
            'kind' => 'renewal', 'license_id' => $first['license']['id'], 'amount' => ['amount' => 4599900, 'currency' => 'INR'], 'payment_reference' => 'pay_2',
        ], ['Idempotency-Key' => (string) Str::uuid(), 'X-Customer-Id' => $first['customer_id']])->assertCreated()->json();

        $this->api('POST', '/orders/'.$renewal['id'].'/refund', ['reason' => 'Duplicate renewal'], ['X-Customer-Id' => $first['customer_id']])
            ->assertOk()->assertJsonPath('payment_status', 'refunded')->assertJsonPath('license.status', 'active');

        $license = SoftwareLicense::query()->where('uuid', substr($first['license']['id'], 4))->first();
        $this->assertTrue($license->events()->where('event', 'order_refunded')->exists());
    }

    public function test_orders_of_another_customer_or_storefront_are_not_found(): void
    {
        $order = $this->order();
        $other = $this->api('POST', '/customers', ['company' => 'Other', 'email' => 'o@o.test', 'country' => 'US'], ['Idempotency-Key' => (string) Str::uuid()])->json('id');

        $this->api('POST', '/orders/'.$order['id'].'/confirm-payment', [], ['X-Customer-Id' => $other])->assertNotFound()->assertJsonPath('error.code', 'order_not_found');
        $this->api('POST', '/orders/ord_'.Str::uuid().'/refund')->assertNotFound();
        $this->api('POST', '/orders/not-an-id/refund')->assertNotFound();

        [, $otherKey] = app(StorefrontService::class)->create('other', 'Other', 'https://other.test/hook', $this->superAdmin());
        $this->json('POST', '/api/v1/storefront/orders/'.$order['id'].'/confirm-payment', [], ['Authorization' => 'Bearer '.$otherKey])->assertNotFound();
        $this->assertSame('pending', SoftwareOrder::query()->first()->payment_status);
    }
}
