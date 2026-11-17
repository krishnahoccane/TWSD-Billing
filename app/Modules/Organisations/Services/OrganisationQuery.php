<?php

namespace App\Modules\Organisations\Services;

use App\Models\User;
use App\Modules\Organisations\Enums\OrganisationStatus;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one tenant-filtered listing query, shared by the Livewire table,
 * the CSV export and the API so they can never disagree about visibility.
 */
class OrganisationQuery
{
    public function filtered(User $user, ?string $search = null, ?string $status = null): Builder
    {
        $query = Organisation::query()->visibleTo($user);

        $search = trim((string) $search);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function (Builder $inner) use ($like) {
                $inner->where('name', 'like', $like)
                    ->orWhere('legal_name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('primary_contact_email', 'like', $like);
            });
        }

        if ($status !== null && OrganisationStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        return $query->orderBy('name');
    }
}
