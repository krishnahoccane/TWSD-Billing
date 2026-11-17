<?php

namespace Tests\Feature\GoogleWorkspace;

use App\Modules\Billing\Models\AccountBillingPeriod;
use App\Modules\Billing\Services\BillingPeriodTracker;
use App\Modules\GoogleWorkspace\Enums\AccountOperation;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Organisations\Models\Organisation;
use Carbon\CarbonImmutable;

class AccountReassignTest extends WorkspaceTestCase
{
    /** @return array{0: Organisation, 1: Organisation, 2: GoogleAccount} */
    private function assignedAccount(): array
    {
        [$from] = $this->organisationWithDomain('alpha.test');
        $to = Organisation::factory()->create();
        $account = $this->existingAccount($from, 'person@alpha.test');

        $this->travelTo(CarbonImmutable::now()->subDays(10));
        app(BillingPeriodTracker::class)->open($account->fresh());
        $this->travelBack();

        return [$from, $to, $account->fresh()];
    }

    public function test_super_admin_moves_an_assigned_mailbox_to_another_organisation(): void
    {
        [$from, $to, $account] = $this->assignedAccount();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('accounts.show', $account))->assertOk()
            ->assertSee('Change organisation')->assertDontSee('Assign to an organisation');

        $this->from(route('accounts.show', $account))
            ->post(route('accounts.assign', $account), ['organisation' => $to->uuid, 'reason' => 'Department moved to the new company'])
            ->assertRedirect(route('accounts.show', $account))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'billed to the new organisation from today'));

        $this->assertSame($to->id, $account->fresh()->organisation_id);

        // Billing: the old organisation's period is closed, a new one is open for the new organisation.
        $periods = AccountBillingPeriod::query()->where('google_account_id', $account->id)->orderBy('id')->get();
        $this->assertCount(2, $periods);
        $this->assertSame($from->id, $periods[0]->organisation_id);
        $this->assertNotNull($periods[0]->ends_on);
        $this->assertSame($to->id, $periods[1]->organisation_id);
        $this->assertNull($periods[1]->ends_on);

        // Audited for both organisations, with the reason.
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.organisation_reassigned', 'organisation_id' => $to->id, 'user_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.organisation_reassigned', 'organisation_id' => $from->id, 'user_id' => $admin->id]);
        $this->assertDatabaseHas('google_account_history', ['google_account_id' => $account->id, 'event' => 'organisation_reassigned']);
    }

    public function test_a_reason_is_required_and_the_same_organisation_is_rejected(): void
    {
        [$from, $to, $account] = $this->assignedAccount();
        $this->actingAs($this->superAdmin())->from(route('accounts.show', $account));

        $this->post(route('accounts.assign', $account), ['organisation' => $to->uuid])
            ->assertSessionHasErrorsIn('reassign', 'reason');
        $this->post(route('accounts.assign', $account), ['organisation' => $from->uuid, 'reason' => 'No change'])
            ->assertSessionHasErrorsIn('reassign', 'organisation');

        $this->assertSame($from->id, $account->fresh()->organisation_id);
    }

    public function test_a_mailbox_with_an_operation_in_flight_cannot_be_moved(): void
    {
        [$from, $to, $account] = $this->assignedAccount();
        $account->forceFill(['pending_operation' => AccountOperation::Suspend, 'pending_since' => now()])->save();

        $this->actingAs($this->superAdmin())->from(route('accounts.show', $account))
            ->post(route('accounts.assign', $account), ['organisation' => $to->uuid, 'reason' => 'Moving'])
            ->assertSessionHasErrors('organisation');

        $this->assertSame($from->id, $account->fresh()->organisation_id);
    }

    public function test_organisation_managers_cannot_move_mailboxes(): void
    {
        [$from, $to, $account] = $this->assignedAccount();

        // Even a manager of both organisations: moving mailboxes is a Super Admin function.
        $manager = $this->organisationManager($from, $to);
        $this->actingAs($manager)->get(route('accounts.show', $account))->assertOk()->assertDontSee('Change organisation');
        $this->post(route('accounts.assign', $account), ['organisation' => $to->uuid, 'reason' => 'Moving'])->assertForbidden();

        // A manager of another organisation cannot even see the mailbox.
        $this->actingAs($this->organisationManager($to))
            ->post(route('accounts.assign', $account), ['organisation' => $to->uuid, 'reason' => 'Moving'])->assertNotFound();

        $this->assertSame($from->id, $account->fresh()->organisation_id);
    }
}
