<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\AccessControl\Services\RoleAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Bootstraps the first Super Admin. There are no seeded credentials in this
 * application; the password is typed interactively and never echoed.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'wcc:create-super-admin {--name=} {--email=}';

    protected $description = 'Create a Super Admin user (prompts for the password)';

    public function handle(RoleAssignmentService $roles): int
    {
        $name = $this->option('name') ?: text('Full name', required: true);
        $email = Str::lower($this->option('email') ?: text('Email address', required: true));
        $password = password('Password (min 12 chars, mixed case, number, symbol)', required: true);
        $confirmation = password('Confirm password', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $password, $roles) {
            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $roles->assignPlatformRole($user, RoleName::SuperAdmin);
        });

        $this->info("Super Admin {$email} created. They must enrol in two-factor authentication at first sign-in.");

        return self::SUCCESS;
    }
}
