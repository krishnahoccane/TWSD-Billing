<?php

namespace App\Modules\WhatsApp\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Integrations\Models\IntegrationSetting;
use App\Modules\Notifications\Enums\NotificationType;
use SensitiveParameter;

/**
 * Settings of the WhatsApp notifier integration. The app key and auth key
 * are stored only in integration_settings.secrets (encrypted with APP_KEY);
 * they are never returned to a page, logged or audited — screens only
 * learn whether they are set.
 */
class WhatsAppConnection
{
    public const PROVIDER = 'whatsapp';

    public function __construct(private readonly AuditLogger $audit) {}

    public function settingRow(): IntegrationSetting
    {
        return IntegrationSetting::query()->firstOrCreate(['provider' => self::PROVIDER], ['settings' => []]);
    }

    /** @return array{enabled: bool, events: list<string>} */
    public function settings(): array
    {
        $settings = $this->settingRow()->settings ?? [];

        return [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'events' => array_values(array_intersect($settings['events'] ?? self::defaultEvents(), self::defaultEvents())),
        ];
    }

    public function hasKeys(): bool
    {
        return $this->keys() !== null;
    }

    /** @return array{appkey: string, authkey: string}|null */
    public function keys(): ?array
    {
        $secrets = $this->settingRow()->secrets ?? [];

        return filled($secrets['appkey'] ?? null) && filled($secrets['authkey'] ?? null)
            ? ['appkey' => (string) $secrets['appkey'], 'authkey' => (string) $secrets['authkey']]
            : null;
    }

    /** Switched on, keys saved, and this event selected. */
    public function sends(NotificationType $type): bool
    {
        $settings = $this->settings();

        return $settings['enabled'] && $this->hasKeys() && in_array($type->value, $settings['events'], true);
    }

    /**
     * @param  list<string>  $events
     */
    public function saveSettings(bool $enabled, array $events, User $actor): void
    {
        $row = $this->settingRow();
        $previous = $this->settings();
        $events = array_values(array_intersect($events, self::defaultEvents()));

        $row->settings = ['enabled' => $enabled, 'events' => $events];
        $row->updated_by = $actor->getKey();
        $row->save();

        $this->audit->record('integration.whatsapp.settings_saved', $row, previous: $previous,
            new: ['enabled' => $enabled, 'events' => $events], actor: $actor);
    }

    /** A blank value keeps the stored key. Returns false when nothing changed. */
    public function saveKeys(#[SensitiveParameter] ?string $appKey, #[SensitiveParameter] ?string $authKey, User $actor): bool
    {
        $row = $this->settingRow();
        $secrets = $row->secrets ?? [];
        $changed = [];

        foreach (['appkey' => $appKey, 'authkey' => $authKey] as $name => $value) {
            if (filled($value)) {
                $secrets[$name] = trim((string) $value);
                $changed[] = $name;
            }
        }

        if ($changed === []) {
            return false;
        }

        $row->secrets = $secrets;
        $row->updated_by = $actor->getKey();
        $row->save();

        // Which keys changed — never their values.
        $this->audit->record('integration.whatsapp.keys_changed', $row, new: ['changed' => $changed], actor: $actor);

        return true;
    }

    public function clearKeys(User $actor): void
    {
        $row = $this->settingRow();
        $row->secrets = null;
        $row->updated_by = $actor->getKey();
        $row->save();

        $this->audit->record('integration.whatsapp.keys_removed', $row, actor: $actor);
    }

    public function markTested(): void
    {
        $this->settingRow()->forceFill(['last_tested_at' => now()])->save();
    }

    /** @return list<string> notification types that may go to WhatsApp */
    public static function defaultEvents(): array
    {
        return array_values(array_map(fn (NotificationType $type) => $type->value,
            array_filter(NotificationType::cases(), fn (NotificationType $type) => $type->whatsAppEligible())));
    }
}
