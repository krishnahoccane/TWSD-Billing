<?php

namespace App\Modules\Domains\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Domains\Enums\DomainType;
use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Super Admin mapping of a domain that ALREADY exists in the Google
 * Workspace customer to the organisation that owns it. This is what the
 * Phase C import uses to match accounts to organisations.
 *
 * It does not add anything to Google and does not prove ownership; the
 * mapping stays "unverified" until it is checked against Google (Phase C).
 * New domains go through the request/approval workflow (Phase D).
 */
class DomainMappingService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public static function normalise(string $domain): string
    {
        return Str::of($domain)->trim()->lower()->rtrim('.')->toString();
    }

    public function map(Organisation $organisation, string $domainName, DomainType $type, User $actor): OrganisationDomain
    {
        return DB::transaction(function () use ($organisation, $domainName, $type, $actor) {
            $domain = new OrganisationDomain([
                'domain_name' => self::normalise($domainName),
                'domain_type' => $type,
            ]);
            $domain->organisation_id = $organisation->getKey();
            $domain->created_by = $actor->getKey();
            $domain->save();

            $this->audit->record(
                action: 'domain.mapped',
                resource: $domain,
                new: ['domain_name' => $domain->domain_name, 'domain_type' => $type->value],
                organisation: $organisation,
                actor: $actor,
            );

            return $domain;
        });
    }
}
