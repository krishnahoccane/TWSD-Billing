<?php

namespace App\Modules\GoogleWorkspace\Data;

final readonly class WorkspaceOrgUnit
{
    public function __construct(
        public string $id,
        public string $name,
        public string $path,
        public ?string $parentPath = null,
    ) {}
}
