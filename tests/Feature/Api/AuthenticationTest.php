<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Organisations\Models\Organisation;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    private const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    public function test_login_returns_a_bearer_token_and_profile(): void
    {
        $organisation = Organisation::factory()->create();
        $manager = $this->organisationManager($organisation);

        $response = $this->postJson('/api/v1/login', ['email' => $manager->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'token_type', 'expires_at', 'user' => ['id', 'name', 'email', 'organisations']]])
            ->assertJsonPath('data.user.id', $manager->uuid)
            ->assertJsonPath('data.user.organisations.0.id', $organisation->uuid)
            ->assertJsonPath('data.user.global_access', false);

        $this->assertNotNull($response->json('data.expires_at'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.api_login', 'user_id' => $manager->id]);
    }

    public function test_login_rejects_invalid_credentials_with_the_standard_error_envelope(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['email'], 'request_id']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_deactivated_user_cannot_obtain_a_token(): void
    {
        $user = User::factory()->inactive()->create();

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_user_with_two_factor_must_supply_a_valid_code(): void
    {
        $admin = $this->superAdmin();
        $credentials = ['email' => $admin->email, 'password' => 'password'];

        $this->postJson('/api/v1/login', $credentials)->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/login', $credentials + ['code' => '000000'])->assertStatus(422);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $code = app(Google2FA::class)->getCurrentOtp(self::TOTP_SECRET);

        $this->postJson('/api/v1/login', $credentials + ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.user.global_access', true);
    }

    public function test_privileged_user_without_two_factor_cannot_obtain_a_token(): void
    {
        $admin = $this->superAdmin(twoFactor: false);

        $this->postJson('/api/v1/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonStructure(['message', 'request_id']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_api_login_is_throttled(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(422);
        }

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(429);
    }

    public function test_profile_requires_authentication_and_answers_in_json(): void
    {
        $this->get('/api/v1/profile')
            ->assertUnauthorized()
            ->assertJsonStructure(['message', 'request_id']);
    }

    public function test_profile_exposes_uuids_and_never_internal_ids_or_secrets(): void
    {
        $organisation = Organisation::factory()->create();
        $manager = $this->organisationManager($organisation);
        $token = $manager->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/profile')->assertOk();

        $response->assertJsonPath('data.id', $manager->uuid);
        $body = $response->getContent();
        $this->assertStringNotContainsString('two_factor_secret', $body);
        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('"id":'.$manager->id.',', $body);
    }

    public function test_logout_revokes_the_token(): void
    {
        $manager = $this->organisationManager(Organisation::factory()->create());
        $token = $manager->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/logout')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/profile')->assertUnauthorized();
    }

    public function test_expired_tokens_are_rejected(): void
    {
        $manager = $this->organisationManager(Organisation::factory()->create());
        $token = $manager->createToken('test', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/profile')->assertUnauthorized();
    }

    public function test_token_of_a_deactivated_user_stops_working(): void
    {
        $manager = $this->organisationManager(Organisation::factory()->create());
        $token = $manager->createToken('test')->plainTextToken;
        $manager->forceFill(['is_active' => false])->save();

        $this->withToken($token)->getJson('/api/v1/profile')->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
