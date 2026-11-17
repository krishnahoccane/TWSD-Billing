<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data only. No users, organisations or credentials are
     * seeded; create the first Super Admin with `php artisan wcc:create-super-admin`.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
