<?php

namespace App\Modules\Domains\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Domains\Enums\DomainType;
use App\Modules\Domains\Models\GoogleDomain;
use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Services\GoogleAccountService;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    /**
     * Assigns a domain found in Google (Unassigned Domains) to an organisation.
     * Google's verification state is copied onto the mapping. With
     * $withAccounts, the unassigned mailboxes on the domain are assigned too,
     * each through GoogleAccountService::assignOrganisation (audited, billed
     * from today). Mailboxes that already belong to an organisation are never moved.
     *
     * @return array{domain: OrganisationDomain, accounts: int}
     */
    public function assignGoogleDomain(GoogleDomain $googleDomain, Organisation $organisation, User $actor, bool $withAccounts = false): array
    {
        return DB::transaction(function () use ($googleDomain, $organisation, $actor, $withAccounts) {
            $googleDomain = GoogleDomain::query()->whereKey($googleDomain->getKey())->lockForUpdate()->firstOrFail();

            if ($googleDomain->organisation_domain_id !== null
                || OrganisationDomain::query()->where('domain_name', $googleDomain->domain_name)->exists()) {
                throw ValidationException::withMessages(['organisation' => 'This domain is already mapped to an organisation.']);
            }

            $domain = $this->map($organisation, $googleDomain->domain_name, $googleDomain->isAlias() ? DomainType::Alias : DomainType::Secondary, $actor);

            $domain->forceFill([
                'verification_status' => $googleDomain->verified ? 'verified' : 'pending',
                'is_primary' => $googleDomain->is_primary,
                'google_domain_id' => $googleDomain->domain_name,
            ])->save();

            $googleDomain->forceFill(['organisation_domain_id' => $domain->getKey()])->save();

            $assigned = 0;

            if ($withAccounts) {
                $accounts = app(GoogleAccountService::class);

                GoogleAccount::query()
                    ->with('organisation')
                    ->whereNull('organisation_id')
                    ->where('primary_email', 'like', '%@'.addcslashes($domain->domain_name, '%_\\'))
                    ->orderBy('id')
                    ->each(function (GoogleAccount $account) use ($accounts, $organisation, $actor, &$assigned) {
                        $accounts->assignOrganisation($account, $organisation, $actor);
                        $assigned++;
                    });
            }

            return ['domain' => $domain, 'accounts' => $assigned];
        });
    }

    /** Unassigned mailboxes on a domain (shown before assigning it). */
    public static function unassignedAccountsOn(string $domainName): int
    {
        return GoogleAccount::query()
            ->whereNull('organisation_id')
            ->where('primary_email', 'like', '%@'.addcslashes($domainName, '%_\\'))
            ->count();
    }
}
