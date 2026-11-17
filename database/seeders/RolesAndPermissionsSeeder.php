<?php

namespace Database\Seeders;

use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\AccessControl\Models\Role;
use App\Modules\AccessControl\Support\RolePermissionMatrix;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent and safe to run on every deploy.
 *
 * - Permissions are synchronised from the Ability enum.
 * - System roles are created if missing.
 * - A role receives its default permission set ONLY when it is first
 *   created, so customisations made by a Super Admin are never reverted.
 * - Super Admin always holds every permission, including new ones.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach (Ability::cases() as $ability) {
                Permission::query()->updateOrCreate(
                    ['name' => $ability->value],
                    [
                        'label' => $ability->label(),
                        'group' => $ability->group(),
                        'is_sensitive' => $ability->isSensitive(),
                    ],
                );
            }

            $permissionIds = Permission::query()->pluck('id', 'name');

            foreach (RoleName::cases() as $roleName) {
                $role = Role::query()->firstOrCreate(
                    ['name' => $roleName->value],
                    [
                        'label' => $roleName->label(),
                        'description' => $roleName->description(),
                        'scope' => $roleName->scope(),
                        'is_system' => true,
                    ],
                );

                if ($role->wasRecentlyCreated || $roleName === RoleName::SuperAdmin) {
                    $role->permissions()->sync(
                        collect(RolePermissionMatrix::for($roleName))
                            ->map(fn (Ability $ability) => $permissionIds[$ability->value])
                            ->all(),
                    );
                }
            }
        });
    }
}
