<?php

namespace App\Modules\GoogleWorkspace\Data;

/**
 * A domain in the Workspace customer. Domain aliases (Directory API
 * domains.domainAliases[]) are returned as entries with $aliasOf set to the
 * parent domain; they are never primary.
 */
final readonly class WorkspaceDomain
{
    public function __construct(
        public string $name,
        public bool $isPrimary = false,
        public bool $verified = false,
        public ?string $aliasOf = null,
    ) {}
}
