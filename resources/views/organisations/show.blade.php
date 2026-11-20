@php
    use App\Modules\Organisations\Enums\ContactType;
    use App\Modules\Organisations\Enums\OrganisationStatus;

    $user = auth()->user();
    $canEdit = $user->can('update', $organisation);
    $canChangeStatus = $user->can('changeStatus', $organisation);
    $canManageMembers = $user->can('manageMembers', $organisation);
    $canMapDomains = $user->can('mapDomains', $organisation);
    $canViewDomains = $user->can('viewDomains', $organisation);
    $contactTypes = collect(ContactType::cases())
        ->filter(fn (ContactType $type) => $user->can('manageContacts', [$organisation, $type]))
        ->mapWithKeys(fn (ContactType $type) => [$type->value => $type->label()])
        ->all();

    $details = [
        'Legal name' => $organisation->legal_name,
        'Code' => $organisation->code,
        'PAN' => $organisation->pan,
        'GSTIN' => $organisation->gstin,
        'Primary contact' => $organisation->primary_contact_name,
        'Contact email' => $organisation->primary_contact_email,
        'Phone' => $organisation->contact_phone,
        'Billing email' => $organisation->billing_email,
        'Registered address' => $organisation->registered_address,
        'Billing address' => $organisation->billing_address,
        'Location' => collect([$organisation->city, $organisation->state, $organisation->postal_code, $organisation->country])->filter()->implode(', '),
    ];

    $transitions = [
        OrganisationStatus::Active->value => ['Activate', 'Managers regain full access and billing continues.', false],
        OrganisationStatus::Suspended->value => ['Suspend', 'Managers keep read-only access plus invoices and payments. Billing continues. No Google accounts are changed.', true],
        OrganisationStatus::Inactive->value => ['Deactivate', 'Managers lose access to this organisation. No Google accounts, invoices or payments are deleted.', true],
    ];
@endphp
<x-layouts.app :title="$organisation->name" :description="'Organisation code '.$organisation->code"
               :breadcrumbs="[['label' => 'Organisations', 'url' => route('organisations.index')], ['label' => $organisation->name]]">
    <x-slot:actions>
        <x-ui.badge :tone="$organisation->status->tone()">{{ $organisation->status->label() }}</x-ui.badge>
        @if ($canEdit)
            <x-ui.button variant="secondary" icon="pencil" :href="route('organisations.edit', $organisation)">Edit</x-ui.button>
        @endif
        @if ($canChangeStatus)
            <x-ui.dropdown>
                <x-slot:trigger>
                    <x-ui.button variant="secondary">Status <x-ui.icon name="chevron-down" class="size-3.5" /></x-ui.button>
                </x-slot:trigger>
                @foreach ($transitions as $value => [$label, $explanation, $danger])
                    @if ($organisation->status->value !== $value)
                        <x-ui.dropdown.item :danger="$danger" @click="$dispatch('open-modal', 'status-{{ $value }}')">{{ $label }}…</x-ui.dropdown.item>
                    @endif
                @endforeach
            </x-ui.dropdown>
        @endif
    </x-slot:actions>

    @if ($organisation->status !== OrganisationStatus::Active)
        <x-ui.alert tone="warning" class="mb-6">
            This organisation is {{ strtolower($organisation->status->label()) }}.
            @if ($organisation->status === OrganisationStatus::Suspended) Its managers have read-only access. @endif
        </x-ui.alert>
    @endif

    <div @class(['grid grid-cols-1 gap-4 sm:grid-cols-2', 'lg:grid-cols-4' => $billableAccounts !== null, 'lg:grid-cols-3' => $billableAccounts === null])>
        @if ($billableAccounts !== null)
            <x-ui.stat-card label="Billable email accounts" :value="number_format($billableAccounts)" icon="mail" />
        @endif
        <x-ui.stat-card label="Users with access" :value="$members->pluck('user_id')->unique()->count()" icon="users" />
        <x-ui.stat-card label="Domains" :value="$organisation->domains->count()" icon="globe" />
        <x-ui.stat-card label="Contacts" :value="$organisation->contacts->count()" icon="user" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <x-ui.card title="Details" class="xl:col-span-1">
            <dl class="space-y-3 text-sm">
                @foreach ($details as $label => $value)
                    <div>
                        <dt class="muted text-xs">{{ $label }}</dt>
                        <dd class="whitespace-pre-line break-words">{{ filled($value) ? $value : '—' }}</dd>
                    </div>
                @endforeach
                @if ($canEdit && filled($organisation->notes))
                    <div>
                        <dt class="muted text-xs">Internal notes</dt>
                        <dd class="whitespace-pre-line break-words">{{ $organisation->notes }}</dd>
                    </div>
                @endif
            </dl>
        </x-ui.card>

        <div class="space-y-6 xl:col-span-2">
            {{-- Members --}}
            <x-ui.card title="Managers and access" id="members" :padding="false"
                       description="People who can sign in and act for this organisation.">
                @if ($canManageMembers)
                    <x-slot:actions>
                        <x-ui.button size="sm" icon="user-plus" @click="$dispatch('open-modal', 'add-member')">Grant access</x-ui.button>
                    </x-slot:actions>
                @endif

                @if ($members->isEmpty())
                    <x-ui.empty-state title="Nobody has access yet" icon="users"
                                      description="Grant a manager access so the organisation can manage its own accounts." />
                @else
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($members as $member)
                                    <tr>
                                        <td>
                                            <div class="font-medium">{{ $member->user->name }}</div>
                                            <div class="muted text-xs">{{ $member->user->email }}</div>
                                        </td>
                                        <td>{{ $member->role->label }}</td>
                                        <td>
                                            <x-ui.badge :tone="$member->user->is_active ? 'success' : 'neutral'">
                                                {{ $member->user->is_active ? 'Active' : 'Deactivated' }}
                                            </x-ui.badge>
                                        </td>
                                        <td class="muted whitespace-nowrap">{{ $member->user->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                                        <td class="text-right">
                                            @if ($canManageMembers)
                                                <x-ui.button variant="ghost" size="sm" @click="$dispatch('open-modal', 'remove-member-{{ $loop->index }}')">Remove</x-ui.button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>

            {{-- Domains --}}
            @if ($canViewDomains)
                <x-ui.card title="Domains" id="domains" :padding="false"
                           description="Email accounts are matched to this organisation by these domains.">
                    @if ($canMapDomains)
                        <x-slot:actions>
                            <x-ui.button size="sm" icon="plus" @click="$dispatch('open-modal', 'map-domain')">Map domain</x-ui.button>
                        </x-slot:actions>
                    @endif

                    @if ($organisation->domains->isEmpty())
                        <x-ui.empty-state title="No domains mapped" icon="globe"
                                          description="Domains already in Google Workspace are mapped here by a Super Admin." />
                    @else
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead><tr><th>Domain</th>@if ($billableAccounts !== null)<th>Billable accounts</th>@endif<th>Type</th><th>Verification</th><th>Added</th></tr></thead>
                                <tbody>
                                    @foreach ($organisation->domains as $domain)
                                        <tr>
                                            <td><a href="{{ route('domains.show', $domain) }}" class="font-medium hover:underline">{{ $domain->domain_name }}</a></td>
                                            @if ($billableAccounts !== null)
                                                <td>{{ number_format($billableByDomain[$domain->id] ?? 0) }}</td>
                                            @endif
                                            <td>{{ $domain->domain_type->label() }}</td>
                                            <td>
                                                <x-ui.badge :tone="$domain->verification_status === 'verified' ? 'success' : 'neutral'">
                                                    {{ $domain->verification_status === 'unverified' ? 'Not checked with Google' : ucfirst($domain->verification_status) }}
                                                </x-ui.badge>
                                            </td>
                                            <td class="muted whitespace-nowrap">{{ $domain->created_at->inDisplayTimezone()->format('d M Y') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            @if ($licenses !== null && $licenses->isNotEmpty())
                <x-ui.card title="Software licenses" id="licenses" :padding="false">
                    <table class="data-table">
                        <thead><tr><th>License</th><th>Product / plan</th><th>Status</th><th>Expires</th></tr></thead>
                        <tbody>
                            @foreach ($licenses as $license)
                                <tr>
                                    <td><a href="{{ route('licensing.show', $license) }}" class="font-medium hover:underline">{{ $license->number }}</a></td>
                                    <td>{{ $license->product?->name }} · {{ $license->plan?->name }}</td>
                                    <td><x-ui.badge :tone="$license->statusTone()">{{ ucfirst($license->displayStatus()) }}</x-ui.badge></td>
                                    <td class="muted">{{ $license->expires_at?->inDisplayTimezone()->format('d M Y') ?? 'Lifetime' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.card>
            @endif

            {{-- Contacts --}}
            <x-ui.card title="Contacts" id="contacts" :padding="false">
                @if ($contactTypes)
                    <x-slot:actions>
                        <x-ui.button size="sm" icon="plus" @click="$dispatch('open-modal', 'add-contact')">Add contact</x-ui.button>
                    </x-slot:actions>
                @endif

                @if ($organisation->contacts->isEmpty())
                    <x-ui.empty-state title="No contacts" icon="user" description="Add the people to reach for general, billing or technical matters." />
                @else
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead><tr><th>Name</th><th>Type</th><th>Email</th><th>Phone</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($organisation->contacts as $contact)
                                    <tr>
                                        <td>
                                            <div class="font-medium">{{ $contact->name }}</div>
                                            @if ($contact->designation)<div class="muted text-xs">{{ $contact->designation }}</div>@endif
                                        </td>
                                        <td>{{ $contact->type->label() }}</td>
                                        <td>{{ $contact->email ?? '—' }}</td>
                                        <td class="whitespace-nowrap">{{ $contact->phone ?? '—' }}</td>
                                        <td class="text-right">
                                            @if (isset($contactTypes[$contact->type->value]))
                                                <x-ui.button variant="ghost" size="sm" @click="$dispatch('open-modal', 'remove-contact-{{ $loop->index }}')">Remove</x-ui.button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>
        </div>
    </div>

    {{-- Modals --}}
    @if ($canChangeStatus)
        @foreach ($transitions as $value => [$label, $explanation, $danger])
            @if ($organisation->status->value !== $value)
                <x-ui.confirm-modal name="status-{{ $value }}" :title="$label.' '.$organisation->name.'?'"
                                    :action="route('organisations.status', $organisation)"
                                    :confirm-label="$label" :danger="$danger" require-reason>
                    <input type="hidden" name="status" value="{{ $value }}">
                    {{ $explanation }}
                </x-ui.confirm-modal>
            @endif
        @endforeach
    @endif

    @if ($canManageMembers)
        <x-ui.modal name="add-member" title="Grant access">
            <form method="POST" action="{{ route('organisations.members.store', $organisation) }}" class="space-y-4">
                @csrf
                <x-ui.form.field label="Email" for="member_email" name="email" required>
                    <x-ui.form.input name="email" id="member_email" type="email" required maxlength="255" />
                </x-ui.form.field>
                <x-ui.form.field label="Name" for="member_name" name="name"
                                 hint="Only needed when this person does not have a dashboard account yet. They are e-mailed a link to set their password.">
                    <x-ui.form.input name="name" id="member_name" maxlength="255" />
                </x-ui.form.field>
                <x-ui.form.field label="Role" for="member_role" name="role" required>
                    <x-ui.form.select name="role" id="member_role" :options="$assignableRoles" required />
                </x-ui.form.field>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'add-member')">Cancel</x-ui.button>
                    <x-ui.button type="submit">Grant access</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        @foreach ($members as $member)
            <x-ui.confirm-modal name="remove-member-{{ $loop->index }}" title="Remove access?" method="DELETE"
                                :action="route('organisations.members.destroy', [$organisation, $member->user])"
                                confirm-label="Remove access" danger>
                <input type="hidden" name="role" value="{{ $member->role->name }}">
                {{ $member->user->name }} will no longer act as {{ $member->role->label }} for {{ $organisation->name }}.
                Their dashboard account is not deleted.
            </x-ui.confirm-modal>
        @endforeach
    @endif

    @if ($canMapDomains)
        <x-ui.modal name="map-domain" title="Map an existing domain">
            <form method="POST" action="{{ route('organisations.domains.store', $organisation) }}" class="space-y-4">
                @csrf
                <p class="muted text-sm">
                    Records that a domain already in Google Workspace belongs to this organisation. Nothing is changed
                    in Google. New domains go through the domain request workflow.
                </p>
                <x-ui.form.field label="Domain name" for="domain_name" bag="domain" required>
                    <x-ui.form.input name="domain_name" bag="domain" placeholder="example.com" required maxlength="253" class="lowercase" />
                </x-ui.form.field>
                <x-ui.form.field label="Type" for="domain_type" bag="domain" required
                                 hint="Secondary domains have their own mailboxes. An alias only adds extra addresses to another domain.">
                    <x-ui.form.select name="domain_type" bag="domain" value="secondary" :options="\App\Modules\Domains\Enums\DomainType::options()" required />
                </x-ui.form.field>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'map-domain')">Cancel</x-ui.button>
                    <x-ui.button type="submit">Map domain</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($contactTypes)
        <x-ui.modal name="add-contact" title="Add contact">
            <form method="POST" action="{{ route('organisations.contacts.store', $organisation) }}" class="space-y-4">
                @csrf
                <x-ui.form.field label="Type" for="contact_type" name="type" bag="contact" required>
                    <x-ui.form.select name="type" id="contact_type" bag="contact" :options="$contactTypes" required />
                </x-ui.form.field>
                <x-ui.form.field label="Name" for="contact_name" name="name" bag="contact" required>
                    <x-ui.form.input name="name" id="contact_name" bag="contact" required maxlength="255" />
                </x-ui.form.field>
                <x-ui.form.field label="Designation" for="contact_designation" name="designation" bag="contact">
                    <x-ui.form.input name="designation" id="contact_designation" bag="contact" maxlength="100" />
                </x-ui.form.field>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.form.field label="Email" for="contact_email" name="email" bag="contact" hint="Email or phone is required.">
                        <x-ui.form.input name="email" id="contact_email" type="email" bag="contact" maxlength="255" />
                    </x-ui.form.field>
                    <x-ui.form.field label="Phone" for="contact_phone_field" name="phone" bag="contact">
                        <x-ui.form.input name="phone" id="contact_phone_field" type="tel" bag="contact" maxlength="32" />
                    </x-ui.form.field>
                </div>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'add-contact')">Cancel</x-ui.button>
                    <x-ui.button type="submit">Add contact</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        @foreach ($organisation->contacts as $contact)
            @if (isset($contactTypes[$contact->type->value]))
                <x-ui.confirm-modal name="remove-contact-{{ $loop->index }}" title="Remove contact?" method="DELETE"
                                    :action="route('organisations.contacts.destroy', [$organisation, $contact])"
                                    confirm-label="Remove" danger>
                    {{ $contact->name }} will be removed from {{ $organisation->name }}'s contacts.
                </x-ui.confirm-modal>
            @endif
        @endforeach
    @endif

    {{-- Reopen the relevant dialog when its form failed validation. --}}
    @php
        $reopen = match (true) {
            $errors->hasBag('domain') && $errors->getBag('domain')->any() => 'map-domain',
            $errors->hasBag('contact') && $errors->getBag('contact')->any() => 'add-contact',
            $errors->any() && old('role') !== null => 'add-member',
            default => null,
        };
    @endphp
    @if ($reopen)
        <div x-data x-init="$nextTick(() => $dispatch('open-modal', @js($reopen)))"></div>
    @endif
</x-layouts.app>
