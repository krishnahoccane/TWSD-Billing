<?php

namespace App\Modules\Organisations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organisations\Enums\ContactType;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Models\OrganisationContact;
use App\Modules\Organisations\Services\OrganisationContactService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrganisationContactController extends Controller
{
    public function store(Request $request, Organisation $organisation, OrganisationContactService $contacts): RedirectResponse
    {
        $data = $request->validateWithBag('contact', [
            'type' => ['required', Rule::enum(ContactType::class)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s]+$/'],
            'designation' => ['nullable', 'string', 'max:100'],
        ]);

        // Authorised against the concrete type: a Billing Manager may add
        // billing contacts but not general or technical ones.
        Gate::authorize('manageContacts', [$organisation, ContactType::from($data['type'])]);

        $contacts->create($organisation, $data, $request->user());

        return redirect()->to(route('organisations.show', $organisation).'#contacts')->with('success', 'Contact added.');
    }

    public function destroy(Request $request, Organisation $organisation, OrganisationContact $contact, OrganisationContactService $contacts): RedirectResponse
    {
        Gate::authorize('manageContacts', [$organisation, $contact->type]);

        $contacts->delete($contact, $request->user());

        return redirect()->to(route('organisations.show', $organisation).'#contacts')->with('success', 'Contact removed.');
    }
}
