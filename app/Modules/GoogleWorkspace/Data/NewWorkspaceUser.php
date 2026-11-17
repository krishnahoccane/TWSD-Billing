<?php

namespace App\Modules\GoogleWorkspace\Data;

use SensitiveParameter;

final readonly class NewWorkspaceUser
{
    public function __construct(
        public string $primaryEmail,
        public string $givenName,
        public string $familyName,
        #[SensitiveParameter] public string $password,
        public string $orgUnitPath = '/',
        public ?string $recoveryEmail = null,
        public ?string $recoveryPhone = null,
    ) {}
}
