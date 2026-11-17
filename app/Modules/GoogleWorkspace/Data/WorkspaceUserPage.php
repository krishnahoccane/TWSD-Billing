<?php

namespace App\Modules\GoogleWorkspace\Data;

final readonly class WorkspaceUserPage
{
    /**
     * @param  list<WorkspaceUser>  $users
     */
    public function __construct(
        public array $users,
        public ?string $nextPageToken = null,
    ) {}
}
