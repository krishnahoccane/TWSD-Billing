<?php

namespace Tests\Feature\GoogleWorkspace;

use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Enums\BillingStatus;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Providers\MockWorkspaceProvider;
use App\Modules\Organisations\Models\Organisation;
use Database\Factories\OrganisationDomainFactory;
use Tests\TestCase;

abstract class WorkspaceTestCase extends TestCase
{
    protected function workspace(): MockWorkspaceProvider
    {
        return app(MockWorkspaceProvider::class);
    }

    /**
     * @return array{0: Organisation, 1: OrganisationDomain}
     */
    protected function organisationWithDomain(string $domain): array
    {
        $organisation = Organisation::factory()->create();
        $mapped = OrganisationDomainFactory::new()->create(['organisation_id' => $organisation->id, 'domain_name' => $domain]);
        $this->workspace()->seedDomain($domain);

        return [$organisation, $mapped];
    }

    /**
     * A mailbox that exists both in the mock directory and locally.
     */
    protected function existingAccount(Organisation $organisation, string $email, AccountStatus $status = AccountStatus::Active): GoogleAccount
    {
        $remote = $this->workspace()->seedUser($email, 'Existing', 'User', $status !== AccountStatus::Active);

        $account = new GoogleAccount(['primary_email' => $email, 'first_name' => 'Existing', 'last_name' => 'User']);
        $account->forceFill([
            'organisation_id' => $organisation->id,
            'google_user_id' => $remote->id,
            'email_unique_key' => $email,
            'account_status' => $status,
            'is_suspended' => $status !== AccountStatus::Active,
            'billing_status' => $status === AccountStatus::SoftDeleted ? BillingStatus::NonBillable : BillingStatus::Billable,
            'deleted_at' => $status === AccountStatus::SoftDeleted ? now() : null,
        ])->save();

        return $account;
    }
}
