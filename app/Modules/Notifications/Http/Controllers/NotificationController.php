<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A user's own notifications and e-mail preferences. Only the signed-in
 * user's notifications are ever loaded.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $unreadOnly = $request->boolean('unread');

        return view('notifications.index', [
            'notifications' => $request->user()->notifications()
                ->when($unreadOnly, fn ($query) => $query->whereNull('read_at'))
                ->paginate(30)->withQueryString(),
            'unreadOnly' => $unreadOnly,
            'unreadCount' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** Mark read and go to the linked page — the link comes from our own stored data. */
    public function open(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        $url = $notification->data['url'] ?? null;

        // Only follow links into this application.
        return is_string($url) && str_starts_with($url, url('/'))
            ? redirect()->to($url)
            : redirect()->route('notifications.index');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }

    public function preferences(Request $request, Notifier $notifier): View
    {
        return view('notifications.preferences', [
            'types' => $this->relevantTypes($request, $notifier),
            'preferences' => $request->user()->notification_preferences ?? [],
        ]);
    }

    public function savePreferences(Request $request, Notifier $notifier, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $chosen = (array) $request->input('mail', []);
        $preferences = [];

        foreach ($this->relevantTypes($request, $notifier) as $type) {
            $preferences[$type->value] = ['mail' => in_array($type->value, $chosen, true)];
        }

        $user->forceFill(['notification_preferences' => $preferences])->save();
        $audit->record('user.notification_preferences_saved', $user, actor: $user);

        return back()->with('success', 'Notification preferences saved.');
    }

    /**
     * Only the notifications this user can actually receive.
     *
     * @return list<NotificationType>
     */
    private function relevantTypes(Request $request, Notifier $notifier): array
    {
        $user = $request->user();

        return array_values(array_filter(NotificationType::cases(), function (NotificationType $type) use ($user) {
            return ($type->organisationAbility() && $user->hasAbilityAnywhere($type->organisationAbility()))
                || ($type->platformAbility() && $user->hasAbilityAnywhere($type->platformAbility()))
                || in_array($type, [NotificationType::DomainRequestApproved, NotificationType::DomainRequestRejected, NotificationType::SupportRequestUpdated], true);
        }));
    }
}
