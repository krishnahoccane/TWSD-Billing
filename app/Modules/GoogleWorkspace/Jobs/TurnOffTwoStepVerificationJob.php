<?php

namespace App\Modules\GoogleWorkspace\Jobs;

use App\Models\User;
use App\Modules\GoogleWorkspace\Enums\AccountOperation;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Services\GoogleAccountOperations;
use Throwable;

class TurnOffTwoStepVerificationJob extends GoogleAccountJob
{
    public function operation(): ?AccountOperation
    {
        return null;
    }

    protected function run(GoogleAccountOperations $operations, GoogleAccount $account, ?User $actor): void
    {
        $operations->turnOffTwoStepVerification($account, $actor);
    }

    public function failed(?Throwable $error): void
    {
        if ($account = GoogleAccount::query()->find($this->accountId)) {
            app(GoogleAccountOperations::class)->recordActionFailure($account, 'two_step_verification', $error ?? new WorkspaceException('Job failed.'));
        }
    }
}
