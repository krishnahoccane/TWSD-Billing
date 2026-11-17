<?php

namespace App\Modules\GoogleWorkspace\Services;

use App\Models\User;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Models\GoogleAccountHistory;
use Illuminate\Support\Facades\Context;

class AccountHistoryRecorder
{
    /**
     * @param  array<string, mixed>  $details  never include passwords or tokens
     */
    public function record(
        GoogleAccount $account,
        string $event,
        ?AccountStatus $from = null,
        ?AccountStatus $to = null,
        array $details = [],
        ?User $actor = null,
    ): GoogleAccountHistory {
        return GoogleAccountHistory::query()->create([
            'google_account_id' => $account->getKey(),
            'organisation_id' => $account->organisation_id,
            'user_id' => $actor?->getKey(),
            'event' => $event,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'details' => $details === [] ? null : $details,
            'request_id' => Context::get('request_id'),
        ]);
    }
}
