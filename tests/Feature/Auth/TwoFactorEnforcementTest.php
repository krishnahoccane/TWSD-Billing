<?php

namespace Tests\Feature\Auth;

use App\Modules\Organisations\Models\Organisation;
use Tests\TestCase;

class TwoFactorEnforcementTest extends TestCase
{
    public function test_privileged_user_without_two_factor_is_forced_to_enrol(): void
    {
        $admin = $this->superAdmin(twoFactor: false);

        $this->actingAs($admin)->get('/dashboard')->assertRedirect('/account/security');
    }

    public function test_security_page_is_reachable_before_enrolment(): void
    {
        $admin = $this->superAdmin(twoFactor: false);

        $this->actingAs($admin)->get('/account/security')
            ->assertOk()
            ->assertSee('Required')
            ->assertSee('Set up two-factor authentication');
    }

    public function test_privileged_user_with_two_factor_can_use_the_application(): void
    {
        $this->actingAs($this->superAdmin())->get('/dashboard')->assertOk();
    }

    public function test_organisation_users_are_not_forced_to_enrol(): void
    {
        $manager = $this->organisationManager(Organisation::factory()->create());

        $this->actingAs($manager)->get('/dashboard')->assertOk();
    }

    public function test_enforcement_can_be_disabled_by_configuration(): void
    {
        config(['wcc.security.enforce_two_factor' => false]);

        $this->actingAs($this->superAdmin(twoFactor: false))->get('/dashboard')->assertOk();
    }

    public function test_enabling_two_factor_requires_password_confirmation(): void
    {
        $admin = $this->superAdmin(twoFactor: false);

        $this->actingAs($admin)->post('/user/two-factor-authentication')
            ->assertRedirect('/user/confirm-password');

        $this->assertNull($admin->fresh()->two_factor_secret);
    }

    public function test_enrolment_shows_a_qr_code_and_stays_pending_until_confirmed(): void
    {
        $admin = $this->superAdmin(twoFactor: false);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication')
            ->assertRedirect();

        $admin->refresh();
        $this->assertNotNull($admin->two_factor_secret);
        $this->assertFalse($admin->hasTwoFactorEnabled());

        $this->get('/account/security')->assertOk()->assertSee('<svg', false)->assertSee('Confirm and enable');

        // Still blocked from the rest of the application until confirmed.
        $this->get('/dashboard')->assertRedirect('/account/security');
    }

    public function test_recovery_codes_are_not_shown_on_an_ordinary_page_view(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/account/security')
            ->assertOk()
            ->assertDontSee('recovery-code-1');
    }
}
