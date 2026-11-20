<?php

namespace App\Modules\Licensing\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One domain / installation of a license. */
class SoftwareActivation extends Model
{
    use HasUuid;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const DEACTIVATED = 'deactivated';

    public const PRODUCTION = 'production';

    public const STAGING = 'staging';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'last_check_in_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'check_in_requested' => 'boolean',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(SoftwareLicense::class, 'software_license_id');
    }

    public function publicId(): string
    {
        return 'act_'.$this->uuid;
    }

    public function isOnline(): bool
    {
        return $this->status === self::ACTIVE && $this->last_check_in_at !== null && $this->last_check_in_at->gt(now()->subDay());
    }

    public function maskedIp(): ?string
    {
        if ($this->server_ip === null) {
            return null;
        }

        return str_contains($this->server_ip, ':')
            ? implode(':', array_slice(explode(':', $this->server_ip), 0, 3)).':…'
            : preg_replace('/\.\d+$/', '.xx', $this->server_ip);
    }
}
