<?php

namespace App\Modules\Licensing\Models;

use App\Modules\AccessControl\Concerns\BelongsToOrganisation;
use App\Support\Concerns\HasUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A software license owned by an organisation. The key is stored as a
 * SHA-256 hash (license server look-up) and APP_KEY-encrypted (revealed on
 * request, audited); only its last four characters are ever listed.
 */
class SoftwareLicense extends Model
{
    use BelongsToOrganisation, HasUuid;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const REVOKED = 'revoked';

    public const EXPIRING_DAYS = 30;

    protected $guarded = [];

    protected $hidden = ['key_hash', 'key_encrypted'];

    protected function casts(): array
    {
        return [
            'key_encrypted' => 'encrypted',
            'purchased_at' => 'datetime',
            'expires_at' => 'datetime',
            'support_until' => 'datetime',
            'pending_until' => 'datetime',
            'auto_renew' => 'boolean',
            'domains_included' => 'integer',
            'domains_extra' => 'integer',
            'price_paid_minor' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SoftwareProduct::class, 'software_product_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SoftwarePlan::class, 'software_plan_id');
    }

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    public function activations(): HasMany
    {
        return $this->hasMany(SoftwareActivation::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SoftwareOrder::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SoftwareLicenseEvent::class)->orderByDesc('id');
    }

    public function publicId(): string
    {
        return 'lic_'.$this->uuid;
    }

    public function domainLimit(): int
    {
        return $this->domains_included + $this->domains_extra;
    }

    public function maskedKey(): string
    {
        return ($this->product?->key_prefix ?? 'KEY').'-••••-••••-••••-'.$this->key_last4;
    }

    /**
     * Stored status plus the derived "expiring" / "expired" (annual licenses).
     * pending | active | expiring | expired | suspended | revoked
     */
    public function displayStatus(?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();

        if (in_array($this->status, [self::SUSPENDED, self::REVOKED], true)) {
            return $this->status;
        }

        if ($this->status === self::PENDING) {
            return $this->pending_until !== null && $this->pending_until->lt($now) ? 'expired' : self::PENDING;
        }

        if ($this->expires_at !== null) {
            if ($this->expires_at->lte($now)) {
                return 'expired';
            }

            if ($this->expires_at->lte($now->addDays(self::EXPIRING_DAYS))) {
                return 'expiring';
            }
        }

        return self::ACTIVE;
    }

    /** May installs run (read/write)? Expired annual licenses keep running; suspended/revoked do not. */
    public function allowsUse(): bool
    {
        return ! in_array($this->displayStatus(), [self::SUSPENDED, self::REVOKED], true)
            && ! ($this->status === self::PENDING && $this->displayStatus() === 'expired');
    }

    public function supportExpired(): bool
    {
        return $this->support_until !== null && $this->support_until->isPast();
    }

    public function statusTone(): string
    {
        return match ($this->displayStatus()) {
            'active' => 'success',
            'expiring', 'pending' => 'warning',
            'expired', 'suspended', 'revoked' => 'danger',
            default => 'neutral',
        };
    }
}
