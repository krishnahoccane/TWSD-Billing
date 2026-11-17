<?php

namespace App\Modules\Notifications;

use App\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification class for every event. Queued on "notifications".
 * The content is plain text with a link back into the application — it
 * never contains passwords, tokens or another organisation's data.
 */
class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public ?string $url = null,
        public ?string $organisationName = null,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        $channels = ['database'];
        $type = NotificationType::from($this->type);
        $preference = $notifiable->notification_preferences[$this->type]['mail'] ?? $type->mailByDefault();

        if ($preference && $notifiable->is_active) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(User $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title.($this->organisationName ? ' — '.$this->organisationName : ''))
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->body);

        if ($this->url !== null) {
            $message->action('Open in Workspace Control Center', $this->url);
        }

        return $message->line('You can choose which e-mails you receive under Account → Notifications.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'organisation' => $this->organisationName,
            'level' => NotificationType::from($this->type)->level(),
        ];
    }
}
