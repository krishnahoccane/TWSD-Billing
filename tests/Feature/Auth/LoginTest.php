<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Organisations\Models\Organisation;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_login_screen_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/account/security')->assertRedirect('/login');
    }

    public function test_user_can_sign_in_with_valid_credentials(): void
    {
        $user = $this->organisationManager(Organisation::factory()->create());

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_sign_in_fails_with_wrong_password(): void
    {
        $user = User::factory()->create();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'not-the-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivated_user_cannot_sign_in_and_gets_the_generic_error(): void
    {
        $user = User::factory()->inactive()->create();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
    }

    public function test_user_deactivated_mid_session_is_signed_out(): void
    {
        $user = $this->organisationManager(Organisation::factory()->create());
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(302);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_user_with_two_factor_is_sent_to_the_challenge(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/two-factor-challenge');

        $this->assertGuest();
        $this->get('/two-factor-challenge')->assertOk()->assertSee('Authentication code');
    }

    public function test_self_registration_is_not_available(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => 'x@example.com'])->assertNotFound();
    }

    public function test_user_can_sign_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_authentication_events_are_audited_without_credentials(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'a-wrong-secret']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed', 'status' => 'failure']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id, 'status' => 'success']);

        $dump = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString('a-wrong-secret', $dump);
        $this->assertStringNotContainsString('"password"', $dump);
    }

    public function test_responses_carry_security_headers_and_a_request_id(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }
}
