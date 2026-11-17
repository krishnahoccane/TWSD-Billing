<?php

namespace App\Modules\Organisations\Enums;

enum OrganisationStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Inactive = 'inactive';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Badge tone used by <x-ui.badge>. */
    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Inactive => 'neutral',
        };
    }
}
