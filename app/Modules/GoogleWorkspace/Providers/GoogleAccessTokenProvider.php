<?php

namespace App\Modules\GoogleWorkspace\Providers;

use App\Modules\GoogleWorkspace\Exceptions\WorkspaceNotConfiguredException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceTransientException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * OAuth 2.0 service-account flow (signed JWT bearer grant).
 *
 * With delegation enabled the token impersonates GOOGLE_ADMIN_EMAIL
 * (domain-wide delegation); without it the service account acts as itself
 * and must hold an admin role directly. The key file is read only here,
 * and neither the key nor the token is ever logged or returned to a view.
 */
class GoogleAccessTokenProvider
{
    public const SCOPES = [
        'https://www.googleapis.com/auth/admin.directory.user',
        'https://www.googleapis.com/auth/admin.directory.user.security', // users.signOut
        'https://www.googleapis.com/auth/admin.directory.domain',
        'https://www.googleapis.com/auth/admin.directory.orgunit',
        'https://www.googleapis.com/auth/siteverification',               // domain ownership verification
    ];

    private const CACHE_KEY = 'google-workspace:access-token';

    /**
     * @param  array{service_account_json: ?string, admin_email: ?string, delegation_enabled: bool}  $config
     */
    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly array $config,
    ) {}

    public function token(): string
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return decrypt($cached);
        }

        $key = $this->serviceAccountKey();
        $now = time();

        $claims = [
            'iss' => $key['client_email'],
            'scope' => implode(' ', self::SCOPES),
            'aud' => $key['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        if ($this->config['delegation_enabled']) {
            if (blank($this->config['admin_email'])) {
                throw new WorkspaceNotConfiguredException('GOOGLE_ADMIN_EMAIL is required when delegation is enabled.');
            }

            $claims['sub'] = $this->config['admin_email'];
        }

        try {
            $response = $this->http->asForm()->timeout(15)->connectTimeout(5)->post($key['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->sign($claims, $key['private_key']),
            ]);
        } catch (ConnectionException $e) {
            throw new WorkspaceTransientException('Could not reach the Google token endpoint.');
        }

        if ($response->serverError()) {
            throw new WorkspaceTransientException('Google token endpoint error ('.$response->status().').');
        }

        if ($response->failed() || blank($response->json('access_token'))) {
            // The error code only (for example invalid_grant) — never the assertion.
            throw new WorkspaceNotConfiguredException(
                'Google rejected the service-account credentials: '.($response->json('error') ?? 'HTTP '.$response->status()),
            );
        }

        $token = (string) $response->json('access_token');
        $ttl = max(60, (int) $response->json('expires_in', 3600) - 120);

        $this->cache->put(self::CACHE_KEY, encrypt($token), $ttl);

        return $token;
    }

    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * @return array{client_email: string, private_key: string, token_uri: string}
     */
    private function serviceAccountKey(): array
    {
        $path = $this->config['service_account_json'];

        if (blank($path) || ! is_file($path) || ! is_readable($path)) {
            throw new WorkspaceNotConfiguredException('The Google service-account key file is missing or unreadable.');
        }

        $key = json_decode((string) file_get_contents($path), true);

        if (! is_array($key) || ($key['type'] ?? null) !== 'service_account'
            || blank($key['client_email'] ?? null) || blank($key['private_key'] ?? null)) {
            throw new WorkspaceNotConfiguredException('The Google service-account key file is not a valid service-account key.');
        }

        return [
            'client_email' => $key['client_email'],
            'private_key' => $key['private_key'],
            'token_uri' => $key['token_uri'] ?? 'https://oauth2.googleapis.com/token',
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function sign(array $claims, string $privateKey): string
    {
        $encode = fn (string $data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $input = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode(json_encode($claims));

        if (! openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new WorkspaceNotConfiguredException('The Google service-account private key could not be used for signing.');
        }

        return $input.'.'.$encode($signature);
    }
}
