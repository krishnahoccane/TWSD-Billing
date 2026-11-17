<?php

namespace App\Modules\GoogleWorkspace\Models;

use App\Models\User;
use App\Modules\AccessControl\Concerns\BelongsToOrganisation;
use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\GoogleWorkspace\Enums\AccountOperation;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Enums\BillingStatus;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One Google Workspace mailbox. Rows are never deleted: `deleted_at` is
 * the lifecycle soft-deletion time, not an Eloquent soft delete.
 *
 * organisation_id is deliberately not fillable: ownership changes only
 * through GoogleAccountService::assignOrganisation().
 */
class GoogleAccount extends Model
{
    use BelongsToOrganisation, HasUuid;

    protected $fillable = [
        'primary_email', 'first_name', 'last_name', 'display_name',
        'recovery_email', 'recovery_phone', 'organisational_unit_path',
    ];

    protected $attributes = [
        'organisational_unit_path' => '/',
        'is_suspended' => false,
        'billing_status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'account_status' => AccountStatus::class,
            'billing_status' => BillingStatus::class,
            'pending_operation' => AccountOperation::class,
            'previous_status' => AccountStatus::class,
            'is_suspended' => 'boolean',
            'monthly_price' => 'decimal:2',
            'billable_from' => 'date',
            'deletion_effective_date' => 'date',
            'pending_since' => 'datetime',
            'google_created_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'missing_from_google_at' => 'datetime',
            'permanently_deleted_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $account) {
            $account->idempotency_key ??= (string) Str::uuid();
        });
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(OrganisationDomain::class, 'domain_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(GoogleAccountHistory::class)->latest('id');
    }

    public function deletionRequests(): HasMany
    {
        return $this->hasMany(AccountDeletionRequest::class)->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function isUnassigned(): bool
    {
        return $this->organisation_id === null;
    }
}
