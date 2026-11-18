<?php

namespace App\Modules\Notifications;

use App\Models\User;
use App\Modules\AccessControl\Services\AccessResolver;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\WhatsApp\Services\WhatsAppNotifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Works out who should hear about an event and sends it.
 *
 * Recipients are resolved from role assignments in the event's OWN
 * organisation (never from request input), so one organisation's events
 * never reach another organisation's users. A notification problem never
 * interrupts the operation that triggered it.
 */
class Notifier
{
    public function __construct(private readonly AccessResolver $access) {}

    /**
     * @param  list<User>  $extra  specific people to include (for example the requester)
     * @param  string|null  $throttleKey  send at most once per hour per key (integration errors)
     */
    public function send(
        NotificationType $type,
        string $title,
        string $body,
        ?string $url = null,
        ?Organisation $organisation = null,
        array $extra = [],
        ?string $throttleKey = null,
        int $throttleSeconds = 3600,
    ): int {
        try {
            if ($throttleKey !== null && ! Cache::add('notify:'.$type->value.':'.$throttleKey, 1, $throttleSeconds)) {
                return 0;
            }

            // WhatsApp (organisation phone + the organisation's own users), when switched on for this event.
            app(WhatsAppNotifier::class)->notify($type, $title, $body, $url, $organisation);

            $recipients = $this->recipients($type, $organisation)->merge($extra)
                ->filter(fn ($user) => $user instanceof User && $user->is_active)
                ->unique('id')->values();

            if ($recipients->isEmpty()) {
                return 0;
            }

            Notification::send($recipients, new AppNotification($type->value, $title, $body, $url, $organisation?->name));

            return $recipients->count();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * @return Collection<int, User>
     */
    public function recipients(NotificationType $type, ?Organisation $organisation): Collection
    {
        $users = collect();

        if ($organisation !== null && ($ability = $type->organisationAbility()) !== null) {
            $ids = DB::table('organisation_user_roles')->where('organisation_id', $organisation->getKey())->distinct()->pluck('user_id');

            $users = $users->merge(User::query()->whereIn('id', $ids)->where('is_active', true)->get()
                ->filter(fn (User $user) => $this->access->can($user, $ability, $organisation)));
        }

        if (($ability = $type->platformAbility()) !== null) {
            $ids = DB::table('user_roles')->distinct()->pluck('user_id');

            $users = $users->merge(User::query()->whereIn('id', $ids)->where('is_active', true)->get()
                ->filter(fn (User $user) => match (true) {
                    // Technical, platform-wide abilities (integration logs, Zoho).
                    $ability->isPlatformOnly(), $organisation === null => $this->access->can($user, $ability),
                    // Otherwise platform staff hear about an organisation's event only if they can act in it.
                    default => $this->access->can($user, $ability, $organisation),
                }));
        }

        return $users->unique('id')->values();
    }
}
