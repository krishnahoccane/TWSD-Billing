<?php

namespace App\Modules\AccessControl\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\AccessControl\Models\Role;
use App\Modules\AccessControl\Services\AccessResolver;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Settings → Roles and permissions (settings.manage).
 * The Super Admin role always holds every permission and cannot be edited.
 */
class RoleController extends Controller
{
    public function index(): View
    {
        Gate::authorize(Ability::SettingsManage->value);

        return view('settings.roles', [
            'roles' => Role::query()->with('permissions:id,name')->orderBy('id')->get(),
            'groups' => Permission::query()->orderBy('group')->orderBy('name')->get()->groupBy('group'),
            'superAdmin' => RoleName::SuperAdmin->value,
            'config' => [
                'Environment' => app()->environment(),
                'Application URL' => config('app.url'),
                'Billing currency / timezone' => config('wcc.billing.currency').' / '.config('wcc.billing.timezone'),
                'GST rate' => config('wcc.billing.default_tax_rate').'%',
                'Invoice due after' => config('wcc.billing.default_due_days').' days',
                'Proration' => config('wcc.billing.proration_policy'),
                'Offline payments by Super Admins' => config('wcc.billing.allow_manual_payments') ? 'Allowed' : 'Off',
                'Two-factor for privileged users' => config('wcc.security.enforce_two_factor') ? 'Required' : 'Not enforced',
                'Two-person rule (mailbox deletion / domain removal)' => (config('wcc.google.require_second_approver') ? 'On' : 'Off').' / '.(config('wcc.domains.require_second_approver') ? 'On' : 'Off'),
            ],
        ]);
    }

    public function update(Request $request, Role $role, AccessResolver $resolver, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize(Ability::SettingsManage->value);
        abort_if($role->name === RoleName::SuperAdmin->value, 403, 'The Super Admin role cannot be edited.');

        $data = $request->validate(['permissions' => ['array'], 'permissions.*' => ['string', 'exists:permissions,name']]);
        $wanted = array_values(array_unique($data['permissions'] ?? []));

        $before = $role->permissions()->pluck('name')->sort()->values()->all();

        DB::transaction(function () use ($role, $wanted) {
            $role->permissions()->sync(Permission::query()->whereIn('name', $wanted)->pluck('id')->all());
        });

        $after = $role->permissions()->pluck('name')->sort()->values()->all();
        $resolver->forget();

        $audit->record('access.role_permissions_changed', $role,
            previous: ['removed' => array_values(array_diff($before, $after))],
            new: ['added' => array_values(array_diff($after, $before))],
            actor: $request->user());

        return back()->with('success', $role->label.' updated. The change applies from the next request.');
    }
}
