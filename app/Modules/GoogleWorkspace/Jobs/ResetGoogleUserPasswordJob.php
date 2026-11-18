<?php

namespace App\Modules\GoogleWorkspace\Jobs;

use App\Models\User;
use App\Modules\GoogleWorkspace\Enums\AccountOperation;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Services\GoogleAccountOperations;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Throwable;

/**
 * payload: ['password' => ?string (null = generate), 'change_at_next_login' => bool]
 *
 * The whole job is encrypted on the queue (ShouldBeEncrypted, APP_KEY), so
 * a typed password is never stored in plain text in the jobs table.
 */
class ResetGoogleUserPasswordJob extends GoogleAccountJob implements ShouldBeEncrypted
{
    public function operation(): ?AccountOperation
    {
        return null;
    }

    /** One reset per account at a time; the key never contains the password. */
    public function uniqueId(): string
    {
        return static::class.':'.$this->accountId;
    }

    protected function run(GoogleAccountOperations $operations, GoogleAccount $account, ?User $actor): void
    {
        $operations->resetPassword($account, $this->payload['password'] ?? null, (bool) ($this->payload['change_at_next_login'] ?? true), $actor);
    }

    public function failed(?Throwable $error): void
    {
        if ($account = GoogleAccount::query()->find($this->accountId)) {
            app(GoogleAccountOperations::class)->recordActionFailure($account, 'password_reset', $error ?? new WorkspaceException('Job failed.'));
        }
    }
}
