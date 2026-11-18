<?php

namespace Tests\Feature\Zoho;

use App\Models\User;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Zoho\Clients\MockZohoBooksClient;
use App\Modules\Zoho\Clients\ZohoBooksClient;
use App\Modules\Zoho\Models\ZohoMapping;
use App\Modules\Zoho\Services\ZohoConnection;
use App\Modules\Zoho\Services\ZohoSync;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Customers that already exist in Zoho Books are linked to organisations
 * (no duplicate customer), and their details flow from Zoho into this
 * application — never the other way round.
 */
class ZohoCustomerLinkTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->superAdmin();
    }

    private function zoho(): MockZohoBooksClient
    {
        return app(MockZohoBooksClient::class);
    }

    private function existingCustomer(): string
    {
        return $this->zoho()->seedContact([
            'contact_name' => 'Sri Yash Enterprises',
            'company_name' => 'Sri Yash Enterprises Pvt Ltd',
            'gst_no' => '36AABCS1234K1Z5',
            'email' => 'accounts@sriyash.test',
            'billing_address' => ['address' => '1-2-3 Main Road', 'street2' => 'Ameerpet', 'city' => 'Hyderabad', 'state' => 'Telangana', 'zip' => '500016', 'country' => 'India'],
            'contact_persons' => [['first_name' => 'Ravi', 'last_name' => 'Kumar', 'email' => 'ravi@sriyash.test', 'phone' => '+91 98480 00000', 'is_primary_contact' => true]],
        ]);
    }

    public function test_super_admin_links_an_existing_zoho_customer_and_imports_its_details(): void
    {
        $contactId = $this->existingCustomer();
        $organisation = Organisation::factory()->create(['name' => 'Sri yash test', 'code' => 'SRIYASH', 'gstin' => null, 'pan' => null]);

        $this->actingAs($this->admin)->get(route('integrations.zoho.customers'))->assertOk()
            ->assertSee('Sri Yash Enterprises')->assertSee('Link');

        $this->from(route('integrations.zoho.customers'))
            ->post(route('integrations.zoho.customers.link', $contactId), ['organisation' => $organisation->uuid, 'import_details' => '1'])
            ->assertRedirect(route('integrations.zoho.customers'))
            ->assertSessionHas('success');

        $mapping = app(ZohoSync::class)->mapping($organisation);
        $this->assertSame($contactId, $mapping->zoho_id);
        $this->assertSame(ZohoMapping::ORIGIN_ZOHO, $mapping->origin);

        $organisation->refresh();
        $this->assertSame('Sri yash test', $organisation->name);                // our name is kept
        $this->assertSame('Sri Yash Enterprises Pvt Ltd', $organisation->legal_name);
        $this->assertSame('36AABCS1234K1Z5', $organisation->gstin);
        $this->assertSame('AABCS1234K', $organisation->pan);                   // taken from the GSTIN
        $this->assertSame("1-2-3 Main Road\nAmeerpet", $organisation->billing_address);
        $this->assertSame(['Hyderabad', 'Telangana', '500016', 'IN'], [$organisation->city, $organisation->state, $organisation->postal_code, $organisation->country]);
        $this->assertSame('accounts@sriyash.test', $organisation->billing_email);
        $this->assertSame('Ravi Kumar', $organisation->primary_contact_name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'zoho.customer_linked', 'organisation_id' => $organisation->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organisation.updated', 'organisation_id' => $organisation->id]);

        // The page now shows the link.
        $this->get(route('integrations.zoho.customers'))->assertOk()->assertSee('Update details from Zoho');

        // Sending an invoice later uses this customer: no new contact, and the Zoho contact is not overwritten.
        $this->assertSame($contactId, app(ZohoSync::class)->syncCustomer($organisation));
        $this->assertSame(0, $this->zoho()->callCount('contacts.create'));
        $this->assertSame(0, $this->zoho()->callCount('contacts.update'));
        $this->assertSame('Sri Yash Enterprises', $this->zoho()->rawContact($contactId)['contact_name']);
    }

    public function test_details_can_be_refreshed_from_zoho(): void
    {
        $contactId = $this->existingCustomer();
        $organisation = Organisation::factory()->create(['gstin' => null]);
        app(ZohoSync::class)->linkCustomer($organisation, $contactId, $this->admin, importDetails: false);
        $this->assertNull($organisation->fresh()->gstin);

        $this->actingAs($this->admin)->from(route('integrations.zoho.customers'))
            ->post(route('integrations.zoho.customers.import', $organisation))
            ->assertRedirect(route('integrations.zoho.customers'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'gstin'));

        $this->assertSame('36AABCS1234K1Z5', $organisation->fresh()->gstin);
    }

    public function test_one_zoho_customer_cannot_be_linked_to_two_organisations(): void
    {
        $contactId = $this->existingCustomer();
        $first = Organisation::factory()->create();
        $second = Organisation::factory()->create();
        app(ZohoSync::class)->linkCustomer($first, $contactId, $this->admin, importDetails: false);

        $this->actingAs($this->admin)->from(route('integrations.zoho.customers'))
            ->post(route('integrations.zoho.customers.link', $contactId), ['organisation' => $second->uuid])
            ->assertSessionHasErrors('contact');

        $this->assertNull(app(ZohoSync::class)->mapping($second)?->zoho_id);
    }

    public function test_invalid_zoho_values_are_not_copied(): void
    {
        $data = ZohoSync::organisationDetailsFrom(ZohoBooksClient::normaliseContact([
            'contact_id' => '1', 'contact_name' => 'X', 'gst_no' => 'not-a-gstin', 'email' => 'nope',
            'billing_address' => ['country' => 'United Arab Emirates'],
        ]));

        $this->assertSame([], $data);
    }

    public function test_only_zoho_managers_reach_zoho_customers(): void
    {
        $contactId = $this->existingCustomer();
        $organisation = Organisation::factory()->create();
        $manager = $this->organisationManager($organisation);

        $this->actingAs($manager)->get(route('integrations.zoho.customers'))->assertForbidden();
        $this->post(route('integrations.zoho.customers.link', $contactId), ['organisation' => $organisation->uuid])->assertForbidden();
        $this->post(route('integrations.zoho.customers.import', $organisation))->assertForbidden();

        $this->assertNull(app(ZohoSync::class)->mapping($organisation)?->zoho_id);
    }

    public function test_live_client_lists_and_reads_contacts_as_documented(): void
    {
        config(['wcc.zoho.client_id' => 'id', 'wcc.zoho.client_secret' => 'secret', 'wcc.zoho.redirect_uri' => 'http://localhost/cb', 'wcc.zoho.accounts_base_url' => 'https://accounts.zoho.in']);
        $row = app(ZohoConnection::class)->settingRow();
        $row->secrets = ['refresh_token' => 'stored-refresh'];
        $row->settings = ['organization_id' => '600123', 'api_domain' => 'https://www.zohoapis.in'];
        $row->save();

        Http::preventStrayRequests();
        Http::fake([
            'https://accounts.zoho.in/oauth/v2/token' => Http::response(['access_token' => 'fresh-access', 'expires_in' => 3600]),
            'https://www.zohoapis.in/books/v3/contacts/460000000026049*' => Http::response(['code' => 0, 'contact' => [
                'contact_id' => '460000000026049', 'contact_name' => 'Bowman and Co', 'company_name' => 'Bowman and Co',
                'gst_no' => '22AAAAA0000A1Z5', 'legal_name' => 'Bowman and Company Pvt Ltd',
                'billing_address' => ['address' => '4900 Hopyard Rd', 'city' => 'Pleasanton', 'state' => 'CA', 'zip' => '94588', 'country' => 'U.S.A'],
                'contact_persons' => [['first_name' => 'Will', 'last_name' => 'Smith', 'email' => 'willsmith@bowmanfurniture.com', 'is_primary_contact' => true]],
            ]]),
            'https://www.zohoapis.in/books/v3/contacts?*' => Http::response(['code' => 0, 'contacts' => [
                ['contact_id' => '460000000026049', 'contact_name' => 'Bowman and Co', 'company_name' => 'Bowman and Co', 'status' => 'active',
                    'email' => 'willsmith@bowmanfurniture.com', 'outstanding_receivable_amount' => 250],
            ], 'page_context' => ['page' => 1, 'per_page' => 50, 'has_more_page' => true]]),
        ]);

        $client = new ZohoBooksClient(app(Factory::class), app(ZohoConnection::class));

        $list = $client->listContacts(1, 'Bowman');
        $this->assertTrue($list['has_more']);
        $this->assertSame(['460000000026049', 'Bowman and Co', '250'], [$list['contacts'][0]['contact_id'], $list['contacts'][0]['contact_name'], $list['contacts'][0]['outstanding']]);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://www.zohoapis.in/books/v3/contacts?')
            && str_contains($r->url(), 'contact_type=customer') && str_contains($r->url(), 'search_text=Bowman')
            && str_contains($r->url(), 'organization_id=600123'));

        $contact = $client->getContact('460000000026049');
        $this->assertSame('22AAAAA0000A1Z5', $contact['gst_no']);
        $this->assertSame('Will Smith', $contact['primary_contact']['name']);
        $this->assertSame('Bowman and Company Pvt Ltd', ZohoSync::organisationDetailsFrom($contact)['legal_name']);
    }
}
