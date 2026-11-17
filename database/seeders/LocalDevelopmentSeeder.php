<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\AccessControl\Services\RoleAssignmentService;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Fictional data for local UI work. Never part of DatabaseSeeder.
 *
 *   php artisan db:seed --class=LocalDevelopmentSeeder
 *
 * Refuses to run outside the local environment and has no built-in
 * password: set WCC_DEV_PASSWORD in your (git-ignored) .env first.
 */
class LocalDevelopmentSeeder extends Seeder
{
    public function run(RoleAssignmentService $roles): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('LocalDevelopmentSeeder may only run in the local environment.');
        }

        $password = env('WCC_DEV_PASSWORD');

        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Set WCC_DEV_PASSWORD (12+ characters) in .env before seeding demo users.');
        }

        $this->call(RolesAndPermissionsSeeder::class);

        $user = fn (string $name, string $email) => User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $password],
        );

        $organisations = collect([
            ['Demo Northwind Traders', 'DEMO-NWT'],
            ['Demo Contoso Logistics', 'DEMO-CTL'],
            ['Demo Fabrikam Foods', 'DEMO-FBF'],
        ])->map(fn (array $row) => Organisation::query()->firstOrCreate(
            ['code' => $row[1]],
            ['name' => $row[0], 'legal_name' => $row[0].' Pvt Ltd'],
        ));

        $roles->assignPlatformRole($user('Demo Super Admin', 'admin@wcc.test'), RoleName::SuperAdmin);

        $manager = $user('Demo Manager', 'manager@wcc.test');
        $roles->assignOrganisationRole($manager, $organisations[0], RoleName::OrganisationManager);
        $roles->assignOrganisationRole($manager, $organisations[1], RoleName::OrganisationManager);

        $roles->assignOrganisationRole($user('Demo Billing', 'billing@wcc.test'), $organisations[0], RoleName::BillingManager);
    }
}
