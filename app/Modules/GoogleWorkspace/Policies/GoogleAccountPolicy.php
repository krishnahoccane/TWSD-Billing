<?php

namespace App\Modules\GoogleWorkspace\Policies;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Organisations\Models\Organisation;

/**
 * Second isolation layer for mailboxes (the first is the tenant-scoped
 * {account} route binding and GoogleAccount::visibleTo()).
 *
 * Abilities are evaluated in the account's OWN organisation, read from
 * the database. An unassigned account belongs to nobody, so only users
 * with global access can act on it.
 */
class GoogleAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAbilityAnywhere(Ability::AccountsView);
    }

    public function view(User $user, GoogleAccount $account): bool
    {
        return $this->allows($user, Ability::AccountsView, $account);
    }

    public function create(User $user, Organisation $organisation): bool
    {
        return $user->hasAbility(Ability::AccountsCreate, $organisation);
    }

    public function update(User $user, GoogleAccount $account): bool
    {
        return $this->allows($user, Ability::AccountsUpdate, $account);
    }

    public function suspend(User $user, GoogleAccount $account): bool
    {
        return $this->allows($user, Ability::AccountsSuspend, $account);
    }

    public function reactivate(User $user, GoogleAccount $account): bool
    {
        return $this->allows($user, Ability::AccountsReactivate, $account);
    }

    /** Stage 1 (reversible): managers may request it; it executes immediately (decision D-05). */
    public function softDelete(User $user, GoogleAccount $account): bool
    {
        return $this->allows($user, Ability::AccountsRequestDeletion, $account)
            || $this->allows($user, Ability::AccountsSoftDelete, $account);
    }

    public function restore(User $user, GoogleAccount $account): bool
    {
        return $this->allows($user, Ability::AccountsRestore, $account);
    }

    public function requestPermanentDeletion(User $user, GoogleAccount $account): bool
    {
        return $this->allows($user, Ability::AccountsExecutePermanentDeletion, $account);
    }

    public function approvePermanentDeletion(User $user, GoogleAccount $account): bool
    {
        return $this->allows($user, Ability::AccountsApprovePermanentDeletion, $account);
    }

    public function reassign(User $user, GoogleAccount $account): bool
    {
        return $this->allows($user, Ability::AccountsReassignOrganisation, $account);
    }

    private function allows(User $user, Ability $ability, GoogleAccount $account): bool
    {
        if ($account->organisation_id === null) {
            return $user->hasGlobalAccess() && $user->hasAbility($ability);
        }

        $organisation = $account->organisation;

        return $organisation !== null && $user->hasAbility($ability, $organisation);
    }
}
