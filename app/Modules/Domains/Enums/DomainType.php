<?php

namespace App\Modules\Domains\Enums;

enum DomainType: string
{
    case Primary = 'primary';
    /** An additional domain with its own independent mailboxes. */
    case Secondary = 'secondary';
    /** A domain alias of another domain — not a substitute for a secondary domain. */
    case Alias = 'alias';

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
