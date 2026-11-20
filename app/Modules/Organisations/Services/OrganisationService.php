<?php

namespace App\Modules\Organisations\Services;

use App\Models\User;
use App\Modules\AccessControl\Services\AccessResolver;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organisations\Enums\OrganisationStatus;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes to organisations. Callers authorise first (OrganisationPolicy).
 */
class OrganisationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function create(array $data, ?User $actor): Organisation
    {
        return DB::transaction(function () use ($data, $actor) {
            $organisation = new Organisation($this->normalise($data));
            $organisation->status = OrganisationStatus::Active;
            $organisation->created_by = $actor?->getKey();   // null: created by a storefront
            $organisation->updated_by = $actor?->getKey();
            $organisation->save();

            $this->audit->record(
                action: 'organisation.created',
                resource: $organisation,
                new: Arr::except($organisation->getAttributes(), ['id', 'created_by', 'updated_by']),
                organisation: $organisation,
                actor: $actor,
            );

            return $organisation;
        });
    }

    /**
     * The organisation code is immutable after creation: it is a stable
     * external reference (invoices, exports, Zoho).
     *
     * @param  array<string, mixed>  $data  validated input
     */
    public function update(Organisation $organisation, array $data, User $actor): Organisation
    {
        return DB::transaction(function () use ($organisation, $data, $actor) {
            $organisation->fill(Arr::except($this->normalise($data), ['code']));

            if (! $organisation->isDirty()) {
                return $organisation;
            }

            $changes = $organisation->getDirty();
            $previous = Arr::only($organisation->getRawOriginal(), array_keys($changes));

            $organisation->updated_by = $actor->getKey();
            $organisation->save();

            $this->audit->record(
                action: 'organisation.updated',
                resource: $organisation,
                previous: $previous,
                new: $changes,
                organisation: $organisation,
                actor: $actor,
            );

            return $organisation;
        });
    }

    /**
     * Changes status only. Google accounts, domains, invoices and payments
     * are never touched by a status change (business rule).
     */
    public function changeStatus(Organisation $organisation, OrganisationStatus $status, string $reason, User $actor): Organisation
    {
        if ($organisation->status === $status) {
            return $organisation;
        }

        return DB::transaction(function () use ($organisation, $status, $reason, $actor) {
            $previous = $organisation->status;

            $organisation->status = $status;
            $organisation->updated_by = $actor->getKey();
            $organisation->save();

            $this->audit->record(
                action: 'organisation.status_changed',
                resource: $organisation,
                previous: ['status' => $previous->value],
                new: ['status' => $status->value, 'reason' => $reason],
                organisation: $organisation,
                actor: $actor,
            );

            // Status affects what organisation users may do (decision D-06).
            $this->resolver->forget();

            return $organisation;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        foreach (['code', 'pan', 'gstin', 'country'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = Str::upper(trim($data[$key]));
            }
        }

        foreach (['primary_contact_email', 'billing_email'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = Str::lower(trim($data[$key]));
            }
        }

        return $data;
    }
}
