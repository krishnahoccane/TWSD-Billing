<?php

namespace App\Modules\Organisations\Services;

use App\Models\User;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

/**
 * The organisation a user is currently "working in".
 *
 * The session only stores a uuid as a hint. It is re-validated against the
 * user's server-side assignments on every read, so tampering with it (or a
 * revoked assignment) can never widen access.
 */
class OrganisationContext
{
    private const SESSION_KEY = 'wcc.organisation';

    public function __construct(private readonly Session $session) {}

    /**
     * @return Collection<int, Organisation>
     */
    public function available(User $user): Collection
    {
        return Organisation::query()
            ->visibleTo($user)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'code', 'status']);
    }

    /**
     * Null means "all organisations" and is only possible for users with
     * global access (or users with no assignment at all).
     */
    public function current(User $user): ?Organisation
    {
        $uuid = $this->session->get(self::SESSION_KEY);

        if (is_string($uuid)) {
            $organisation = Organisation::query()->visibleTo($user)->where('uuid', $uuid)->first();

            if ($organisation !== null) {
                return $organisation;
            }

            $this->session->forget(self::SESSION_KEY);
        }

        if ($user->hasGlobalAccess()) {
            return null;
        }

        return Organisation::query()->visibleTo($user)->orderBy('name')->first();
    }

    /**
     * @throws AuthorizationException
     */
    public function switchTo(User $user, ?string $uuid): ?Organisation
    {
        if ($uuid === null) {
            if (! $user->hasGlobalAccess()) {
                throw new AuthorizationException('You must select one of your organisations.');
            }

            $this->session->forget(self::SESSION_KEY);

            return null;
        }

        $organisation = Organisation::query()->visibleTo($user)->where('uuid', $uuid)->first();

        if ($organisation === null) {
            // Same answer whether the organisation does not exist or is
            // simply not assigned to this user.
            throw new AuthorizationException('You do not have access to that organisation.');
        }

        $this->session->put(self::SESSION_KEY, $organisation->uuid);

        return $organisation;
    }
}
