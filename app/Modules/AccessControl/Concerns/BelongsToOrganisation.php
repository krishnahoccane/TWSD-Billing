<?php

namespace App\Modules\AccessControl\Concerns;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every organisation-owned model (accounts, domains, invoices, ...).
 *
 * Listing queries MUST start from Model::visibleTo($user, $ability) so
 * tenant filtering happens in SQL, and single-record access MUST also pass
 * a policy check. Both layers are required; neither replaces the other.
 */
trait BelongsToOrganisation
{
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function scopeVisibleTo(Builder $query, User $user, Ability $ability): Builder
    {
        $user->organisationScope($ability)
            ->apply($query, $this->qualifyColumn('organisation_id'));

        return $query;
    }
}
