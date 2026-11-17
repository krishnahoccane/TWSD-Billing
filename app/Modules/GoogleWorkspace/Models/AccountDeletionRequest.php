<?php

namespace App\Modules\GoogleWorkspace\Models;

use App\Models\User;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to permanently delete a mailbox in Google, with the recorded
 * pre-deletion checks, the approval decision and the API outcome.
 */
class AccountDeletionRequest extends Model
{
    use HasUuid;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const EXECUTED = 'executed';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'api_response' => 'array',
            'decided_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAccount::class, 'google_account_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
