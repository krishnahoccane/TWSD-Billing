<?php

namespace App\Modules\Domains\Policies;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Domains\Enums\DomainRequestStatus;
use App\Modules\Domains\Models\DomainRequest;
use App\Modules\Organisations\Models\Organisation;

/**
 * Abilities are evaluated in the request's own organisation, read from
 * the database (never from the browser).
 */
class DomainRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAbilityAnywhere(Ability::DomainsView);
    }

    public function view(User $user, DomainRequest $request): bool
    {
        return $user->hasAbility(Ability::DomainsView, $request->organisation);
    }

    public function create(User $user, Organisation $organisation): bool
    {
        return $user->hasAbility(Ability::DomainsRequest, $organisation);
    }

    /** Only the person who submitted it, and only while it is still undecided. */
    public function cancel(User $user, DomainRequest $request): bool
    {
        return $request->requested_by === $user->getKey()
            && $request->status === DomainRequestStatus::Pending
            && $user->hasAbility(Ability::DomainsView, $request->organisation);
    }

    /** Approve or reject. Additions need domains.approve; removals need domains.remove. */
    public function decide(User $user, DomainRequest $request): bool
    {
        return $user->hasAbility($request->isRemoval() ? Ability::DomainsRemove : Ability::DomainsApprove, $request->organisation);
    }

    /** Check DNS, ask Google to verify, retry a failed step. */
    public function process(User $user, DomainRequest $request): bool
    {
        return $user->hasAbility(Ability::DomainsProcess, $request->organisation);
    }
}
