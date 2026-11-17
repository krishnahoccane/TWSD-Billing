<?php

namespace App\Modules\AccessControl\Enums;

/**
 * Where a role may be assigned.
 *
 * Platform     -> user_roles only.
 * Organisation -> organisation_user_roles only.
 * Both         -> either table.
 */
enum RoleScope: string
{
    case Platform = 'platform';
    case Organisation = 'organisation';
    case Both = 'both';

    public function allowsPlatformAssignment(): bool
    {
        return $this !== self::Organisation;
    }

    public function allowsOrganisationAssignment(): bool
    {
        return $this !== self::Platform;
    }
}
