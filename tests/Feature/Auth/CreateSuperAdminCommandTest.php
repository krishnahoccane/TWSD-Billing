<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\TestCase;

class CreateSuperAdminCommandTest extends TestCase
{
    public function test_it_creates_a_super_admin_with_global_access(): void
    {
        $this->artisan('wcc:create-super-admin', ['--name' => 'Platform Owner', '--email' => 'Owner@Example.com'])
            ->expectsQuestion('Password (min 12 chars, mixed case, number, symbol)', 'Str0ng!Passphrase')
            ->expectsQuestion('Confirm password', 'Str0ng!Passphrase')
            ->assertSuccessful();

        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();

        $this->assertTrue($user->hasGlobalAccess());
        $this->assertTrue($user->isPrivileged());
        $this->assertNotSame('Str0ng!Passphrase', $user->password);
    }

    public function test_it_rejects_a_weak_password(): void
    {
        $this->artisan('wcc:create-super-admin', ['--name' => 'Platform Owner', '--email' => 'owner@example.com'])
            ->expectsQuestion('Password (min 12 chars, mixed case, number, symbol)', 'password')
            ->expectsQuestion('Confirm password', 'password')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
    }
}
