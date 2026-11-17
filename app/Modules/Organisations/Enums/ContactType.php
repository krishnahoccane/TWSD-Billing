<?php

namespace App\Modules\Organisations\Enums;

enum ContactType: string
{
    case General = 'general';
    case Billing = 'billing';
    case Technical = 'technical';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type) => [$type->value => $type->label()])->all();
    }
}
