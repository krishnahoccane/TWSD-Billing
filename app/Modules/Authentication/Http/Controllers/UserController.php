<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\Authentication\Services\UserService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Dashboard users (Super Admin only: users.manage).
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize(Ability::UsersManage->value);

        $search = trim($request->string('search')->toString());

        $users = User::query()
            ->with('platformRoles:id,name,label')
            ->withCount(['organisationRoleAssignments as organisations_count' => fn (Builder $query) => $query
                ->select(\DB::raw('count(distinct organisation_id)'))])
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';

                $query->where(fn (Builder $inner) => $inner->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'search' => $search,
            'platformRoles' => self::platformRoles(),
        ]);
    }

    public function store(Request $request, UserService $users): RedirectResponse
    {
        Gate::authorize(Ability::UsersManage->value);

        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validateWithBag('user', [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'platform_role' => ['nullable', Rule::in(array_keys(self::platformRoles()))],
        ]);

        $user = $users->create(
            $data['name'],
            $data['email'],
            $request->user(),
            isset($data['platform_role']) ? RoleName::from($data['platform_role']) : null,
        );

        return redirect()->route('users.index')
            ->with('success', "{$user->name} was created and e-mailed a link to set their password.");
    }

    public function setActive(Request $request, User $user, UserService $users): RedirectResponse
    {
        Gate::authorize(Ability::UsersManage->value);

        $data = $request->validate(['active' => ['required', 'boolean']]);

        try {
            $users->setActive($user, (bool) $data['active'], $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $data['active'] ? 'User activated.' : 'User deactivated.');
    }

    public function sendPasswordLink(Request $request, User $user, UserService $users): RedirectResponse
    {
        Gate::authorize(Ability::UsersManage->value);

        return $users->sendPasswordLink($user, $request->user())
            ? back()->with('success', 'Password link sent.')
            : back()->with('error', 'A password link was sent recently. Please wait a minute before sending another.');
    }

    /**
     * @return array<string, string>
     */
    private static function platformRoles(): array
    {
        return collect(RoleName::cases())
            ->filter(fn (RoleName $role) => $role->scope()->allowsPlatformAssignment())
            ->mapWithKeys(fn (RoleName $role) => [$role->value => $role->label()])
            ->all();
    }
}
