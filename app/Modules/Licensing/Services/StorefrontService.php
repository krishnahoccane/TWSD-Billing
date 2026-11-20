<?php

namespace App\Modules\Licensing\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Licensing\Models\Storefront;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Storefront credentials. The API key ("sk_live_…") and webhook secret
 * ("whsec_…") are returned ONCE, when created or rotated; the key is kept
 * only as a SHA-256 hash, the secret encrypted with APP_KEY.
 */
class StorefrontService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{0: Storefront, 1: string} the storefront and its new API key */
    public function create(string $code, string $name, ?string $webhookUrl, User $actor): array
    {
        $storefront = Storefront::query()->create([
            'code' => Str::lower($code),
            'name' => $name,
            'webhook_url' => $webhookUrl ?: null,
            'is_active' => true,
            'created_by' => $actor->getKey(),
        ]);

        $this->audit->record('licensing.storefront_created', $storefront, new: ['code' => $storefront->code, 'name' => $name], actor: $actor);

        return [$storefront, $this->rotateKey($storefront, $actor)];
    }

    public function rotateKey(Storefront $storefront, User $actor): string
    {
        $key = 'sk_live_'.Str::random(48);

        $storefront->forceFill(['api_key_hash' => hash('sha256', $key), 'api_key_hint' => substr($key, -4)])->save();
        $this->audit->record('licensing.storefront_key_rotated', $storefront, new: ['hint' => $storefront->api_key_hint], actor: $actor);

        return $key;
    }

    public function rotateWebhookSecret(Storefront $storefront, User $actor): string
    {
        $secret = 'whsec_'.Str::random(40);

        $storefront->forceFill(['webhook_secret' => $secret])->save();
        $this->audit->record('licensing.storefront_webhook_secret_rotated', $storefront, actor: $actor);

        return $secret;
    }

    public function update(Storefront $storefront, string $name, ?string $webhookUrl, bool $active, User $actor): void
    {
        $previous = $storefront->only(['name', 'webhook_url', 'is_active']);
        $storefront->forceFill(['name' => $name, 'webhook_url' => $webhookUrl ?: null, 'is_active' => $active])->save();
        $this->audit->record('licensing.storefront_updated', $storefront, previous: $previous, new: $storefront->only(['name', 'webhook_url', 'is_active']), actor: $actor);
    }

    public function authenticate(#[SensitiveParameter] string $key): ?Storefront
    {
        if (! str_starts_with($key, 'sk_') || strlen($key) > 200) {
            return null;
        }

        return Storefront::query()->where('api_key_hash', hash('sha256', $key))->where('is_active', true)->first();
    }

    /** Let a storefront act for an existing organisation (Super Admin). */
    public function linkCustomer(Storefront $storefront, Organisation $organisation, User $actor): void
    {
        $storefront->customers()->syncWithoutDetaching([$organisation->getKey()]);
        $this->audit->record('licensing.storefront_customer_linked', $storefront, new: ['storefront' => $storefront->code],
            organisation: $organisation, actor: $actor);
    }
}
