<?php

namespace Tests\Feature\Licensing;

use App\Models\User;
use App\Modules\Licensing\Models\SoftwareLicense;
use App\Modules\Licensing\Models\SoftwarePlan;
use App\Modules\Licensing\Models\SoftwareProduct;
use App\Modules\Licensing\Models\SoftwareRelease;
use App\Modules\Licensing\Services\StorefrontService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * MusicDesk integration doc §7.5 (USD value of orders) and §7.6 (release
 * download URLs): single-use 15-minute links, only for versions the license
 * is entitled to.
 */
class DownloadsAndUsdTest extends TestCase
{
    private User $admin;

    private string $key;

    private SoftwareProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->admin = $this->superAdmin();
        [$storefront, $this->key] = app(StorefrontService::class)->create('musicdesk', 'MusicDesk', null, $this->admin);
        $this->product = SoftwareProduct::query()->create(['storefront_id' => $storefront->id, 'code' => 'labeldesk', 'name' => 'LabelDesk']);
        SoftwarePlan::query()->create(['software_product_id' => $this->product->id, 'code' => 'business', 'name' => 'Business', 'included_domains' => 3]);
    }

    /** @param  array<string, string>  $headers */
    private function api(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json($method, '/api/v1/storefront'.$uri, $data, ['Authorization' => 'Bearer '.$this->key] + $headers);
    }

    /** @return array<string, mixed> */
    private function buy(array $amount = ['amount' => 10832046, 'currency' => 'INR'], ?int $usd = 129900, string $email = 'ravi@bassline.test'): array
    {
        return $this->api('POST', '/orders', array_filter([
            'kind' => 'new_license',
            'customer' => ['name' => 'Ravi', 'email' => $email, 'company' => 'Bassline '.Str::random(4), 'country' => 'IN'],
            'product' => 'labeldesk', 'plan_code' => 'business', 'billing_type' => 'annual',
            'amount' => $amount, 'amount_usd' => $usd,
        ], fn ($v) => $v !== null), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json();
    }

    private function release(string $version, int $daysAgo, bool $withPackage = true): SoftwareRelease
    {
        $this->actingAs($this->admin)->post(route('licensing.releases.store', $this->product->uuid), array_filter([
            'version' => $version, 'channel' => 'stable', 'released_at' => now()->subDays($daysAgo)->toDateString(),
            'package' => $withPackage ? UploadedFile::fake()->createWithContent("labeldesk-{$version}.zip", 'PK zip bytes '.$version) : null,
        ]))->assertSessionHasNoErrors();

        return SoftwareRelease::query()->where('version', $version)->sole();
    }

    public function test_orders_record_the_usd_value(): void
    {
        $inr = $this->buy();
        $this->assertSame(['amount' => 129900, 'currency' => 'USD'], $inr['amount_usd']);
        $this->assertSame(['amount' => 10832046, 'currency' => 'INR'], $inr['amount']);

        // A USD order is its own USD value; an order without it stays null.
        $this->assertSame(49900, $this->buy(['amount' => 49900, 'currency' => 'USD'], null, 'b@x.test')['amount_usd']['amount']);
        $this->assertNull($this->buy(['amount' => 4000000, 'currency' => 'INR'], null, 'c@x.test')['amount_usd']);
    }

    public function test_entitled_license_downloads_once_through_a_short_lived_link(): void
    {
        $release = $this->release('4.3.0', 5);
        $this->assertSame(hash('sha256', 'PK zip bytes 4.3.0'), $release->sha256, 'Checksum worked out from the uploaded file.');
        $this->assertSame(strlen('PK zip bytes 4.3.0'), $release->size_bytes);
        Storage::disk('local')->assertExists($release->package_path);

        $order = $this->buy();
        $headers = ['X-Customer-Id' => $order['customer_id']];
        $this->assertSame('4.3.0', $order['license']['latest_available_version']);

        $link = $this->api('POST', "/licenses/{$order['license']['id']}/downloads", ['version' => '4.3.0'], $headers)
            ->assertCreated()->assertJsonPath('sha256', $release->sha256)->assertJsonPath('version', '4.3.0')->json();

        $this->assertStringStartsWith(url('/downloads/'), $link['url']);
        $this->assertSame(0, DB::table('software_download_links')->where('token_hash', basename($link['url']))->count(), 'Only the hash is stored.');

        $download = $this->get($link['url'])->assertOk();
        $this->assertSame('PK zip bytes 4.3.0', $download->streamedContent());
        $this->get($link['url'])->assertStatus(410);

        // A new link expires after 15 minutes.
        $late = $this->api('POST', "/licenses/{$order['license']['id']}/downloads", ['version' => '4.3.0'], $headers)->json('url');
        $this->travel(16)->minutes();
        $this->get($late)->assertStatus(410);

        $this->get('/downloads/'.str_repeat('a', 64))->assertStatus(410);
        $this->assertDatabaseHas('software_license_events', ['event' => 'downloaded']);
    }

    public function test_versions_after_support_until_are_refused(): void
    {
        $this->release('4.2.0', 200);
        $this->release('4.3.0', 5);
        $order = $this->buy();
        $headers = ['X-Customer-Id' => $order['customer_id']];

        // Updates lapsed 100 days ago: 4.2.0 yes, 4.3.0 no.
        SoftwareLicense::query()->sole()->forceFill(['support_until' => now()->subDays(100)])->save();

        $this->api('GET', "/licenses/{$order['license']['id']}", [], $headers)->assertJsonPath('latest_available_version', '4.2.0');
        $this->api('POST', "/licenses/{$order['license']['id']}/downloads", ['version' => '4.3.0'], $headers)
            ->assertForbidden()->assertJsonPath('error.code', 'updates_expired');
        $this->api('POST', "/licenses/{$order['license']['id']}/downloads", ['version' => '4.2.0'], $headers)->assertCreated();
    }

    public function test_refusals_missing_package_suspended_and_foreign_license(): void
    {
        $this->release('4.3.0', 5, withPackage: false);
        $mine = $this->buy();
        $theirs = $this->buy(email: 'other@x.test');
        $headers = ['X-Customer-Id' => $mine['customer_id']];

        $this->api('POST', "/licenses/{$mine['license']['id']}/downloads", ['version' => '4.3.0'], $headers)->assertStatus(409)->assertJsonPath('error.code', 'package_unavailable');
        $this->api('POST', "/licenses/{$mine['license']['id']}/downloads", ['version' => '9.9.9'], $headers)->assertNotFound();
        $this->api('POST', "/licenses/{$theirs['license']['id']}/downloads", ['version' => '4.3.0'], $headers)->assertNotFound();

        $this->actingAs($this->admin)->post(route('licensing.releases.package', SoftwareRelease::query()->sole()->uuid),
            ['package' => UploadedFile::fake()->createWithContent('labeldesk.zip', 'bytes')])->assertSessionHasNoErrors();

        $link = $this->api('POST', "/licenses/{$mine['license']['id']}/downloads", ['version' => '4.3.0'], $headers)->assertCreated()->json('url');

        // Suspended after the link was issued: the link no longer works.
        SoftwareLicense::query()->where('uuid', substr($mine['license']['id'], 4))->update(['status' => 'suspended']);
        $this->get($link)->assertStatus(410);
        $this->api('POST', "/licenses/{$mine['license']['id']}/downloads", ['version' => '4.3.0'], $headers)->assertForbidden();

        // Only Super Admins upload packages.
        $this->actingAs($this->organisationManager())->post(route('licensing.releases.package', SoftwareRelease::query()->sole()->uuid),
            ['package' => UploadedFile::fake()->createWithContent('x.zip', 'x')])->assertForbidden();
    }
}
