<?php

namespace Tests\Feature\GoogleWorkspace;

use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\GoogleWorkspace\Models\AccountSecret;
use Illuminate\Support\Facades\DB;

/**
 * Super Admins, organisation managers and assigned Operations Admins can
 * reset a mailbox password (generated or typed, optionally forcing a change
 * at next sign-in) and turn off 2-Step Verification. Never across organisations.
 */
class PasswordAndTwoStepTest extends WorkspaceTestCase
{
    public function test_manager_resets_with_a_generated_password_revealed_once(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        $account = $this->existingAccount($organisation, 'ravi@acme.test');
        $manager = $this->organisationManager($organisation);

        $this->actingAs($manager)->get(route('accounts.show', $account))->assertOk()
            ->assertSee('Reset password')->assertSee('Turn off 2SV')->assertSee('Ask user to change their password when they sign in');

        $this->from(route('accounts.show', $account))
            ->post(route('accounts.password', $account), ['mode' => 'generate', 'change_at_next_login' => '1'])
            ->assertRedirect(route('accounts.show', $account))->assertSessionHasNoErrors();

        $remote = $this->workspace()->rawUser('ravi@acme.test');
        $this->assertNotNull($remote['passwordChangedAt'] ?? null);
        $this->assertTrue($remote['changePasswordAtNextLogin']);
        $this->assertSame(1, AccountSecret::query()->where('google_account_id', $account->id)->where('user_id', $manager->id)->count());

        $page = $this->post(route('accounts.reveal', $account))->assertOk();
        $this->assertSame(0, AccountSecret::query()->where('google_account_id', $account->id)->count(), 'Shown once, then destroyed.');
        $page->assertSee('shown this one time only');

        $this->assertDatabaseHas('audit_logs', ['action' => 'account.password_reset', 'organisation_id' => $organisation->id]);
        // No password anywhere in audit or history rows.
        $this->assertSame(0, DB::table('audit_logs')->where('new_data', 'like', '%password":"%')->count());
    }

    public function test_operations_admin_sets_a_typed_password_without_forcing_a_change(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        $account = $this->existingAccount($organisation, 'ops@acme.test');
        $ops = $this->operationsAdmin($organisation);

        $this->actingAs($ops)->from(route('accounts.show', $account))
            ->post(route('accounts.password', $account), [
                'mode' => 'manual', 'password' => 'Str0ng!Pass#2026', 'password_confirmation' => 'Str0ng!Pass#2026', 'change_at_next_login' => '0',
            ])->assertRedirect(route('accounts.show', $account))->assertSessionHasNoErrors();

        $this->assertFalse($this->workspace()->rawUser('ops@acme.test')['changePasswordAtNextLogin']);
        $this->assertSame(0, AccountSecret::query()->count(), 'A typed password is never stored.');
        $this->assertSame(0, DB::table('audit_logs')->where('new_data', 'like', '%Str0ng%')->count());
        $this->assertSame(0, DB::table('google_account_history')->where('details', 'like', '%Str0ng%')->count());

        // Mismatched confirmation or a too-short password is refused.
        $this->post(route('accounts.password', $account), ['mode' => 'manual', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrorsIn('password', 'password');
    }

    public function test_two_step_verification_is_turned_off_in_google(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        $account = $this->existingAccount($organisation, 'tsv@acme.test');
        $manager = $this->organisationManager($organisation);

        $this->actingAs($manager)->from(route('accounts.show', $account))
            ->post(route('accounts.two-step-off', $account), ['reason' => 'Lost phone'])
            ->assertRedirect(route('accounts.show', $account))->assertSessionHasNoErrors();

        $this->assertSame(1, $this->workspace()->callCount('users.twoStepVerification.turnOff'));
        $this->assertFalse($this->workspace()->rawUser('tsv@acme.test')['isEnrolledIn2Sv']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.two_step_verification_turned_off', 'organisation_id' => $organisation->id]);
    }

    public function test_no_access_to_another_organisations_mailbox_or_without_the_permission(): void
    {
        [$mine] = $this->organisationWithDomain('mine.test');
        [$theirs] = $this->organisationWithDomain('theirs.test');
        $foreign = $this->existingAccount($theirs, 'x@theirs.test');
        $own = $this->existingAccount($mine, 'y@mine.test');

        $this->actingAs($this->organisationManager($mine));
        $this->post(route('accounts.password', $foreign), ['mode' => 'generate'])->assertNotFound();
        $this->post(route('accounts.two-step-off', $foreign), ['reason' => 'x x x'])->assertNotFound();

        // A billing manager of the same organisation cannot even see mailboxes.
        $this->actingAs($this->billingManager($mine));
        $this->post(route('accounts.password', $own), ['mode' => 'generate'])->assertNotFound();
        $this->post(route('accounts.two-step-off', $own), ['reason' => 'x x x'])->assertNotFound();

        $this->assertSame(0, $this->workspace()->callCount('users.twoStepVerification.turnOff'));
        $this->assertNull($this->workspace()->rawUser('x@theirs.test')['passwordChangedAt'] ?? null);
    }

    public function test_a_failed_reset_is_shown_on_the_account(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        $account = $this->existingAccount($organisation, 'fail@acme.test', AccountStatus::Active);
        $this->workspace()->failNext('users.patch', new WorkspaceException('Invalid password'));

        $this->actingAs($this->superAdmin())->post(route('accounts.password', $account), ['mode' => 'generate', 'change_at_next_login' => '1']);

        $this->assertStringContainsString('Invalid password', (string) $account->fresh()->last_error);
        $this->assertSame(0, AccountSecret::query()->count(), 'Nothing to reveal when Google refused the password.');
    }
}
