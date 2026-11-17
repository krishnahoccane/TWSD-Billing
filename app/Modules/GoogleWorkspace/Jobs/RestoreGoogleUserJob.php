<?php

namespace App\Modules\GoogleWorkspace\Jobs;

use App\Models\User;
use App\Modules\GoogleWorkspace\Enums\AccountOperation;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Services\GoogleAccountOperations;

class RestoreGoogleUserJob extends GoogleAccountJob
{
    public function operation(): ?AccountOperation
    {
        return AccountOperation::Restore;
    }

    protected function run(GoogleAccountOperations $operations, GoogleAccount $account, ?User $actor): void
    {
        $operations->restore($account, $actor);
    }
}
