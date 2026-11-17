<?php

namespace App\Modules\Domains\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Domains\Enums\DomainType;
use App\Modules\Domains\Services\DomainMappingService;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DomainMappingController extends Controller
{
    public function store(Request $request, Organisation $organisation, DomainMappingService $domains): RedirectResponse
    {
        Gate::authorize('mapDomains', $organisation);

        $request->merge(['domain_name' => DomainMappingService::normalise((string) $request->input('domain_name'))]);

        $data = $request->validateWithBag('domain', [
            'domain_name' => [
                'required', 'string', 'max:253',
                'regex:/^(?=.{4,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
                Rule::unique('organisation_domains', 'domain_name'),
            ],
            'domain_type' => ['required', Rule::enum(DomainType::class)],
        ], [
            'domain_name.regex' => 'Enter a valid domain name, for example example.com.',
            'domain_name.unique' => 'This domain is already mapped to an organisation.',
        ]);

        $domains->map($organisation, $data['domain_name'], DomainType::from($data['domain_type']), $request->user());

        return redirect()->to(route('organisations.show', $organisation).'#domains')->with('success', 'Domain mapped.');
    }
}
