<?php

namespace App\Modules\GoogleWorkspace\Providers;

use App\Modules\GoogleWorkspace\Contracts\WorkspaceProviderInterface;
use App\Modules\GoogleWorkspace\Data\NewWorkspaceUser;
use App\Modules\GoogleWorkspace\Data\WorkspaceDomain;
use App\Modules\GoogleWorkspace\Data\WorkspaceOrgUnit;
use App\Modules\GoogleWorkspace\Data\WorkspaceUser;
use App\Modules\GoogleWorkspace\Data\WorkspaceUserPage;
use App\Modules\GoogleWorkspace\Models\GoogleApiLog;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;

/**
 * Decorator: records every provider call (operation, target, outcome,
 * duration) in google_api_logs. Arguments such as passwords are never
 * logged — only the operation name and the user/domain it targeted.
 * A logging failure never masks the provider result.
 */
class LoggingWorkspaceProvider implements WorkspaceProviderInterface
{
    public function __construct(private readonly WorkspaceProviderInterface $inner) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function inner(): WorkspaceProviderInterface
    {
        return $this->inner;
    }

    public function listUsers(?string $pageToken = null, int $pageSize = 500): WorkspaceUserPage
    {
        return $this->log('users.list', null, fn () => $this->inner->listUsers($pageToken, $pageSize));
    }

    public function getUser(string $userKey): ?WorkspaceUser
    {
        return $this->log('users.get', $userKey, fn () => $this->inner->getUser($userKey));
    }

    public function createUser(NewWorkspaceUser $user): WorkspaceUser
    {
        return $this->log('users.insert', $user->primaryEmail, fn () => $this->inner->createUser($user));
    }

    public function updateUser(string $userKey, array $attributes): WorkspaceUser
    {
        return $this->log('users.patch', $userKey, fn () => $this->inner->updateUser($userKey, $attributes));
    }

    public function suspendUser(string $userKey): WorkspaceUser
    {
        return $this->log('users.suspend', $userKey, fn () => $this->inner->suspendUser($userKey));
    }

    public function reactivateUser(string $userKey): WorkspaceUser
    {
        return $this->log('users.reactivate', $userKey, fn () => $this->inner->reactivateUser($userKey));
    }

    public function signOutUser(string $userKey): void
    {
        $this->log('users.signOut', $userKey, fn () => $this->inner->signOutUser($userKey));
    }

    public function deleteUser(string $userKey): void
    {
        $this->log('users.delete', $userKey, fn () => $this->inner->deleteUser($userKey));
    }

    public function restoreUser(string $googleUserId, string $orgUnitPath = '/'): void
    {
        $this->log('users.undelete', $googleUserId, fn () => $this->inner->restoreUser($googleUserId, $orgUnitPath));
    }

    public function listDomains(): array
    {
        return $this->log('domains.list', null, fn () => $this->inner->listDomains());
    }

    public function getDomain(string $domainName): ?WorkspaceDomain
    {
        return $this->log('domains.get', $domainName, fn () => $this->inner->getDomain($domainName));
    }

    public function addDomain(string $domainName): WorkspaceDomain
    {
        return $this->log('domains.insert', $domainName, fn () => $this->inner->addDomain($domainName));
    }

    public function removeDomain(string $domainName): void
    {
        $this->log('domains.delete', $domainName, fn () => $this->inner->removeDomain($domainName));
    }

    public function listOrgUnits(): array
    {
        return $this->log('orgunits.list', null, fn () => $this->inner->listOrgUnits());
    }

    public function createOrgUnit(string $name, string $parentPath = '/'): WorkspaceOrgUnit
    {
        return $this->log('orgunits.insert', $name, fn () => $this->inner->createOrgUnit($name, $parentPath));
    }

    private function log(string $operation, ?string $target, Closure $call): mixed
    {
        $started = hrtime(true);

        try {
            $result = $call();
            $this->write($operation, $target, $started);

            return $result;
        } catch (Throwable $e) {
            $this->write($operation, $target, $started, $e);

            throw $e;
        }
    }

    private function write(string $operation, ?string $target, int $started, ?Throwable $error = null): void
    {
        try {
            GoogleApiLog::query()->create([
                'provider' => $this->inner->name(),
                'operation' => $operation,
                'target' => $target === null ? null : Str::limit($target, 250, ''),
                'status' => $error === null ? 'success' : 'failure',
                'error_type' => $error === null ? null : class_basename($error),
                'error_message' => $error === null ? null : Str::limit($error->getMessage(), 2000),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'request_id' => Context::get('request_id'),
            ]);
        } catch (Throwable $loggingFailure) {
            report($loggingFailure);
        }
    }
}
