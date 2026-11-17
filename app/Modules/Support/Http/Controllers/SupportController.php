<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Notifications\NotificationEvents;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Services\OrganisationContext;
use App\Modules\Support\Models\SupportRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Organisation users raise requests (support.create, in their own
 * organisations); Super Admins answer them (support.manage).
 */
class SupportController extends Controller
{
    public function index(Request $request, OrganisationContext $context): View
    {
        $user = $request->user();
        abort_unless($user->hasAbilityAnywhere(Ability::SupportCreate) || $user->hasAbilityAnywhere(Ability::SupportManage), 403);

        $status = (string) $request->query('status', '');
        $current = $context->current($user);

        $requests = $this->visible($request)
            ->with(['organisation:id,uuid,name', 'user:id,name'])
            ->when($current, fn (Builder $query) => $query->where('organisation_id', $current->getKey()))
            ->when(array_key_exists($status, SupportRequest::STATUSES), fn (Builder $query) => $query->where('status', $status))
            ->latest('id')->paginate(25)->withQueryString();

        $organisations = Organisation::query()->visibleTo($user, Ability::SupportCreate)->orderBy('name')->pluck('name', 'uuid')->all();

        return view('support.index', [
            'requests' => $requests,
            'status' => $status,
            'organisations' => $organisations,
            'selected' => $current?->uuid ?? array_key_first($organisations),
            'showOrganisation' => $current === null,
        ]);
    }

    public function store(Request $request, AuditLogger $audit, NotificationEvents $notify): RedirectResponse
    {
        $organisation = Organisation::query()->visibleTo($request->user(), Ability::SupportCreate)
            ->where('uuid', (string) $request->input('organisation'))->firstOrFail();

        $data = $request->validateWithBag('support', [
            'organisation' => ['required', 'uuid'],
            'category' => ['required', Rule::in(array_keys(SupportRequest::CATEGORIES))],
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $support = SupportRequest::query()->create([
            'organisation_id' => $organisation->getKey(),
            'user_id' => $request->user()->getKey(),
            'category' => $data['category'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'status' => 'open',
        ]);

        $audit->record('support.created', $support, new: ['subject' => $data['subject'], 'category' => $data['category']],
            organisation: $organisation, actor: $request->user());
        $notify->supportCreated($support);

        return redirect()->route('support.show', $support)->with('success', 'Your request has been sent. You will be notified when it is answered.');
    }

    public function show(Request $request, string $support): View
    {
        $item = $this->visible($request)->with(['organisation:id,uuid,name', 'user:id,name,email', 'responder:id,name'])
            ->where('uuid', $support)->firstOrFail();

        return view('support.show', [
            'item' => $item,
            'canManage' => $request->user()->hasAbility(Ability::SupportManage, $item->organisation),
        ]);
    }

    public function update(Request $request, string $support, AuditLogger $audit, NotificationEvents $notify): RedirectResponse
    {
        $item = $this->visible($request)->where('uuid', $support)->firstOrFail();
        abort_unless($request->user()->hasAbility(Ability::SupportManage, $item->organisation), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(SupportRequest::STATUSES))],
            'response' => ['nullable', 'string', 'max:5000'],
        ]);

        $previous = $item->only(['status']);

        $item->forceFill([
            'status' => $data['status'],
            'response' => filled($data['response'] ?? null) ? $data['response'] : $item->response,
            'responded_by' => $request->user()->getKey(),
            'responded_at' => now(),
        ])->save();

        $audit->record('support.updated', $item, previous: $previous, new: ['status' => $data['status']],
            organisation: $item->organisation, actor: $request->user());
        $notify->supportUpdated($item);

        return back()->with('success', 'Support request updated.');
    }

    /** Requests the user may see: their organisations', or all for support managers. */
    private function visible(Request $request): Builder
    {
        $user = $request->user();
        $manage = $user->organisationScope(Ability::SupportManage);
        $create = $user->organisationScope(Ability::SupportCreate);

        return SupportRequest::query()->when(! $manage->all,
            // An empty list matches nothing.
            fn (Builder $query) => $query->whereIn('organisation_id', array_values(array_unique([...$manage->ids, ...$create->ids]))));
    }
}
