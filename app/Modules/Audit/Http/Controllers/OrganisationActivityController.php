<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Audit activity that concerns organisations, optionally for one of them.
 */
class OrganisationActivityController extends Controller
{
    public function __invoke(Request $request): View
    {
        Gate::authorize(Ability::AuditView->value);

        $user = $request->user();
        $scope = $user->organisationScope(Ability::AuditView);

        // The filter is a uuid from the query string, so it is resolved
        // inside the caller's scope and ignored if it does not belong there.
        $selected = $request->filled('organisation')
            ? Organisation::query()->visibleTo($user, Ability::AuditView)->where('uuid', $request->string('organisation'))->first()
            : null;

        $entries = AuditLog::query()
            ->with('organisation:id,uuid,name')
            ->whereNotNull('organisation_id')
            ->when(! $scope->all, fn ($query) => $query->whereIn('organisation_id', $scope->ids))
            ->when($selected, fn ($query) => $query->where('organisation_id', $selected->getKey()))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('organisations.activity', [
            'entries' => $entries,
            'selected' => $selected,
            'organisations' => Organisation::query()->visibleTo($user, Ability::AuditView)->orderBy('name')->pluck('name', 'uuid'),
        ]);
    }
}
