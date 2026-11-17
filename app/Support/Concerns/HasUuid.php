<?php

namespace App\Support\Concerns;

use Illuminate\Support\Str;

/**
 * Numeric ids stay internal; the uuid is the only identifier exposed in
 * URLs and API payloads. Route-model binding resolves by uuid.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid7();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
