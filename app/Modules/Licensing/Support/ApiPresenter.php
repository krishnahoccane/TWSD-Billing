<?php

namespace App\Modules\Licensing\Support;

use App\Modules\Licensing\Models\SoftwareActivation;
use App\Modules\Licensing\Models\SoftwareLicense;
use App\Modules\Licensing\Models\SoftwareOrder;
use App\Modules\Licensing\Models\SoftwarePlan;
use App\Modules\Licensing\Models\SoftwarePlanPrice;
use App\Modules\Licensing\Models\SoftwareProduct;
use App\Modules\Licensing\Models\SoftwareRelease;
use App\Modules\Licensing\Services\DownloadService;
use App\Modules\Organisations\Models\Organisation;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The JSON shapes of the storefront API (docs/LICENSING.md, MusicDesk spec
 * §5): prefixed public ids built from UUIDs, money in minor units with a
 * currency, dates in ISO 8601 UTC. Never a numeric id, a full key or a
 * secret.
 */
final class ApiPresenter
{
    public static function customerId(Organisation $organisation): string
    {
        return 'cus_'.$organisation->uuid;
    }

    /** @return array<string, mixed> */
    public static function customer(Organisation $organisation): array
    {
        return [
            'id' => self::customerId($organisation),
            'company' => $organisation->legal_name ?: $organisation->name,
            'billing_email' => $organisation->billing_email ?: $organisation->primary_contact_email,
            'country' => $organisation->country,
            'vat_id' => $organisation->gstin,
            'address' => [
                'line1' => $organisation->billing_address ?: $organisation->registered_address,
                'city' => $organisation->city,
                'state' => $organisation->state,
                'postal_code' => $organisation->postal_code,
            ],
            'customer_since' => self::date($organisation->created_at),
            'currency' => $organisation->currency,
        ];
    }

    /** @return array<string, mixed> */
    public static function catalog(SoftwareProduct $product, ?string $currency = null): array
    {
        $latest = $product->latestRelease();

        return [
            'product' => ['code' => $product->code, 'name' => $product->name, 'latest_version' => $latest?->version],
            'plans' => $product->plans->where('is_active', true)->values()->map(fn (SoftwarePlan $plan) => [
                'id' => 'plan_'.$plan->uuid,
                'code' => $plan->code,
                'name' => $plan->name,
                'featured' => $plan->is_featured,
                'blurb' => $plan->blurb,
                'features' => $plan->features ?? [],
                'included_domains' => $plan->included_domains,
                'prices' => $plan->prices->where('is_active', true)
                    ->when($currency, fn ($prices) => $prices->where('currency', $currency))
                    ->values()->map(fn (SoftwarePlanPrice $price) => [
                        'id' => $price->publicId(),
                        'kind' => $price->kind,
                        'amount' => $price->amount_minor,
                        'currency' => $price->currency,
                        'interval' => in_array($price->kind, ['annual', 'extra_domain', 'updates_renewal'], true) ? 'year' : null,
                    ])->all(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function license(SoftwareLicense $license, bool $withActivations = false): array
    {
        $production = $license->relationLoaded('activations')
            ? $license->activations->where('environment', SoftwareActivation::PRODUCTION)->whereIn('status', [SoftwareActivation::PENDING, SoftwareActivation::ACTIVE])->count()
            : $license->activations()->where('environment', SoftwareActivation::PRODUCTION)->whereIn('status', [SoftwareActivation::PENDING, SoftwareActivation::ACTIVE])->count();

        $data = [
            'id' => $license->publicId(),
            'number' => $license->number,
            'customer_id' => $license->organisation ? self::customerId($license->organisation) : null,
            'product' => $license->product?->code,
            'plan_code' => $license->plan?->code,
            'plan_name' => $license->plan?->name,
            'billing_type' => $license->billing_type,
            'status' => $license->displayStatus(),
            'status_reason' => $license->status_reason,
            'key_masked' => $license->maskedKey(),
            'domains_included' => $license->domains_included,
            'domains_extra' => $license->domains_extra,
            'domains_used' => $production,
            'purchased_at' => self::date($license->purchased_at),
            'expires_at' => self::date($license->expires_at),
            'support_until' => self::date($license->support_until),
            'pending_until' => self::date($license->pending_until),
            'max_version' => $license->max_version,
            // Newest version this license may download (released on or before support_until).
            'latest_available_version' => app(DownloadService::class)->entitledRelease($license)?->version,
            'auto_renew' => $license->auto_renew,
            'price_paid' => ['amount' => $license->price_paid_minor, 'currency' => $license->currency],
            'licensed_to' => $license->organisation ? ($license->organisation->legal_name ?: $license->organisation->name) : null,
        ];

        if ($withActivations) {
            $data['activations'] = $license->activations->where('status', '!=', SoftwareActivation::DEACTIVATED)->values()
                ->map(fn (SoftwareActivation $activation) => self::activation($activation->setRelation('license', $license)))->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function activation(SoftwareActivation $activation): array
    {
        return [
            'id' => $activation->publicId(),
            'license_id' => 'lic_'.$activation->license?->uuid,
            'domain' => $activation->domain,
            'environment' => $activation->environment,
            'status' => $activation->status,
            'server_ip' => $activation->maskedIp(),
            'version' => $activation->version,
            'php_version' => $activation->php_version,
            'last_check_in_at' => self::date($activation->last_check_in_at),
            'online' => $activation->isOnline(),
            'check_in_requested' => $activation->check_in_requested,
        ];
    }

    /** @return array<string, mixed> */
    public static function release(SoftwareRelease $release): array
    {
        return [
            'id' => $release->publicId(),
            'product' => $release->product?->code,
            'version' => $release->version,
            'released_at' => self::date($release->released_at),
            'channel' => $release->channel,
            'size_bytes' => $release->size_bytes,
            'sha256' => $release->sha256,
            'requirements' => $release->requirements ?? (object) [],
            'notes' => $release->notes ?? [],
        ];
    }

    /** @return array<string, mixed> */
    public static function order(SoftwareOrder $order): array
    {
        return [
            'id' => $order->publicId(),
            'kind' => $order->kind,
            'customer_id' => $order->organisation ? self::customerId($order->organisation) : null,
            'license_id' => $order->license ? $order->license->publicId() : null,
            'amount' => ['amount' => $order->amount_minor, 'currency' => $order->currency],
            'amount_usd' => $order->amount_usd_minor === null ? null : ['amount' => $order->amount_usd_minor, 'currency' => 'USD'],
            'payment_reference' => $order->payment_reference,
            'payment_status' => $order->payment_status ?? 'paid',
            'paid_at' => self::date($order->paid_at),
            'refunded_at' => self::date($order->refunded_at),
            'refund_reference' => $order->refund_reference,
            'invoice_number' => $order->invoice_number,
            'created_at' => self::date($order->created_at),
        ];
    }

    public static function date(?DateTimeInterface $date): ?string
    {
        // Immutable copy: never shift the model's own value.
        return $date === null ? null : CarbonImmutable::instance($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
