<?php

namespace Tests\Feature\Licensing;

use App\Models\User;
use App\Modules\Licensing\Models\SoftwareLicense;
use App\Modules\Licensing\Models\SoftwarePlan;
use App\Modules\Licensing\Models\SoftwareProduct;
use App\Modules\Licensing\Models\Storefront;
use App\Modules\Licensing\Services\LicenseService;
use App\Modules\Licensing\Services\StorefrontService;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LicensingAdminTest extends TestCase
{
    private User $admin;

    private SoftwareProduct $product;

    private SoftwarePlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->superAdmin();
        [$storefront] = app(StorefrontService::class)->create('musicdesk', 'MusicDesk', null, $this->admin);
        $this->product = SoftwareProduct::query()->create(['storefront_id' => $storefront->id, 'code' => 'labeldesk', 'name' => 'LabelDesk']);
        $this->plan = SoftwarePlan::query()->create(['software_product_id' => $this->product->id, 'code' => 'business', 'name' => 'Business', 'included_domains' => 3]);
    }

    private function license(Organisation $organisation): SoftwareLicense
    {
        return app(LicenseService::class)->create($organisation, $this->product, $this->plan, ['billing_type' => 'annual', 'currency' => 'USD'], $this->admin, 'test');
    }

    public function test_super_admin_manages_licenses_from_the_portal(): void
    {
        $organisation = Organisation::factory()->create();
        $this->actingAs($this->admin);

        foreach (['licensing.index', 'licensing.create', 'licensing.products', 'licensing.storefronts'] as $route) {
            $this->get(route($route))->assertOk();
        }

        $this->post(route('licensing.store'), ['organisation' => $organisation->uuid, 'plan' => $this->plan->uuid, 'billing_type' => 'lifetime',
            'currency' => 'inr', 'price_paid' => '25000', 'domains_extra' => 1, 'reason' => 'Bank transfer sale'])->assertRedirect();

        $license = SoftwareLicense::query()->sole();
        $this->assertSame(['lifetime', 'INR', 2500000, 4, null], [$license->billing_type, $license->currency, $license->price_paid_minor, $license->domainLimit(), $license->expires_at]);

        $this->get(route('licensing.show', $license))->assertOk()->assertSee($license->number)->assertDontSee((string) $license->key_encrypted);
        $this->get(route('organisations.show', $organisation))->assertOk()->assertSee($license->number);

        $this->post(route('licensing.activations.store', $license), ['domain' => 'portal.acme.com'])->assertSessionHasNoErrors();
        $this->assertSame(1, $license->activations()->count());

        $this->post(route('licensing.status', $license), ['status' => 'suspended', 'reason' => 'Chargeback'])->assertSessionHasNoErrors();
        $this->assertSame('suspended', $license->fresh()->status);
        $this->post(route('licensing.status', $license), ['status' => 'active', 'reason' => 'Resolved'])->assertSessionHasNoErrors();

        $this->put(route('licensing.update', $license), ['plan' => $this->plan->uuid, 'domains_extra' => 3, 'support_until' => '2030-01-01', 'auto_renew' => '0', 'reason' => 'Goodwill'])
            ->assertSessionHasNoErrors();
        $this->assertSame([3, '2030-01-01'], [$license->fresh()->domains_extra, $license->fresh()->support_until->inDisplayTimezone()->toDateString()]);

        $this->post(route('licensing.reveal', $license))->assertOk()->assertSee((string) $license->key_encrypted)->assertHeaderContains('Cache-Control', 'no-store');
        $this->assertDatabaseHas('audit_logs', ['action' => 'licensing.license_key_revealed', 'organisation_id' => $organisation->id]);
    }

    public function test_organisations_only_see_their_own_licenses_read_only(): void
    {
        [$mine, $theirs] = Organisation::factory()->count(2)->create();
        $own = $this->license($mine);
        $foreign = $this->license($theirs);
        $manager = $this->organisationManager($mine);

        $this->actingAs($manager)->get(route('licensing.index'))->assertOk()->assertSee($own->number)->assertDontSee($foreign->number);
        $this->get(route('licensing.show', $own))->assertOk()->assertDontSee('Revoke');
        $this->get(route('licensing.show', $foreign))->assertNotFound();
        $this->post(route('licensing.reveal', $foreign))->assertNotFound();

        // The customer may see its own key (audited) but not change the license.
        $this->post(route('licensing.reveal', $own))->assertOk();
        $this->post(route('licensing.status', $own), ['status' => 'revoked', 'reason' => 'x x x'])->assertForbidden();
        $this->post(route('licensing.activations.store', $own), ['domain' => 'x.example.com'])->assertForbidden();
        $this->get(route('licensing.create'))->assertForbidden();
        $this->get(route('licensing.storefronts'))->assertForbidden();
        $this->get(route('licensing.products'))->assertForbidden();

        $this->assertSame('active', $own->fresh()->status);
    }

    public function test_storefront_secrets_are_shown_once_and_never_stored_readably(): void
    {
        $response = $this->actingAs($this->admin)->post(route('licensing.storefronts.store'), ['name' => 'Other', 'code' => 'other', 'webhook_url' => 'https://other.example.com/hook'])
            ->assertOk()->assertHeaderContains('Cache-Control', 'no-store')->assertSee('WCC_BILLING_KEY=sk_live_', false);

        preg_match('/WCC_BILLING_KEY=(sk_live_[A-Za-z0-9]+)/', $response->getContent(), $m);
        $storefront = Storefront::query()->where('code', 'other')->sole();

        $this->assertSame(hash('sha256', $m[1]), $storefront->api_key_hash);
        $this->assertSame(0, DB::table('storefronts')->where('api_key_hash', $m[1])->count());
        $this->assertSame(0, DB::table('audit_logs')->where('new_data', 'like', '%'.$m[1].'%')->count());
        $this->get(route('licensing.storefronts'))->assertOk()->assertDontSee($m[1]);
    }
}
