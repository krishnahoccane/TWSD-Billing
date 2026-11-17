<?php

namespace App\Modules\GoogleWorkspace\Contracts;

use App\Modules\GoogleWorkspace\Data\NewWorkspaceUser;
use App\Modules\GoogleWorkspace\Data\WorkspaceDomain;
use App\Modules\GoogleWorkspace\Data\WorkspaceOrgUnit;
use App\Modules\GoogleWorkspace\Data\WorkspaceUser;
use App\Modules\GoogleWorkspace\Data\WorkspaceUserPage;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;

/**
 * Everything the application needs from a Workspace directory.
 *
 * Implementations: GoogleWorkspaceProvider (Admin SDK Directory API) and
 * MockWorkspaceProvider (local development and tests). Business logic
 * depends only on this interface and on the DTOs, never on API payloads.
 *
 * Every method may throw a WorkspaceException subclass:
 *   WorkspaceRateLimitException  retry after a delay
 *   WorkspaceTransientException  retry with backoff (timeouts, 5xx)
 *   WorkspaceNotFoundException / WorkspaceConflictException / WorkspaceException  do not retry
 *
 * @throws WorkspaceException
 */
interface WorkspaceProviderInterface
{
    /** Short identifier written to logs: "google" or "mock". */
    public function name(): string;

    /** One page of users; follow nextPageToken until it is null. */
    public function listUsers(?string $pageToken = null, int $pageSize = 500): WorkspaceUserPage;

    /** By immutable id or primary e-mail. Null when the user does not exist. */
    public function getUser(string $userKey): ?WorkspaceUser;

    public function createUser(NewWorkspaceUser $user): WorkspaceUser;

    /**
     * @param  array{first_name?: string, last_name?: string, display_name?: ?string, recovery_email?: ?string,
     *               recovery_phone?: ?string, org_unit_path?: string, password?: string,
     *               change_password_at_next_login?: bool}  $attributes
     */
    public function updateUser(string $userKey, array $attributes): WorkspaceUser;

    public function suspendUser(string $userKey): WorkspaceUser;

    public function reactivateUser(string $userKey): WorkspaceUser;

    /** Revokes the user's active web and device sessions. */
    public function signOutUser(string $userKey): void;

    /** Permanent in Google after the recovery window. */
    public function deleteUser(string $userKey): void;

    /** Requires the immutable user id; only possible inside Google's recovery window. */
    public function restoreUser(string $googleUserId, string $orgUnitPath = '/'): void;

    /** @return list<WorkspaceDomain> */
    public function listDomains(): array;

    public function getDomain(string $domainName): ?WorkspaceDomain;

    public function addDomain(string $domainName): WorkspaceDomain;

    public function removeDomain(string $domainName): void;

    /** @return list<WorkspaceOrgUnit> */
    public function listOrgUnits(): array;

    public function createOrgUnit(string $name, string $parentPath = '/'): WorkspaceOrgUnit;
}
