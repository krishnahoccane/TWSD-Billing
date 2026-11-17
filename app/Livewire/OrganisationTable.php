<?php

namespace App\Livewire;

use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Services\OrganisationQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Searchable, filterable organisation list. Public state holds only the
 * filter text; rows come from the tenant-filtered OrganisationQuery on
 * every render, so tampering with component state cannot widen access.
 */
class OrganisationTable extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function render(OrganisationQuery $query): View
    {
        Gate::authorize('viewAny', Organisation::class);

        return view('livewire.organisation-table', [
            'organisations' => $query->filtered(Auth::user(), $this->search, $this->status ?: null)
                ->withCount(['domains', 'roleAssignments as members_count' => fn ($members) => $members
                    ->select(\DB::raw('count(distinct user_id)'))])
                ->paginate(20),
            'canCreate' => Auth::user()->can('create', Organisation::class),
        ]);
    }
}
