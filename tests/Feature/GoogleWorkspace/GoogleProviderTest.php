<?php

namespace Tests\Feature\GoogleWorkspace;

use App\Modules\GoogleWorkspace\Contracts\WorkspaceProviderInterface;
use App\Modules\GoogleWorkspace\Data\NewWorkspaceUser;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceConflictException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceNotConfiguredException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceRateLimitException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceTransientException;
use App\Modules\GoogleWorkspace\Providers\GoogleAccessTokenProvider;
use App\Modules\GoogleWorkspace\Providers\GoogleWorkspaceProvider;
use App\Modules\GoogleWorkspace\Providers\LoggingWorkspaceProvider;
use App\Modules\GoogleWorkspace\Providers\MockWorkspaceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The real Google client, exercised against a faked HTTP layer. No request
 * leaves the machine: stray requests are forbidden.
 */
class GoogleProviderTest extends WorkspaceTestCase
{
    private const BASE = 'https://admin.googleapis.com/admin/directory/v1';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function provider(): GoogleWorkspaceProvider
    {
        $tokens = new class(app(Factory::class), app('cache.store'), ['service_account_json' => null, 'admin_email' => null, 'delegation_enabled' => false]) extends GoogleAccessTokenProvider
        {
            public function token(): string
            {
                return 'test-access-token';
            }
        };

        return new GoogleWorkspaceProvider(app(Factory::class), $tokens, 'my_customer');
    }

    private function userPayload(string $id, string $email, bool $suspended = false): array
    {
        return ['id' => $id, 'primaryEmail' => $email, 'name' => ['givenName' => 'Given', 'familyName' => 'Family'],
            'suspended' => $suspended, 'orgUnitPath' => '/', 'creationTime' => '2024-05-01T10:00:00.000Z'];
    }

    public function test_the_test_suite_can_never_reach_live_google(): void
    {
        config(['wcc.google.enabled' => true]);
        app()->forgetInstance(WorkspaceProviderInterface::class);

        $provider = app(WorkspaceProviderInterface::class);

        $this->assertInstanceOf(LoggingWorkspaceProvider::class, $provider);
        $this->assertInstanceOf(MockWorkspaceProvider::class, $provider->inner());
        $this->assertSame('mock', $provider->name());
    }

    public function test_list_users_requests_the_whole_customer_and_follows_page_tokens(): void
    {
        Http::fake([
            self::BASE.'/users?*pageToken=NEXT*' => Http::response(['users' => [$this->userPayload('2', 'B@Example.com')]]),
            self::BASE.'/users?*' => Http::response(['users' => [$this->userPayload('1', 'a@example.com', true)], 'nextPageToken' => 'NEXT']),
        ]);

        $first = $this->provider()->listUsers();
        $second = $this->provider()->listUsers($first->nextPageToken);

        $this->assertSame('NEXT', $first->nextPageToken);
        $this->assertNull($second->nextPageToken);
        $this->assertTrue($first->users[0]->suspended);
        $this->assertSame('b@example.com', $second->users[0]->primaryEmail);
        $this->assertSame('2024-05-01', $first->users[0]->createdAt->toDateString());

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), 'customer=my_customer')
            && str_contains($request->url(), 'maxResults=500')
            && $request->hasHeader('Authorization', 'Bearer test-access-token'));
    }

    public function test_create_user_sends_the_documented_payload(): void
    {
        Http::fake([self::BASE.'/users' => Http::response($this->userPayload('123', 'new@example.com'))]);

        $user = $this->provider()->createUser(new NewWorkspaceUser('new@example.com', 'New', 'Person', 'S3cret!Passw0rd', '/Staff', null, '+919876543210'));

        $this->assertSame('123', $user->id);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && $body['primaryEmail'] === 'new@example.com'
                && $body['name'] === ['givenName' => 'New', 'familyName' => 'Person']
                && $body['password'] === 'S3cret!Passw0rd'
                && $body['changePasswordAtNextLogin'] === true
                && $body['orgUnitPath'] === '/Staff'
                && $body['recoveryPhone'] === '+919876543210'
                && ! array_key_exists('recoveryEmail', $body);
        });
    }

    public function test_suspend_sign_out_delete_and_undelete_use_the_documented_methods(): void
    {
        Http::fake([
            self::BASE.'/users/123/signOut' => Http::response('', 204),
            self::BASE.'/users/123/undelete' => Http::response('', 204),
            self::BASE.'/users/123' => Http::response($this->userPayload('123', 'a@example.com', true)),
        ]);

        $provider = $this->provider();
        $this->assertTrue($provider->suspendUser('123')->suspended);
        $provider->signOutUser('123');
        $provider->restoreUser('123', '/Staff');
        $provider->deleteUser('123');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['suspended' => true]);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/users/123/signOut') && $r->body() === '');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/users/123/undelete') && $r->data() === ['orgUnitPath' => '/Staff']);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/users/123'));
    }

    public function test_domains_and_org_units_are_mapped(): void
    {
        Http::fake([
            self::BASE.'/customer/my_customer/domains' => Http::response(['domains' => [
                ['domainName' => 'Example.com', 'isPrimary' => true, 'verified' => true],
                ['domainName' => 'second.example', 'isPrimary' => false, 'verified' => false],
            ]]),
            self::BASE.'/customer/my_customer/orgunits*' => Http::response(['organizationUnits' => [
                ['orgUnitId' => 'id:abc', 'name' => 'Sales', 'orgUnitPath' => '/Sales', 'parentOrgUnitPath' => '/'],
            ]]),
        ]);

        $domains = $this->provider()->listDomains();
        $units = $this->provider()->listOrgUnits();

        $this->assertSame('example.com', $domains[0]->name);
        $this->assertTrue($domains[0]->isPrimary);
        $this->assertFalse($domains[1]->verified);
        $this->assertSame('/Sales', $units[0]->path);
    }

    public function test_http_errors_are_mapped_to_retryable_and_permanent_exceptions(): void
    {
        $cases = [
            [Http::response(['error' => ['message' => 'Rate Limit Exceeded']], 429, ['Retry-After' => '17']), WorkspaceRateLimitException::class],
            [Http::response(['error' => ['message' => 'Quota', 'errors' => [['reason' => 'userRateLimitExceeded']]]], 403), WorkspaceRateLimitException::class],
            [Http::response(['error' => ['message' => 'Backend Error']], 503), WorkspaceTransientException::class],
            [Http::response(['error' => ['message' => 'Entity already exists.']], 409), WorkspaceConflictException::class],
            [Http::response(['error' => ['message' => 'Not Authorized', 'errors' => [['reason' => 'forbidden']]]], 403), WorkspaceException::class],
            [Http::response(['error' => ['message' => 'Invalid Input']], 400), WorkspaceException::class],
        ];

        foreach ($cases as [$response, $expected]) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake([self::BASE.'/*' => $response]);

            try {
                $this->provider()->suspendUser('123');
                $this->fail("Expected {$expected}");
            } catch (WorkspaceException $e) {
                $this->assertSame($expected, $e::class, $e->getMessage());

                if ($e instanceof WorkspaceRateLimitException && $response === $cases[0][0]) {
                    $this->assertSame(17, $e->retryAfter);
                }
            }
        }
    }

    public function test_not_found_on_get_is_null_and_connection_failures_are_transient(): void
    {
        Http::fake([self::BASE.'/users/missing*' => Http::response(['error' => ['message' => 'Resource Not Found: userKey']], 404)]);
        $this->assertNull($this->provider()->getUser('missing@example.com'));

        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->expectException(WorkspaceTransientException::class);
        $this->provider()->listDomains();
    }

    public function test_missing_service_account_key_is_reported_without_any_request(): void
    {
        Http::fake();
        $tokens = new GoogleAccessTokenProvider(app(Factory::class), app('cache.store'),
            ['service_account_json' => storage_path('does-not-exist.json'), 'admin_email' => null, 'delegation_enabled' => false]);

        try {
            $tokens->token();
            $this->fail('A missing key file must be reported.');
        } catch (WorkspaceNotConfiguredException $e) {
            $this->assertStringContainsString('missing or unreadable', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_integration_screen_shows_mock_mode_and_never_credentials(): void
    {
        config(['wcc.google.service_account_json' => 'C:/secret/path/key.json', 'wcc.google.admin_email' => 'admin@secret.example']);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/integrations/google')
            ->assertOk()
            ->assertSee('Mock mode')
            ->assertDontSee('C:/secret/path/key.json')
            ->assertDontSee('admin@secret.example');

        $this->post('/integrations/google/test')->assertSessionHas('success');
        $this->assertDatabaseHas('integration_settings', ['provider' => 'google_workspace', 'last_test_status' => 'success']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'integration.google.tested', 'user_id' => $admin->id]);

        $this->get('/integrations/logs')->assertOk()->assertSee('users.list');
        $this->get('/workspace')->assertOk()->assertSee('Mock mode');
    }
}
