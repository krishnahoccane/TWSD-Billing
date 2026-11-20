<?php

namespace App\Modules\Licensing\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Licensing\Exceptions\LicensingException;
use App\Modules\Licensing\Models\SoftwareLicense;
use App\Modules\Licensing\Models\SoftwareLicenseEvent;
use App\Modules\Licensing\Models\SoftwarePlan;
use App\Modules\Licensing\Models\SoftwareProduct;
use App\Modules\Licensing\Models\Storefront;
use App\Modules\Licensing\Support\LicenseKey;
use App\Modules\Organisations\Models\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every change to a license goes through here: created, renewed, plan
 * changed, domains added, suspended/revoked/reinstated, key revealed.
 * Each change writes the license timeline, the audit log and a webhook to
 * the storefront the license belongs to.
 */
class LicenseService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StorefrontWebhooks $webhooks,
    ) {}

    /**
     * @param  array{billing_type: string, currency: string, price_paid_minor?: int, domains_extra?: int,
     *               purchased_at?: CarbonImmutable, pending_days?: ?int, auto_renew?: bool, storefront?: ?Storefront}  $options
     */
    public function create(Organisation $organisation, SoftwareProduct $product, SoftwarePlan $plan, array $options, ?User $actor, string $actorLabel): SoftwareLicense
    {
        if ($plan->software_product_id !== $product->getKey()) {
            throw new LicensingException('plan_mismatch', 'That plan does not belong to this product.', 422, 'plan_code');
        }

        if (! in_array($options['billing_type'], ['annual', 'lifetime'], true)) {
            throw new LicensingException('invalid_billing_type', 'Billing type must be annual or lifetime.', 422, 'billing_type');
        }

        $purchased = $options['purchased_at'] ?? CarbonImmutable::now();
        $annual = $options['billing_type'] === 'annual';
        $key = LicenseKey::generate($product->key_prefix ?: 'KEY');

        $license = DB::transaction(function () use ($organisation, $product, $plan, $options, $purchased, $annual, $key, $actor, $actorLabel) {
            $license = SoftwareLicense::query()->create([
                'number' => $this->nextNumber(),
                'organisation_id' => $organisation->getKey(),
                'software_product_id' => $product->getKey(),
                'software_plan_id' => $plan->getKey(),
                'storefront_id' => ($options['storefront'] ?? null)?->getKey() ?? $product->storefront_id,
                'billing_type' => $options['billing_type'],
                'status' => ($options['pending_days'] ?? null) ? SoftwareLicense::PENDING : SoftwareLicense::ACTIVE,
                'pending_until' => ($options['pending_days'] ?? null) ? $purchased->addDays((int) $options['pending_days']) : null,
                'key_hash' => LicenseKey::hash($key),
                'key_encrypted' => $key,
                'key_last4' => substr($key, -4),
                'domains_included' => $plan->included_domains,
                'domains_extra' => max(0, (int) ($options['domains_extra'] ?? 0)),
                'purchased_at' => $purchased,
                // Annual: one year. Lifetime: no expiry, 12 months of updates and support.
                'expires_at' => $annual ? $purchased->addYear() : null,
                'support_until' => $purchased->addYear(),
                'auto_renew' => (bool) ($options['auto_renew'] ?? $annual),
                'price_paid_minor' => (int) ($options['price_paid_minor'] ?? 0),
                'currency' => strtoupper($options['currency']),
            ]);

            $this->event($license, 'created', ['plan' => $plan->code, 'billing_type' => $license->billing_type, 'domains' => $license->domainLimit()], $actorLabel);
            $this->audit->record('licensing.license_created', $license, new: [
                'number' => $license->number, 'plan' => $plan->code, 'billing_type' => $license->billing_type,
            ], organisation: $organisation, actor: $actor);

            return $license;
        });

        $this->webhooks->licenseEvent($license, 'license.created');

        return $license;
    }

    /** Annual: one more year from the later of now and the current expiry. Lifetime "updates renewal": one more year of support. */
    public function renew(SoftwareLicense $license, ?User $actor, string $actorLabel, bool $updatesOnly = false): SoftwareLicense
    {
        $this->requireUsable($license);

        return $this->change($license, $updatesOnly ? 'updates_renewed' : 'renewed', function (SoftwareLicense $license) use ($updatesOnly) {
            $now = CarbonImmutable::now();

            if (! $updatesOnly && $license->billing_type === 'annual') {
                $from = $license->expires_at !== null && $license->expires_at->gt($now) ? CarbonImmutable::parse($license->expires_at) : $now;
                $license->expires_at = $from->addYear();
            }

            $supportFrom = $license->support_until !== null && $license->support_until->gt($now) ? CarbonImmutable::parse($license->support_until) : $now;
            $license->support_until = $updatesOnly || $license->billing_type === 'lifetime' ? $supportFrom->addYear() : $license->expires_at;
            $license->max_version = null;

            if ($license->status === SoftwareLicense::PENDING) {
                $license->status = SoftwareLicense::ACTIVE;
                $license->pending_until = null;
            }
        }, $actor, $actorLabel, 'license.updated');
    }

    public function addDomains(SoftwareLicense $license, int $count, ?User $actor, string $actorLabel): SoftwareLicense
    {
        if ($count < 1 || $count > 100) {
            throw new LicensingException('invalid_quantity', 'Add between 1 and 100 domains.', 422, 'extra_domains');
        }

        $this->requireUsable($license);

        return $this->change($license, 'domains_added', fn (SoftwareLicense $l) => $l->domains_extra += $count, $actor, $actorLabel, 'license.updated', ['added' => $count]);
    }

    /** Upgrade (or change) tier: key, domains and dates are kept. */
    public function changePlan(SoftwareLicense $license, SoftwarePlan $plan, ?User $actor, string $actorLabel): SoftwareLicense
    {
        if ($plan->software_product_id !== $license->software_product_id) {
            throw new LicensingException('plan_mismatch', 'That plan belongs to another product.', 422, 'plan_code');
        }

        $this->requireUsable($license);
        $from = $license->plan?->code;

        return $this->change($license, 'plan_changed', function (SoftwareLicense $l) use ($plan) {
            $l->software_plan_id = $plan->getKey();
            $l->domains_included = $plan->included_domains;
        }, $actor, $actorLabel, 'license.updated', ['from' => $from, 'to' => $plan->code]);
    }

    /** Annual → lifetime: no expiry, 12 months of updates from now. */
    public function convertToLifetime(SoftwareLicense $license, ?User $actor, string $actorLabel): SoftwareLicense
    {
        if ($license->billing_type === 'lifetime') {
            throw new LicensingException('already_lifetime', 'This license is already a lifetime license.');
        }

        $this->requireUsable($license);

        return $this->change($license, 'converted_to_lifetime', function (SoftwareLicense $l) {
            $l->billing_type = 'lifetime';
            $l->expires_at = null;
            $l->auto_renew = false;
            $l->support_until = CarbonImmutable::now()->addYear();
            $l->max_version = null;
        }, $actor, $actorLabel, 'license.updated');
    }

    public function setAutoRenew(SoftwareLicense $license, bool $autoRenew, ?User $actor, string $actorLabel): SoftwareLicense
    {
        return $this->change($license, $autoRenew ? 'auto_renew_on' : 'auto_renew_off', fn (SoftwareLicense $l) => $l->auto_renew = $autoRenew,
            $actor, $actorLabel, 'license.updated');
    }

    /** Super Admin: suspend (installs go read-only), revoke (permanent), reinstate, or extend dates. */
    public function setStatus(SoftwareLicense $license, string $status, string $reason, User $actor): SoftwareLicense
    {
        if (! in_array($status, [SoftwareLicense::ACTIVE, SoftwareLicense::SUSPENDED, SoftwareLicense::REVOKED], true)) {
            throw new LicensingException('invalid_status', 'Unknown status.', 422, 'status');
        }

        if ($license->status === SoftwareLicense::REVOKED) {
            throw new LicensingException('license_revoked', 'A revoked license cannot be changed.');
        }

        $event = match ($status) {
            SoftwareLicense::SUSPENDED => 'suspended',
            SoftwareLicense::REVOKED => 'revoked',
            default => 'reinstated',
        };

        return $this->change($license, $event, function (SoftwareLicense $l) use ($status, $reason) {
            $l->status = $status;
            $l->status_reason = $status === SoftwareLicense::ACTIVE ? null : $reason;
            $l->pending_until = $status === SoftwareLicense::ACTIVE ? null : $l->pending_until;
        }, $actor, $actor->name, 'license.'.($status === SoftwareLicense::ACTIVE ? 'updated' : $event), ['reason' => $reason]);
    }

    /**
     * Storefront: the bank transfer for a pending license has arrived. Pending (even past
     * its grace period) becomes active; any other status is left as it is.
     */
    public function confirmPayment(SoftwareLicense $license, string $paymentReference, string $actorLabel): SoftwareLicense
    {
        if ($license->status !== SoftwareLicense::PENDING) {
            return $license;
        }

        return $this->change($license, 'payment_confirmed', function (SoftwareLicense $l) {
            $l->status = SoftwareLicense::ACTIVE;
            $l->pending_until = null;
        }, null, $actorLabel, 'license.updated', ['payment_reference' => $paymentReference]);
    }

    /** Storefront: the purchase was refunded, so the license is revoked (installs are refused at their next check-in). */
    public function revokeForRefund(SoftwareLicense $license, string $reason, string $actorLabel): SoftwareLicense
    {
        if ($license->status === SoftwareLicense::REVOKED) {
            return $license;
        }

        return $this->change($license, 'revoked', function (SoftwareLicense $l) use ($reason) {
            $l->status = SoftwareLicense::REVOKED;
            $l->status_reason = $reason;
        }, null, $actorLabel, 'license.revoked', ['reason' => $reason, 'refund' => true]);
    }

    public function changeDates(SoftwareLicense $license, ?CarbonImmutable $expiresAt, ?CarbonImmutable $supportUntil, string $reason, User $actor): SoftwareLicense
    {
        return $this->change($license, 'dates_changed', function (SoftwareLicense $l) use ($expiresAt, $supportUntil) {
            if ($l->billing_type === 'annual' && $expiresAt !== null) {
                $l->expires_at = $expiresAt;
            }

            if ($supportUntil !== null) {
                $l->support_until = $supportUntil;
            }
        }, $actor, $actor->name, 'license.updated', ['reason' => $reason]);
    }

    /** The full key, for the owner. Every reveal is audited. */
    public function revealKey(SoftwareLicense $license, ?User $actor, string $actorLabel): string
    {
        $this->event($license, 'key_revealed', null, $actorLabel);
        $this->audit->record('licensing.license_key_revealed', $license, new: ['by' => $actorLabel], organisation: $license->organisation, actor: $actor);

        return (string) $license->key_encrypted;
    }

    public function findByKey(string $key): ?SoftwareLicense
    {
        return SoftwareLicense::query()->where('key_hash', LicenseKey::hash($key))->first();
    }

    /** @param  array<string, mixed>|null  $details */
    public function event(SoftwareLicense $license, string $event, ?array $details, string $actorLabel): void
    {
        SoftwareLicenseEvent::query()->create([
            'software_license_id' => $license->getKey(),
            'event' => $event,
            'details' => $details,
            'actor' => Str::limit($actorLabel, 115, ''),
        ]);
    }

    /**
     * @param  callable(SoftwareLicense): mixed  $mutate
     * @param  array<string, mixed>  $details
     */
    private function change(SoftwareLicense $license, string $event, callable $mutate, ?User $actor, string $actorLabel, string $webhook, array $details = []): SoftwareLicense
    {
        $license = DB::transaction(function () use ($license, $event, $mutate, $actor, $actorLabel, $details) {
            $license = SoftwareLicense::query()->whereKey($license->getKey())->lockForUpdate()->firstOrFail();
            $before = $license->only(['status', 'software_plan_id', 'billing_type', 'domains_extra', 'expires_at', 'support_until', 'auto_renew']);

            $mutate($license);
            $license->save();

            $after = $license->only(array_keys($before));
            $this->event($license, $event, $details ?: null, $actorLabel);
            $this->audit->record('licensing.license_'.$event, $license, previous: self::plain($before), new: self::plain($after) + $details,
                organisation: $license->organisation, actor: $actor);

            return $license;
        });

        $this->webhooks->licenseEvent($license, $webhook);

        return $license;
    }

    private function requireUsable(SoftwareLicense $license): void
    {
        if (in_array($license->status, [SoftwareLicense::SUSPENDED, SoftwareLicense::REVOKED], true)) {
            throw new LicensingException('license_'.$license->status, 'This license is '.$license->status.'.');
        }
    }

    /** LIC-XXXX, unique. */
    private function nextNumber(): string
    {
        do {
            $number = 'LIC-'.strtoupper(Str::random(6));
            $number = str_replace(['0', 'O', '1', 'I', 'L'], ['7', 'Q', '8', 'J', 'M'], $number);
        } while (SoftwareLicense::query()->where('number', $number)->exists());

        return $number;
    }

    /** @param  array<string, mixed>  $values  @return array<string, mixed> */
    private static function plain(array $values): array
    {
        return array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value, $values);
    }
}
