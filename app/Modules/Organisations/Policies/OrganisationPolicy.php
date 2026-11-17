<?php

namespace App\Modules\Organisations\Policies;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Organisations\Enums\ContactType;
use App\Modules\Organisations\Models\Organisation;

/**
 * Second isolation layer: every single-organisation action is checked
 * here, in addition to the tenant-filtered route binding.
 */
class OrganisationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAbilityAnywhere(Ability::OrganisationsView);
    }

    public function view(User $user, Organisation $organisation): bool
    {
        return $user->hasAbility(Ability::OrganisationsView, $organisation);
    }

    public function create(User $user): bool
    {
        return $user->hasAbility(Ability::OrganisationsCreate);
    }

    public function update(User $user, Organisation $organisation): bool
    {
        return $user->hasAbility(Ability::OrganisationsUpdate, $organisation);
    }

    public function changeStatus(User $user, Organisation $organisation): bool
    {
        return $user->hasAbility(Ability::OrganisationsChangeStatus, $organisation);
    }

    public function manageMembers(User $user, Organisation $organisation): bool
    {
        return $user->hasAbility(Ability::OrganisationsManageManagers, $organisation);
    }

    /**
     * Organisation Managers manage all contacts; Billing Managers manage
     * billing contacts only. With no type: "may manage at least one type".
     */
    public function manageContacts(User $user, Organisation $organisation, ?ContactType $type = null): bool
    {
        if ($user->hasAbility(Ability::OrganisationsManageContacts, $organisation)) {
            return true;
        }

        return ($type === null || $type === ContactType::Billing)
            && $user->hasAbility(Ability::BillingManageContacts, $organisation);
    }

    public function mapDomains(User $user, Organisation $organisation): bool
    {
        return $user->hasAbility(Ability::DomainsManage, $organisation);
    }

    public function viewDomains(User $user, Organisation $organisation): bool
    {
        return $user->hasAbility(Ability::DomainsView, $organisation);
    }

    public function viewActivity(User $user, Organisation $organisation): bool
    {
        return $user->hasAbility(Ability::AuditView, $organisation);
    }
}
