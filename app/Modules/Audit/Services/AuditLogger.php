<?php

namespace App\Modules\Audit\Services;

use App\Models\User;
use App\Modules\AccessControl\Services\AccessResolver;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Central audit writer. Every administrative operation goes through
 * record(); sensitive keys are redacted before anything is persisted.
 */
class AuditLogger
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILURE = 'failure';

    public const STATUS_DENIED = 'denied';

    private const REDACTED = '[redacted]';

    /** Any key containing one of these fragments is redacted. */
    private const SENSITIVE_FRAGMENTS = [
        'password', 'secret', 'token', 'private_key', 'recovery_code',
        'authorization', 'credential', 'api_key', 'otp', 'service_account',
    ];

    public function __construct(private readonly AccessResolver $resolver) {}

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $new
     */
    public function record(
        string $action,
        ?Model $resource = null,
        ?array $previous = null,
        ?array $new = null,
        ?Organisation $organisation = null,
        string $status = self::STATUS_SUCCESS,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return AuditLog::query()->create([
            'user_id' => $actor?->getKey(),
            'user_email' => $actor?->email,
            'user_role' => $actor ? Str::limit(implode(', ', $this->resolver->roleLabels($actor)), 188) : null,
            'organisation_id' => $organisation?->getKey(),
            'action' => $action,
            'resource_type' => $resource?->getMorphClass(),
            'resource_id' => $resource ? (string) ($resource->getAttributes()['uuid'] ?? $resource->getKey()) : null,
            'previous_data' => $previous === null ? null : $this->redact($previous),
            'new_data' => $new === null ? null : $this->redact($new),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 509) : null,
            'request_id' => Context::get('request_id'),
            'status' => $status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && Str::contains(Str::lower($key), self::SENSITIVE_FRAGMENTS)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
