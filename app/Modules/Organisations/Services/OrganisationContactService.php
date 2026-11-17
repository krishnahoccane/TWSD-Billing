<?php

namespace App\Modules\Organisations\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Models\OrganisationContact;
use Illuminate\Support\Facades\DB;

/**
 * Callers authorise first (OrganisationPolicy::manageContacts with the contact type).
 */
class OrganisationContactService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function create(Organisation $organisation, array $data, User $actor): OrganisationContact
    {
        return DB::transaction(function () use ($organisation, $data, $actor) {
            $contact = new OrganisationContact($data);
            $contact->organisation_id = $organisation->getKey();
            $contact->created_by = $actor->getKey();
            $contact->save();

            $this->audit->record(
                action: 'organisation.contact.created',
                resource: $contact,
                new: $data,
                organisation: $organisation,
                actor: $actor,
            );

            return $contact;
        });
    }

    public function delete(OrganisationContact $contact, User $actor): void
    {
        DB::transaction(function () use ($contact, $actor) {
            $snapshot = $contact->only(['type', 'name', 'email', 'phone', 'designation']);
            $organisation = $contact->organisation;

            $contact->delete();

            $this->audit->record(
                action: 'organisation.contact.deleted',
                resource: $contact,
                previous: $snapshot,
                organisation: $organisation,
                actor: $actor,
            );
        });
    }
}
