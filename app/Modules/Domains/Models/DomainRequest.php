<?php

namespace App\Modules\Domains\Models;

use App\Models\User;
use App\Modules\AccessControl\Concerns\BelongsToOrganisation;
use App\Modules\Domains\Enums\DomainRequestStatus;
use App\Modules\Domains\Enums\DomainType;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request to add a domain to (TYPE_ADD) or remove a domain from
 * (TYPE_REMOVE) an organisation. A request is only a claim: nothing in it
 * proves ownership, which is established solely by Google's verification.
 */
class DomainRequest extends Model
{
    use BelongsToOrganisation, HasUuid;

    public const TYPE_ADD = 'add';

    public const TYPE_REMOVE = 'remove';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => DomainRequestStatus::class,
            'domain_type' => DomainType::class,
            'checks' => 'array',
            'decided_at' => 'datetime',
            'verified_at' => 'datetime',
            'completed_at' => 'datetime',
            'pending_since' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(OrganisationDomain::class, 'organisation_domain_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** (The `checks` attribute holds the attested removal checks.) */
    public function verificationChecks(): HasMany
    {
        return $this->hasMany(DomainVerificationCheck::class)->latest('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(DomainHistory::class)->latest('id');
    }

    public function isRemoval(): bool
    {
        return $this->type === self::TYPE_REMOVE;
    }

    /** The DNS TXT host for the verification record (the domain apex). */
    public function verificationHost(): string
    {
        return '@';
    }
}
