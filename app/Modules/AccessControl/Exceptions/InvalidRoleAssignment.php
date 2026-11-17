<?php

namespace App\Modules\AccessControl\Exceptions;

use App\Modules\AccessControl\Models\Role;
use DomainException;

class InvalidRoleAssignment extends DomainException
{
    public static function notPlatformAssignable(Role $role): self
    {
        return new self("Role [{$role->name}] can only be assigned within an organisation.");
    }

    public static function notOrganisationAssignable(Role $role): self
    {
        return new self("Role [{$role->name}] can only be assigned at platform level.");
    }
}
