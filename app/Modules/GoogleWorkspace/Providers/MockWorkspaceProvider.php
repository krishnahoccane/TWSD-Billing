<?php

namespace App\Modules\GoogleWorkspace\Providers;

use App\Modules\GoogleWorkspace\Contracts\WorkspaceProviderInterface;
use App\Modules\GoogleWorkspace\Data\NewWorkspaceUser;
use App\Modules\GoogleWorkspace\Data\WorkspaceDomain;
use App\Modules\GoogleWorkspace\Data\WorkspaceOrgUnit;
use App\Modules\GoogleWorkspace\Data\WorkspaceUser;
use App\Modules\GoogleWorkspace\Data\WorkspaceUserPage;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceConflictException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceNotFoundException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A stand-in Workspace directory for local development and automated
 * tests. It makes NO network calls. It is not a Google integration and
 * nothing it does reaches a real mailbox.
 *
 * State lives in memory; when $statePath is given (local development) it
 * is also saved to that JSON file so it survives between requests.
 * Tests can inject failures with failNext() and inspect $calls.
 */
class MockWorkspaceProvider implements WorkspaceProviderInterface
{
    /** @var array<string, array<string, mixed>> keyed by user id */
    private array $users = [];

    /** @var array<string, array<string, mixed>> deleted users, keyed by user id */
    private array $deleted = [];

    /** @var array<string, array{isPrimary: bool, verified: bool}> keyed by domain name */
    private array $domains = [];

    /** @var array<string, array{id: string, name: string, parentPath: ?string}> keyed by path */
    private array $orgUnits = [];

    private int $sequence = 0;

    /** @var array<string, list<Throwable>> */
    private array $failures = [];

    /** @var list<array{operation: string, target: ?string}> */
    public array $calls = [];

    public function __construct(private readonly ?string $statePath = null)
    {
        $this->load();
    }

    public function name(): string
    {
        return 'mock';
    }

    // ---- Test and local-development helpers -------------------------------

    /** Make the next $times calls of $operation throw $exception. */
    public function failNext(string $operation, Throwable $exception, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failures[$operation][] = $exception;
        }
    }

    public function callCount(string $operation): int
    {
        return count(array_filter($this->calls, fn (array $call) => $call['operation'] === $operation));
    }

    public function seedDomain(string $name, bool $isPrimary = false, bool $verified = true, ?string $aliasOf = null): void
    {
        $this->domains[strtolower($name)] = ['isPrimary' => $isPrimary, 'verified' => $verified, 'aliasOf' => $aliasOf === null ? null : strtolower($aliasOf)];
        $this->save();
    }

    public function seedUser(string $email, string $givenName = 'Test', string $familyName = 'User', bool $suspended = false): WorkspaceUser
    {
        $user = $this->store([
            'id' => $this->nextId(),
            'primaryEmail' => strtolower($email),
            'givenName' => $givenName,
            'familyName' => $familyName,
            'suspended' => $suspended,
            'orgUnitPath' => '/',
            'createdAt' => CarbonImmutable::now()->toIso8601String(),
        ]);
        $this->save();

        return $user;
    }

    /**
     * Bulk-create fictional users spread evenly over the given domains.
     *
     * @param  list<string>  $domains
     */
    public function seedUsers(int $count, array $domains): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $domain = $domains[($i - 1) % count($domains)];
            $this->store([
                'id' => $this->nextId(),
                'primaryEmail' => sprintf('user%04d@%s', $i, strtolower($domain)),
                'givenName' => 'User',
                'familyName' => sprintf('%04d', $i),
                'suspended' => false,
                'orgUnitPath' => '/',
                'createdAt' => CarbonImmutable::now()->toIso8601String(),
            ]);
        }

        $this->save();
    }

    public function reset(): void
    {
        $this->users = $this->deleted = $this->domains = $this->orgUnits = $this->failures = $this->calls = [];
        $this->sequence = 0;
        $this->save();
    }

    // ---- WorkspaceProviderInterface ---------------------------------------

    public function listUsers(?string $pageToken = null, int $pageSize = 500): WorkspaceUserPage
    {
        $this->called('users.list', $pageToken);

        $pageSize = max(1, min(500, $pageSize));
        $offset = $pageToken === null ? 0 : (int) $pageToken;

        $all = array_values($this->users);
        usort($all, fn (array $a, array $b) => $a['primaryEmail'] <=> $b['primaryEmail']);

        $slice = array_slice($all, $offset, $pageSize);
        $next = $offset + $pageSize < count($all) ? (string) ($offset + $pageSize) : null;

        return new WorkspaceUserPage(array_map(WorkspaceUser::fromArray(...), $slice), $next);
    }

    public function getUser(string $userKey): ?WorkspaceUser
    {
        $this->called('users.get', $userKey);

        $id = $this->resolve($userKey);

        return $id === null ? null : WorkspaceUser::fromArray($this->users[$id]);
    }

    public function createUser(NewWorkspaceUser $user): WorkspaceUser
    {
        $this->called('users.insert', $user->primaryEmail);

        if ($this->resolve($user->primaryEmail) !== null) {
            throw new WorkspaceConflictException("Entity already exists: {$user->primaryEmail}");
        }

        $created = $this->store([
            'id' => $this->nextId(),
            'primaryEmail' => strtolower($user->primaryEmail),
            'givenName' => $user->givenName,
            'familyName' => $user->familyName,
            'suspended' => false,
            'orgUnitPath' => $user->orgUnitPath,
            'createdAt' => CarbonImmutable::now()->toIso8601String(),
            'recoveryEmail' => $user->recoveryEmail,
            'recoveryPhone' => $user->recoveryPhone,
            // The password itself is deliberately never kept.
        ]);
        $this->save();

        return $created;
    }

    public function updateUser(string $userKey, array $attributes): WorkspaceUser
    {
        $this->called('users.patch', $userKey);

        $id = $this->resolveOrFail($userKey);
        $map = [
            'first_name' => 'givenName', 'last_name' => 'familyName', 'display_name' => 'displayName',
            'recovery_email' => 'recoveryEmail', 'recovery_phone' => 'recoveryPhone', 'org_unit_path' => 'orgUnitPath',
        ];

        foreach ($map as $key => $field) {
            if (array_key_exists($key, $attributes)) {
                $this->users[$id][$field] = $attributes[$key];
            }
        }

        $this->save();

        return WorkspaceUser::fromArray($this->users[$id]);
    }

    public function suspendUser(string $userKey): WorkspaceUser
    {
        $this->called('users.suspend', $userKey);

        return $this->setSuspended($userKey, true);
    }

    public function reactivateUser(string $userKey): WorkspaceUser
    {
        $this->called('users.reactivate', $userKey);

        return $this->setSuspended($userKey, false);
    }

    public function signOutUser(string $userKey): void
    {
        $this->called('users.signOut', $userKey);
        $this->resolveOrFail($userKey);
    }

    public function deleteUser(string $userKey): void
    {
        $this->called('users.delete', $userKey);

        $id = $this->resolveOrFail($userKey);
        $this->deleted[$id] = $this->users[$id];
        unset($this->users[$id]);
        $this->save();
    }

    public function restoreUser(string $googleUserId, string $orgUnitPath = '/'): void
    {
        $this->called('users.undelete', $googleUserId);

        if (! isset($this->deleted[$googleUserId])) {
            throw new WorkspaceNotFoundException("Deleted user not found: {$googleUserId}");
        }

        $this->users[$googleUserId] = ['orgUnitPath' => $orgUnitPath] + $this->deleted[$googleUserId];
        unset($this->deleted[$googleUserId]);
        $this->save();
    }

    public function listDomains(): array
    {
        $this->called('domains.list');

        return array_map(
            fn (string $name) => new WorkspaceDomain($name, $this->domains[$name]['isPrimary'], $this->domains[$name]['verified'], $this->domains[$name]['aliasOf'] ?? null),
            array_keys($this->domains),
        );
    }

    public function getDomain(string $domainName): ?WorkspaceDomain
    {
        $this->called('domains.get', $domainName);

        $name = strtolower($domainName);

        return isset($this->domains[$name])
            ? new WorkspaceDomain($name, $this->domains[$name]['isPrimary'], $this->domains[$name]['verified'])
            : null;
    }

    public function addDomain(string $domainName): WorkspaceDomain
    {
        $this->called('domains.insert', $domainName);

        $name = strtolower($domainName);

        if (isset($this->domains[$name])) {
            throw new WorkspaceConflictException("Domain already exists: {$name}");
        }

        $this->domains[$name] = ['isPrimary' => false, 'verified' => false];
        $this->save();

        return new WorkspaceDomain($name);
    }

    public function removeDomain(string $domainName): void
    {
        $this->called('domains.delete', $domainName);

        $name = strtolower($domainName);

        if (! isset($this->domains[$name])) {
            throw new WorkspaceNotFoundException("Domain not found: {$name}");
        }

        unset($this->domains[$name]);
        $this->save();
    }

    public function listOrgUnits(): array
    {
        $this->called('orgunits.list');

        return array_map(
            fn (string $path) => new WorkspaceOrgUnit($this->orgUnits[$path]['id'], $this->orgUnits[$path]['name'], $path, $this->orgUnits[$path]['parentPath']),
            array_keys($this->orgUnits),
        );
    }

    public function createOrgUnit(string $name, string $parentPath = '/'): WorkspaceOrgUnit
    {
        $this->called('orgunits.insert', $name);

        $path = rtrim($parentPath, '/').'/'.$name;

        if (isset($this->orgUnits[$path])) {
            throw new WorkspaceConflictException("Organisational unit already exists: {$path}");
        }

        $this->orgUnits[$path] = ['id' => 'id:mock-ou-'.(count($this->orgUnits) + 1), 'name' => $name, 'parentPath' => $parentPath];
        $this->save();

        return new WorkspaceOrgUnit($this->orgUnits[$path]['id'], $name, $path, $parentPath);
    }

    // ---- Internals --------------------------------------------------------

    private function called(string $operation, ?string $target = null): void
    {
        $this->calls[] = ['operation' => $operation, 'target' => $target];

        if (! empty($this->failures[$operation])) {
            throw array_shift($this->failures[$operation]);
        }
    }

    private function setSuspended(string $userKey, bool $suspended): WorkspaceUser
    {
        $id = $this->resolveOrFail($userKey);
        $this->users[$id]['suspended'] = $suspended;
        $this->save();

        return WorkspaceUser::fromArray($this->users[$id]);
    }

    private function resolve(string $userKey): ?string
    {
        if (isset($this->users[$userKey])) {
            return $userKey;
        }

        $email = strtolower($userKey);

        foreach ($this->users as $id => $user) {
            if ($user['primaryEmail'] === $email) {
                return (string) $id;
            }
        }

        return null;
    }

    private function resolveOrFail(string $userKey): string
    {
        return $this->resolve($userKey) ?? throw new WorkspaceNotFoundException("Resource Not Found: {$userKey}");
    }

    /**
     * @param  array<string, mixed>  $user
     */
    private function store(array $user): WorkspaceUser
    {
        $this->users[$user['id']] = $user;

        return WorkspaceUser::fromArray($user);
    }

    private function nextId(): string
    {
        // Shaped like Google's numeric ids; the prefix makes mock data obvious.
        return '9'.str_pad((string) ++$this->sequence, 20, '0', STR_PAD_LEFT);
    }

    private function load(): void
    {
        if ($this->statePath === null || ! is_file($this->statePath)) {
            return;
        }

        $state = json_decode((string) file_get_contents($this->statePath), true);

        if (is_array($state)) {
            $this->users = $state['users'] ?? [];
            $this->deleted = $state['deleted'] ?? [];
            $this->domains = $state['domains'] ?? [];
            $this->orgUnits = $state['orgUnits'] ?? [];
            $this->sequence = (int) ($state['sequence'] ?? 0);
        }
    }

    private function save(): void
    {
        if ($this->statePath === null) {
            return;
        }

        if (! is_dir(dirname($this->statePath))) {
            mkdir(dirname($this->statePath), 0775, true);
        }

        file_put_contents($this->statePath, json_encode([
            'users' => $this->users,
            'deleted' => $this->deleted,
            'domains' => $this->domains,
            'orgUnits' => $this->orgUnits,
            'sequence' => $this->sequence,
        ], JSON_PRETTY_PRINT), LOCK_EX);
    }
}
