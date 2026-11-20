<?php

namespace App\Modules\Licensing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Licensing\Exceptions\LicensingException;
use App\Modules\Licensing\Models\SoftwareLicense;
use App\Modules\Licensing\Models\SoftwareOrder;
use App\Modules\Licensing\Models\SoftwarePlan;
use App\Modules\Licensing\Models\SoftwareProduct;
use App\Modules\Licensing\Models\Storefront;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Services\OrganisationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies a paid order reported by a storefront. The storefront took the
 * payment (Razorpay) and issued its own invoice; this application records
 * the order and changes the license accordingly. Idempotent on the
 * storefront's Idempotency-Key.
 */
class OrderService
{
    public function __construct(
        private readonly LicenseService $licenses,
        private readonly ActivationService $activations,
        private readonly OrganisationService $organisations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request
     */
    public function apply(Storefront $storefront, array $data, string $idempotencyKey, ?string $actingUser): SoftwareOrder
    {
        // One request per idempotency key at a time; a retry waits and then gets the same order.
        return Cache::lock('storefront-order:'.$storefront->getKey().':'.hash('sha256', $idempotencyKey), 60)
            // All or nothing: a refused domain or plan leaves no license behind.
            ->block(30, fn () => DB::transaction(fn () => $this->applyOnce($storefront, $data, $idempotencyKey, $actingUser)));
    }

    /** @param  array<string, mixed>  $data */
    private function applyOnce(Storefront $storefront, array $data, string $idempotencyKey, ?string $actingUser): SoftwareOrder
    {
        if ($existing = SoftwareOrder::query()->where('storefront_id', $storefront->getKey())->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $label = 'storefront:'.$storefront->code.($actingUser ? ' ('.$actingUser.')' : '');
        $kind = $data['kind'];
        $currency = strtoupper($data['amount']['currency']);

        $license = null;
        $organisation = null;

        if ($kind === 'new_license') {
            $organisation = $this->customer($storefront, $data['customer'] ?? [], $currency);
            $product = SoftwareProduct::query()->where('storefront_id', $storefront->getKey())->where('code', $data['product'] ?? '')->where('is_active', true)->first()
                ?? throw new LicensingException('product_not_found', 'Unknown product for this storefront.', 422, 'product');
            $plan = SoftwarePlan::query()->where('software_product_id', $product->getKey())->where('code', $data['plan_code'] ?? '')->first()
                ?? throw new LicensingException('plan_not_found', 'Unknown plan.', 422, 'plan_code');

            $license = $this->licenses->create($organisation, $product, $plan, [
                'billing_type' => $data['billing_type'] ?? 'annual',
                'currency' => $currency,
                'price_paid_minor' => (int) $data['amount']['amount'],
                'domains_extra' => (int) ($data['extra_domains'] ?? 0),
                'pending_days' => ($data['payment_status'] ?? 'paid') === 'pending' ? 14 : null,
                'auto_renew' => $data['auto_renew'] ?? null,
                'storefront' => $storefront,
            ], null, $label);

            if (filled($data['primary_domain'] ?? null)) {
                $this->activations->add($license, (string) $data['primary_domain'], null, null, $label);
            }
        } else {
            $license = $this->license($storefront, (string) ($data['license_id'] ?? ''));
            $organisation = $license->organisation;

            match ($kind) {
                'renewal' => $this->licenses->renew($license, null, $label),
                'updates_renewal' => $this->licenses->renew($license, null, $label, updatesOnly: true),
                'extra_domains' => $this->licenses->addDomains($license, (int) ($data['extra_domains'] ?? 0), null, $label),
                'change_plan' => $this->licenses->changePlan($license,
                    SoftwarePlan::query()->where('software_product_id', $license->software_product_id)->where('code', $data['plan_code'] ?? '')->first()
                        ?? throw new LicensingException('plan_not_found', 'Unknown plan.', 422, 'plan_code'), null, $label),
                'convert_lifetime' => $this->licenses->convertToLifetime($license, null, $label),
                default => throw new LicensingException('invalid_kind', 'Unknown order kind.', 422, 'kind'),
            };
        }

        try {
            $order = SoftwareOrder::query()->create([
                'storefront_id' => $storefront->getKey(),
                'organisation_id' => $organisation->getKey(),
                'software_license_id' => $license?->getKey(),
                'idempotency_key' => $idempotencyKey,
                'kind' => $kind,
                'amount_minor' => (int) $data['amount']['amount'],
                'amount_usd_minor' => isset($data['amount_usd']) ? (int) $data['amount_usd'] : ($currency === 'USD' ? (int) $data['amount']['amount'] : null),
                'currency' => $currency,
                'payment_provider' => $data['payment_provider'] ?? null,
                'payment_reference' => $data['payment_reference'] ?? null,
                'payment_status' => ($data['payment_status'] ?? 'paid') === 'pending' ? SoftwareOrder::PENDING : SoftwareOrder::PAID,
                'paid_at' => ($data['payment_status'] ?? 'paid') === 'pending' ? null : now(),
                'invoice_number' => $data['invoice_number'] ?? null,
                'acting_user' => $actingUser,
                'details' => array_filter(['plan_code' => $data['plan_code'] ?? null, 'billing_type' => $data['billing_type'] ?? null,
                    'extra_domains' => $data['extra_domains'] ?? null, 'payment_status' => $data['payment_status'] ?? 'paid']),
            ]);
        } catch (QueryException $e) {
            // Two identical requests at once: the first one's order wins.
            return SoftwareOrder::query()->where('storefront_id', $storefront->getKey())->where('idempotency_key', $idempotencyKey)->first() ?? throw $e;
        }

        if ($license !== null) {
            $this->licenses->event($license, 'order_recorded', ['kind' => $kind, 'amount' => $order->amount_minor, 'currency' => $currency,
                'invoice' => $order->invoice_number], $label);
        }

        $this->audit->record('licensing.order_recorded', $order, new: [
            'kind' => $kind, 'amount_minor' => $order->amount_minor, 'currency' => $currency,
            'payment_reference' => $order->payment_reference, 'by' => $label,
        ], organisation: $organisation);

        return $order;
    }

    /**
     * A pending (bank-transfer) order has been paid on the storefront. Idempotent: confirming
     * an order that is already paid returns it unchanged. A refunded order can't be confirmed.
     */
    public function confirmPayment(SoftwareOrder $order, ?string $paymentReference, ?\DateTimeInterface $paidAt, ?string $actingUser): SoftwareOrder
    {
        $label = $this->label($order->storefront, $actingUser);

        $order = DB::transaction(function () use ($order, $paymentReference, $paidAt, $label) {
            $order = SoftwareOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($order->payment_status === SoftwareOrder::REFUNDED) {
                throw new LicensingException('order_refunded', 'This order was refunded and can no longer be confirmed.');
            }

            if ($order->payment_status === SoftwareOrder::PAID) {
                return $order;
            }

            $order->forceFill([
                'payment_status' => SoftwareOrder::PAID,
                'paid_at' => $paidAt ?? now(),
                'payment_reference' => filled($paymentReference) ? $paymentReference : $order->payment_reference,
            ])->save();

            if ($license = $order->license) {
                $this->licenses->confirmPayment($license, (string) $order->payment_reference, $label);
            }

            $this->audit->record('licensing.order_payment_confirmed', $order, new: [
                'payment_reference' => $order->payment_reference, 'by' => $label,
            ], organisation: $order->organisation);

            return $order;
        });

        return $order->fresh(['organisation', 'license']);
    }

    /**
     * The storefront refunded an order. A refunded new_license order revokes its license;
     * for other kinds (renewal, extra domains…) the refund is recorded on the license
     * timeline and a Super Admin decides what to change. Idempotent.
     */
    public function refund(SoftwareOrder $order, ?string $refundReference, ?string $reason, ?string $actingUser): SoftwareOrder
    {
        $label = $this->label($order->storefront, $actingUser);

        $order = DB::transaction(function () use ($order, $refundReference, $reason, $label) {
            $order = SoftwareOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($order->payment_status === SoftwareOrder::REFUNDED) {
                return $order;
            }

            $reason = Str::limit(filled($reason) ? (string) $reason : 'Refunded on the storefront', 500, '');
            $order->forceFill([
                'payment_status' => SoftwareOrder::REFUNDED,
                'refunded_at' => now(),
                'refund_reference' => $refundReference,
                'refund_reason' => $reason,
            ])->save();

            if ($license = $order->license) {
                if ($order->kind === 'new_license') {
                    $this->licenses->revokeForRefund($license, $reason, $label);
                } else {
                    $this->licenses->event($license, 'order_refunded', ['kind' => $order->kind, 'invoice' => $order->invoice_number, 'reason' => $reason], $label);
                }
            }

            $this->audit->record('licensing.order_refunded', $order, new: [
                'kind' => $order->kind, 'refund_reference' => $refundReference, 'reason' => $reason, 'by' => $label,
            ], organisation: $order->organisation);

            return $order;
        });

        return $order->fresh(['organisation', 'license']);
    }

    /** Resolve "ord_<uuid>": only this storefront's orders, and only the X-Customer-Id customer's when given (404 otherwise). */
    public function order(Storefront $storefront, string $orderId, ?Organisation $customer = null): SoftwareOrder
    {
        $uuid = str_starts_with($orderId, 'ord_') ? substr($orderId, 4) : null;
        $order = $uuid && Str::isUuid($uuid)
            ? SoftwareOrder::query()->where('uuid', $uuid)->where('storefront_id', $storefront->getKey())
                ->when($customer, fn ($q) => $q->where('organisation_id', $customer->getKey()))
                ->with(['organisation', 'license', 'storefront'])->first()
            : null;

        return $order ?? throw new LicensingException('order_not_found', 'Order not found.', 404);
    }

    private function label(?Storefront $storefront, ?string $actingUser): string
    {
        return 'storefront:'.($storefront?->code ?? 'unknown').($actingUser ? ' ('.$actingUser.')' : '');
    }

    /** The storefront's own customer, or a new organisation linked to it. */
    public function customer(Storefront $storefront, array $customer, ?string $currency = null): Organisation
    {
        if (filled($customer['id'] ?? null)) {
            return $this->ownedCustomer($storefront, (string) $customer['id']);
        }

        foreach (['company', 'email', 'country'] as $field) {
            if (blank($customer[$field] ?? null)) {
                throw new LicensingException('validation_failed', "customer.{$field} is required for a new customer.", 422, "customer.{$field}");
            }
        }

        return DB::transaction(function () use ($storefront, $customer, $currency) {
            $organisation = $this->organisations->create([
                'code' => $this->uniqueCode((string) $customer['company']),
                'name' => Str::limit((string) $customer['company'], 255, ''),
                'legal_name' => Str::limit((string) $customer['company'], 255, ''),
                'billing_email' => Str::lower((string) $customer['email']),
                'primary_contact_name' => isset($customer['name']) ? Str::limit((string) $customer['name'], 255, '') : null,
                'primary_contact_email' => Str::lower((string) $customer['email']),
                'country' => strtoupper((string) $customer['country']),
                'notes' => 'Created by storefront '.$storefront->code.(filled($customer['vat_id'] ?? null) ? '. VAT ID: '.Str::limit((string) $customer['vat_id'], 40, '') : ''),
            ], null);

            $organisation->forceFill(['currency' => $currency])->save();
            $storefront->customers()->syncWithoutDetaching([$organisation->getKey()]);

            return $organisation;
        });
    }

    /** Resolve "cus_<uuid>" — only a customer linked to this storefront (404 otherwise). */
    public function ownedCustomer(Storefront $storefront, string $customerId): Organisation
    {
        $uuid = str_starts_with($customerId, 'cus_') ? substr($customerId, 4) : null;
        $organisation = $uuid && Str::isUuid($uuid)
            ? $storefront->customers()->where('organisations.uuid', $uuid)->first()
            : null;

        return $organisation ?? throw new LicensingException('customer_not_found', 'Customer not found.', 404);
    }

    /** Resolve "lic_<uuid>" — only a license of this storefront's products (404 otherwise). */
    public function license(Storefront $storefront, string $licenseId, ?Organisation $customer = null): SoftwareLicense
    {
        $uuid = str_starts_with($licenseId, 'lic_') ? substr($licenseId, 4) : null;
        $license = $uuid && Str::isUuid($uuid)
            ? SoftwareLicense::query()->where('uuid', $uuid)
                ->whereIn('software_product_id', $storefront->products()->select('id'))
                ->when($customer, fn ($q) => $q->where('organisation_id', $customer->getKey()))
                ->with(['organisation', 'plan', 'product'])->first()
            : null;

        return $license ?? throw new LicensingException('license_not_found', 'License not found.', 404);
    }

    private function uniqueCode(string $company): string
    {
        $base = Str::limit(strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', Str::ascii($company))), 12, '') ?: 'CUST';
        $code = $base;

        for ($i = 2; Organisation::query()->where('code', $code)->exists(); $i++) {
            $code = Str::limit($base, 10, '').'-'.$i;
        }

        return $code;
    }
}
