<?php

namespace App\Modules\GoogleWorkspace\Data;

final readonly class WorkspaceDomain
{
    public function __construct(
        public string $name,
        public bool $isPrimary = false,
        public bool $verified = false,
    ) {}
}
