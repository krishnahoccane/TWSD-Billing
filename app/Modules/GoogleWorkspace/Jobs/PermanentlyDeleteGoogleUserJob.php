<?php

namespace App\Modules\GoogleWorkspace\Jobs;

use App\Models\User;
use App\Modules\GoogleWorkspace\Enums\AccountOperation;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\GoogleWorkspace\Models\AccountDeletionRequest;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Services\GoogleAccountOperations;

/**
 * Deletes the Google user. Runs only for an approved deletion request;
 * the operation itself re-checks the approval.
 */
class PermanentlyDeleteGoogleUserJob extends GoogleAccountJob
{
    public function operation(): ?AccountOperation
    {
        return AccountOperation::PermanentDelete;
    }

    protected function run(GoogleAccountOperations $operations, GoogleAccount $account, ?User $actor): void
    {
        $request = AccountDeletionRequest::query()->find($this->payload['deletion_request_id'] ?? null);

        if ($request === null) {
            throw new WorkspaceException('Permanent deletion requires an approved deletion request.');
        }

        $operations->permanentlyDelete($account, $request, $actor);
    }
}
