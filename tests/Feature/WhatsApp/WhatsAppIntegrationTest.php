<?php

namespace Tests\Feature\WhatsApp;

use App\Modules\Notifications\NotificationEvents;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\WhatsApp\Clients\LoggingWhatsAppClient;
use App\Modules\WhatsApp\Clients\MockWhatsAppClient;
use App\Modules\WhatsApp\Clients\WhatsAppClient;
use App\Modules\WhatsApp\Contracts\WhatsAppClientInterface;
use App\Modules\WhatsApp\Services\PhoneNumber;
use App\Modules\WhatsApp\Services\WhatsAppConnection;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GoogleWorkspace\WorkspaceTestCase;

class WhatsAppIntegrationTest extends WorkspaceTestCase
{
    private const APP_KEY = 'test-app-key-1234';

    private const AUTH_KEY = 'test-auth-key-5678';

    private function messages(): MockWhatsAppClient
    {
        return app(MockWhatsAppClient::class);
    }

    private function switchOn(): void
    {
        $admin = $this->superAdmin();
        $connection = app(WhatsAppConnection::class);
        $connection->saveKeys(self::APP_KEY, self::AUTH_KEY, $admin);
        $connection->saveSettings(true, WhatsAppConnection::defaultEvents(), $admin);
    }

    public function test_super_admin_saves_keys_that_are_never_shown_or_logged(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('integrations.whatsapp'))->assertOk()->assertSee('Not set');

        $this->post(route('integrations.whatsapp.keys'), ['appkey' => self::APP_KEY, 'authkey' => self::AUTH_KEY])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->get(route('integrations.whatsapp'))->assertOk()->assertSee('Set')
            ->assertDontSee(self::APP_KEY)->assertDontSee(self::AUTH_KEY);

        // Encrypted at rest, absent from the audit log.
        $raw = (string) DB::table('integration_settings')->where('provider', 'whatsapp')->value('secrets');
        $this->assertStringNotContainsString(self::AUTH_KEY, $raw);
        $this->assertSame(0, DB::table('audit_logs')->where('new_data', 'like', '%'.self::AUTH_KEY.'%')->count());
        $this->assertSame(self::AUTH_KEY, app(WhatsAppConnection::class)->keys()['authkey']);

        // A blank field keeps the stored key.
        $this->post(route('integrations.whatsapp.keys'), ['appkey' => 'new-app-key-9999', 'authkey' => '']);
        $this->assertSame(['appkey' => 'new-app-key-9999', 'authkey' => self::AUTH_KEY], app(WhatsAppConnection::class)->keys());

        $this->post(route('integrations.whatsapp.test'), ['to' => '98480 12345'])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->messages()->messagesTo('919848012345'));
    }

    public function test_only_super_admins_reach_the_whatsapp_settings(): void
    {
        $organisation = Organisation::factory()->create();

        foreach ([$this->organisationManager($organisation), $this->operationsAdmin($organisation)] as $user) {
            $this->actingAs($user)->get(route('integrations.whatsapp'))->assertForbidden();
            $this->post(route('integrations.whatsapp.keys'), ['appkey' => 'x-x-x-x-x', 'authkey' => 'y-y-y-y-y'])->assertForbidden();
            $this->post(route('integrations.whatsapp.test'), ['to' => '919848012345'])->assertForbidden();
        }

        $this->assertFalse(app(WhatsAppConnection::class)->hasKeys());
    }

    public function test_notifications_go_to_the_organisation_phone_and_its_own_users_only(): void
    {
        $this->switchOn();
        [$organisation] = $this->organisationWithDomain('acme.test');
        $organisation->forceFill(['contact_phone' => '+91 90000 00001'])->save();
        [$other] = $this->organisationWithDomain('other.test');

        $manager = $this->organisationManager($organisation);
        $manager->forceFill(['phone' => '919000000002'])->save();
        $ops = $this->operationsAdmin($organisation);
        $ops->forceFill(['phone' => '919000000003'])->save();
        $optedOut = $this->organisationManager($organisation);
        $optedOut->forceFill(['phone' => '919000000004', 'notification_preferences' => ['account.suspended' => ['whatsapp' => false]]])->save();
        $stranger = $this->organisationManager($other);
        $stranger->forceFill(['phone' => '919000000005'])->save();
        $superAdmin = $this->superAdmin();
        $superAdmin->forceFill(['phone' => '919000000006'])->save();

        // Each user sees the WhatsApp column and can set their own number.
        $this->actingAs($manager)->get(route('account.notifications'))->assertOk()->assertSee('WhatsApp number')->assertSee('919000000002');
        $this->post(route('account.notifications.save'), ['phone' => '+91 90000 00012', 'whatsapp' => ['account.suspended']])->assertSessionHasNoErrors();
        $this->assertSame('919000000012', $manager->fresh()->phone);
        $manager->forceFill(['phone' => '919000000002'])->save();

        $account = $this->existingAccount($organisation, 'box@acme.test');
        $account->forceFill(['suspension_reason' => 'Test'])->save();
        app(NotificationEvents::class)->accountSuspended($account);

        $numbers = collect($this->messages()->sent)->pluck('to')->sort()->values()->all();
        $this->assertSame(['919000000001', '919000000002', '919000000003'], $numbers);
        $this->assertStringContainsString('box@acme.test', $this->messages()->sent[0]['message']);
    }

    public function test_nothing_is_sent_while_switched_off_or_for_unselected_events(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        $organisation->forceFill(['contact_phone' => '919000000001'])->save();
        $account = $this->existingAccount($organisation, 'box@acme.test');

        app(NotificationEvents::class)->accountSuspended($account);
        $this->assertSame([], $this->messages()->sent, 'No keys, not enabled.');

        $admin = $this->superAdmin();
        app(WhatsAppConnection::class)->saveKeys(self::APP_KEY, self::AUTH_KEY, $admin);
        app(WhatsAppConnection::class)->saveSettings(true, ['invoice.issued'], $admin);
        app(NotificationEvents::class)->accountSuspended($account);
        $this->assertSame([], $this->messages()->sent, 'Event not selected.');
    }

    public function test_live_client_posts_the_documented_form_fields(): void
    {
        $this->switchOn();
        config(['wcc.whatsapp.endpoint' => 'https://api.whatsapp.example.test/api/create-message']);
        Http::preventStrayRequests();
        Http::fake(['https://api.whatsapp.example.test/*' => Http::response(['status' => 'success'])]);

        (new WhatsAppClient(app(Factory::class), app(WhatsAppConnection::class)))->send('919848012345', 'Hello');

        Http::assertSent(function (Request $request) {
            $fields = collect($request->data())->mapWithKeys(fn ($part) => [$part['name'] => $part['contents']]);

            return $request->method() === 'POST' && $request->isMultipart()
                && $fields->all() == ['appkey' => self::APP_KEY, 'authkey' => self::AUTH_KEY, 'to' => '919848012345', 'message' => 'Hello'];
        });
    }

    public function test_tests_never_reach_the_live_api_and_numbers_are_normalised(): void
    {
        config(['wcc.whatsapp.enabled' => true]);
        app()->forgetInstance(WhatsAppClientInterface::class);
        $client = app(WhatsAppClientInterface::class);
        $this->assertInstanceOf(LoggingWhatsAppClient::class, $client);
        $this->assertInstanceOf(MockWhatsAppClient::class, $client->inner());

        $this->assertSame('919848012345', PhoneNumber::normalise('+91 98480 12345'));
        $this->assertSame('919848012345', PhoneNumber::normalise('09848012345'));
        $this->assertSame('971501234567', PhoneNumber::normalise('+971 50 123 4567'));
        $this->assertNull(PhoneNumber::normalise('12345'));
    }
}
