<?php

namespace App\Modules\GoogleWorkspace\Providers;

use App\Modules\GoogleWorkspace\Contracts\WorkspaceProviderInterface;
use App\Modules\GoogleWorkspace\Data\NewWorkspaceUser;
use App\Modules\GoogleWorkspace\Data\WorkspaceDomain;
use App\Modules\GoogleWorkspace\Data\WorkspaceOrgUnit;
use App\Modules\GoogleWorkspace\Data\WorkspaceUser;
use App\Modules\GoogleWorkspace\Data\WorkspaceUserPage;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceConflictException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceNotFoundException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceRateLimitException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceTransientException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;

/**
 * Google Admin SDK Directory API (REST v1).
 * Reference: https://developers.google.com/workspace/admin/directory/reference/rest
 *
 * STATUS: implemented against the published reference and covered by
 * tests that fake the HTTP layer. It has NOT been run against a live
 * Google Workspace customer. See docs/GOOGLE_WORKSPACE_INTEGRATION.md
 * for the go-live checklist.
 */
class GoogleWorkspaceProvider implements WorkspaceProviderInterface
{
    private const BASE = 'https://admin.googleapis.com/admin/directory/v1';

    private const RATE_LIMIT_REASONS = ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'dailyLimitExceeded'];

    public function __construct(
        private readonly Http $http,
        private readonly GoogleAccessTokenProvider $tokens,
        private readonly string $customerId = 'my_customer',
    ) {}

    public function name(): string
    {
        return 'google';
    }

    public function listUsers(?string $pageToken = null, int $pageSize = 500): WorkspaceUserPage
    {
        $data = $this->send('GET', '/users', query: array_filter([
            'customer' => $this->customerId,
            'maxResults' => max(1, min(500, $pageSize)),
            'orderBy' => 'email',
            'projection' => 'basic',
            'pageToken' => $pageToken,
        ], fn ($value) => $value !== null));

        return new WorkspaceUserPage(
            array_map($this->user(...), $data['users'] ?? []),
            $data['nextPageToken'] ?? null,
        );
    }

    public function getUser(string $userKey): ?WorkspaceUser
    {
        try {
            return $this->user($this->send('GET', '/users/'.rawurlencode($userKey), query: ['projection' => 'basic']));
        } catch (WorkspaceNotFoundException) {
            return null;
        }
    }

    public function createUser(NewWorkspaceUser $user): WorkspaceUser
    {
        return $this->user($this->send('POST', '/users', array_filter([
            'primaryEmail' => $user->primaryEmail,
            'name' => ['givenName' => $user->givenName, 'familyName' => $user->familyName],
            'password' => $user->password,
            'changePasswordAtNextLogin' => true,
            'orgUnitPath' => $user->orgUnitPath,
            'recoveryEmail' => $user->recoveryEmail,
            'recoveryPhone' => $user->recoveryPhone,
        ], fn ($value) => $value !== null)));
    }

    public function updateUser(string $userKey, array $attributes): WorkspaceUser
    {
        $body = [];

        foreach (['first_name' => 'givenName', 'last_name' => 'familyName', 'display_name' => 'displayName'] as $key => $field) {
            if (array_key_exists($key, $attributes)) {
                $body['name'][$field] = $attributes[$key];
            }
        }

        $map = [
            'recovery_email' => 'recoveryEmail', 'recovery_phone' => 'recoveryPhone', 'org_unit_path' => 'orgUnitPath',
            'password' => 'password', 'change_password_at_next_login' => 'changePasswordAtNextLogin',
        ];

        foreach ($map as $key => $field) {
            if (array_key_exists($key, $attributes)) {
                // Google clears recovery details when an empty string is sent.
                $body[$field] = $attributes[$key] ?? '';
            }
        }

        return $this->user($this->send('PATCH', '/users/'.rawurlencode($userKey), $body));
    }

    public function suspendUser(string $userKey): WorkspaceUser
    {
        return $this->user($this->send('PATCH', '/users/'.rawurlencode($userKey), ['suspended' => true]));
    }

    public function reactivateUser(string $userKey): WorkspaceUser
    {
        return $this->user($this->send('PATCH', '/users/'.rawurlencode($userKey), ['suspended' => false]));
    }

    public function signOutUser(string $userKey): void
    {
        $this->send('POST', '/users/'.rawurlencode($userKey).'/signOut');
    }

    /**
     * POST /users/{userKey}/twoStepVerification/turnOff, empty body, scope
     * admin.directory.user.security (Directory API reference, checked 2026-11-19).
     */
    public function turnOffTwoStepVerification(string $userKey): void
    {
        $this->send('POST', '/users/'.rawurlencode($userKey).'/twoStepVerification/turnOff');
    }

    public function deleteUser(string $userKey): void
    {
        $this->send('DELETE', '/users/'.rawurlencode($userKey));
    }

    public function restoreUser(string $googleUserId, string $orgUnitPath = '/'): void
    {
        $this->send('POST', '/users/'.rawurlencode($googleUserId).'/undelete', ['orgUnitPath' => $orgUnitPath]);
    }

    public function listDomains(): array
    {
        $data = $this->send('GET', '/customer/'.rawurlencode($this->customerId).'/domains');

        $domains = [];

        foreach ($data['domains'] ?? [] as $item) {
            $domains[] = $this->domain($item);

            // Domain aliases are nested in their parent domain (domainAliases[]).
            foreach ($item['domainAliases'] ?? [] as $alias) {
                $domains[] = new WorkspaceDomain(
                    strtolower((string) $alias['domainAliasName']),
                    false,
                    (bool) ($alias['verified'] ?? false),
                    strtolower((string) ($alias['parentDomainName'] ?? $item['domainName'])),
                );
            }
        }

        return $domains;
    }

    public function getDomain(string $domainName): ?WorkspaceDomain
    {
        try {
            return $this->domain($this->send('GET', '/customer/'.rawurlencode($this->customerId).'/domains/'.rawurlencode($domainName)));
        } catch (WorkspaceNotFoundException) {
            return null;
        }
    }

    public function addDomain(string $domainName): WorkspaceDomain
    {
        return $this->domain($this->send('POST', '/customer/'.rawurlencode($this->customerId).'/domains', ['domainName' => $domainName]));
    }

    public function removeDomain(string $domainName): void
    {
        $this->send('DELETE', '/customer/'.rawurlencode($this->customerId).'/domains/'.rawurlencode($domainName));
    }

    public function listOrgUnits(): array
    {
        $data = $this->send('GET', '/customer/'.rawurlencode($this->customerId).'/orgunits', query: ['type' => 'all']);

        return array_map($this->orgUnit(...), $data['organizationUnits'] ?? []);
    }

    public function createOrgUnit(string $name, string $parentPath = '/'): WorkspaceOrgUnit
    {
        return $this->orgUnit($this->send('POST', '/customer/'.rawurlencode($this->customerId).'/orgunits', [
            'name' => $name,
            'parentOrgUnitPath' => $parentPath,
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $url = self::BASE.$path.($query === [] ? '' : '?'.http_build_query($query));

        $request = $this->http->withToken($this->tokens->token())
            ->acceptJson()
            ->timeout(20)
            ->connectTimeout(5);

        try {
            $response = $body === null
                ? $request->send($method, $url)
                : $request->send($method, $url, ['json' => $body]);
        } catch (ConnectionException) {
            throw new WorkspaceTransientException("Google Directory API unreachable ({$method} {$path}).");
        }

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        throw $this->exceptionFor($response, $method, $path);
    }

    private function exceptionFor(Response $response, string $method, string $path): WorkspaceException
    {
        $status = $response->status();
        $reason = (string) ($response->json('error.errors.0.reason') ?? '');
        // Google's own error text; it contains no credentials.
        $message = "Google Directory API {$status} on {$method} {$path}: ".($response->json('error.message') ?? 'no message');

        if ($status === 401) {
            $this->tokens->forget();

            return new WorkspaceTransientException($message);
        }

        if ($status === 429 || ($status === 403 && in_array($reason, self::RATE_LIMIT_REASONS, true))) {
            return new WorkspaceRateLimitException($message, max(1, (int) ($response->header('Retry-After') ?: 60)));
        }

        return match (true) {
            $status === 404 => new WorkspaceNotFoundException($message),
            $status === 409 => new WorkspaceConflictException($message),
            $status >= 500 => new WorkspaceTransientException($message),
            default => new WorkspaceException($message),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function user(array $data): WorkspaceUser
    {
        return new WorkspaceUser(
            id: (string) $data['id'],
            primaryEmail: strtolower((string) $data['primaryEmail']),
            givenName: (string) ($data['name']['givenName'] ?? ''),
            familyName: (string) ($data['name']['familyName'] ?? ''),
            suspended: (bool) ($data['suspended'] ?? false),
            orgUnitPath: (string) ($data['orgUnitPath'] ?? '/'),
            createdAt: isset($data['creationTime']) ? CarbonImmutable::parse($data['creationTime']) : null,
            displayName: $data['name']['displayName'] ?? null,
            recoveryEmail: $data['recoveryEmail'] ?? null,
            recoveryPhone: $data['recoveryPhone'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function domain(array $data): WorkspaceDomain
    {
        return new WorkspaceDomain(
            strtolower((string) $data['domainName']),
            (bool) ($data['isPrimary'] ?? false),
            (bool) ($data['verified'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function orgUnit(array $data): WorkspaceOrgUnit
    {
        return new WorkspaceOrgUnit(
            (string) ($data['orgUnitId'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['orgUnitPath'] ?? '/'),
            $data['parentOrgUnitPath'] ?? null,
        );
    }
}
