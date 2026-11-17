<?php

namespace App\Livewire;

use App\Modules\Organisations\Services\OrganisationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Header control for choosing the working organisation. Holds no
 * organisation data in public state; the submitted uuid is validated by
 * OrganisationContext against the user's assignments.
 */
class OrganisationSwitcher extends Component
{
    public function select(?string $uuid, OrganisationContext $context): void
    {
        try {
            $context->switchTo(Auth::user(), $uuid ?: null);
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->redirectRoute('dashboard');
    }

    public function render(OrganisationContext $context): View
    {
        $user = Auth::user();

        return view('livewire.organisation-switcher', [
            'organisations' => $context->available($user),
            'current' => $context->current($user),
            'allowAll' => $user->hasGlobalAccess(),
        ]);
    }
}
