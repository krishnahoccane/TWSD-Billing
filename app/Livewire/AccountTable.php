<?php

namespace App\Livewire;

use App\Modules\AccessControl\Enums\Ability;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Services\OrganisationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Mailbox list. Rows always come from GoogleAccount::visibleTo(), and the
 * organisation filter is resolved inside the user's own scope, so no
 * component state can widen what is shown.
 */
class AccountTable extends Component
{
    use WithPagination;

    /** all | suspended | deleted | unassigned — fixed by the page, not editable from the browser. */
    #[Locked]
    public string $preset = 'all';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $organisation = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'organisation'], true)) {
            $this->resetPage();
        }
    }

    public function render(OrganisationContext $context): View
    {
        Gate::authorize('viewAny', GoogleAccount::class);

        $user = Auth::user();
        $current = $context->current($user);

        if ($this->preset === 'unassigned') {
            abort_unless($user->hasGlobalAccess() && $user->hasAbility(Ability::AccountsReassignOrganisation), 403);
        }

        $query = GoogleAccount::query()
            ->visibleTo($user, Ability::AccountsView)
            ->with('organisation:id,uuid,name');

        match ($this->preset) {
            'suspended' => $query->where('account_status', AccountStatus::Suspended),
            'deleted' => $query->whereIn('account_status', [AccountStatus::SoftDeleted, AccountStatus::PermanentlyDeleted]),
            'unassigned' => $query->whereNull('organisation_id'),
            default => $query->when(
                AccountStatus::tryFrom($this->status) !== null,
                fn (Builder $inner) => $inner->where('account_status', $this->status),
                fn (Builder $inner) => $inner->where('account_status', '!=', AccountStatus::PermanentlyDeleted),
            ),
        };

        if ($this->preset !== 'unassigned') {
            // Working organisation from the header switcher, else the optional filter.
            $selected = $current ?? ($this->organisation !== ''
                ? Organisation::query()->visibleTo($user, Ability::AccountsView)->where('uuid', $this->organisation)->first()
                : null);

            if ($selected !== null) {
                $query->where('organisation_id', $selected->getKey());
            }
        }

        $search = trim($this->search);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $inner) => $inner->where('primary_email', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like));
        }

        return view('livewire.account-table', [
            'accounts' => $query->orderBy('primary_email')->paginate(25),
            'statuses' => AccountStatus::options(),
            'organisations' => $current === null && $this->preset !== 'unassigned'
                ? Organisation::query()->visibleTo($user, Ability::AccountsView)->orderBy('name')->pluck('name', 'uuid')->all()
                : [],
            'showOrganisation' => $current === null,
        ]);
    }
}
