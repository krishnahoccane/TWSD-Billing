<?php

namespace App\Modules\WhatsApp\Services;

use App\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\WhatsApp\Jobs\SendWhatsAppMessageJob;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends an organisation's notifications to WhatsApp: the organisation's
 * contact phone and the phones of its own users (organisation managers and
 * the Operations Admins assigned to it). Platform-only staff and other
 * organisations' users are never included. A user can turn WhatsApp off
 * per event under Account -> Notifications.
 */
class WhatsAppNotifier
{
    public function __construct(private readonly WhatsAppConnection $connection) {}

    /**
     * @return int messages queued
     */
    public function notify(NotificationType $type, string $title, string $body, ?string $url, ?Organisation $organisation): int
    {
        if ($organisation === null || ! $type->whatsAppEligible()) {
            return 0;
        }

        try {
            if (! $this->connection->sends($type)) {
                return 0;
            }

            $numbers = [];

            if ($number = PhoneNumber::normalise($organisation->contact_phone)) {
                $numbers[$number] = true;
            }

            // Everyone holding a role IN this organisation (organisation
            // managers, assigned Operations Admins, billing managers) —
            // resolved from the database, never from request input.
            $members = DB::table('organisation_user_roles')->where('organisation_id', $organisation->getKey())
                ->distinct()->pluck('user_id');

            foreach (User::query()->whereIn('id', $members)->where('is_active', true)->whereNotNull('phone')->get() as $user) {

                if (($user->notification_preferences[$type->value]['whatsapp'] ?? true) === false) {
                    continue;
                }

                if ($number = PhoneNumber::normalise($user->phone)) {
                    $numbers[$number] = true;
                }
            }

            $message = self::text($title, $body, $url, $organisation->name);

            foreach (array_keys($numbers) as $number) {
                SendWhatsAppMessageJob::dispatch((string) $number, $message)->afterCommit();
            }

            return count($numbers);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    public static function text(string $title, string $body, ?string $url, ?string $organisationName): string
    {
        return trim('*'.$title.'*'.($organisationName ? ' — '.$organisationName : '')."\n\n".$body
            .($url ? "\n\n".$url : '')."\n\n— ".config('app.name'));
    }
}
