<?php

namespace App\Modules\AccessControl\Models;

use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\AccessControl\Enums\RoleScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['name', 'label', 'description', 'scope', 'is_system'];

    protected function casts(): array
    {
        return [
            'scope' => RoleScope::class,
            'is_system' => 'boolean',
        ];
    }

    /** Roles are addressed by name in URLs, never by numeric id. */
    public function getRouteKeyName(): string
    {
        return 'name';
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->withTimestamps();
    }

    public static function findByName(RoleName|string $name): self
    {
        return static::query()
            ->where('name', $name instanceof RoleName ? $name->value : $name)
            ->firstOrFail();
    }
}
