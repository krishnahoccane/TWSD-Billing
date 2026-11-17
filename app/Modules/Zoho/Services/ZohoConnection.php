<?php

namespace App\Modules\Zoho\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Integrations\Models\IntegrationSetting;
use App\Modules\Zoho\Exceptions\ZohoException;
use App\Modules\Zoho\Exceptions\ZohoNotConnectedException;
use App\Modules\Zoho\Exceptions\ZohoTransientException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Str;

/**
 * Zoho OAuth 2.0 (authorization code + refresh token) and the Books
 * settings the client needs.
 *
 * Reference: https://www.zoho.com/books/api/v3/oauth/
 *  - authorize: GET  {accounts}/oauth/v2/auth  (response_type=code, access_type=offline, prompt=consent)
 *  - token:     POST {accounts}/oauth/v2/token (authorization_code | refresh_token)
 *  - revoke:    POST {accounts}/oauth/v2/token/revoke
 *  - header:    "Authorization: Zoho-oauthtoken {access_token}"; access tokens last one hour.
 *
 * The refresh token lives only in integration_settings.secrets (encrypted
 * with APP_KEY); the access token only in the cache (encrypted). Neither is
 * ever logged, audited or sent to the browser.
 */
class ZohoConnection
{
    public const PROVIDER = 'zoho_books';

    public const SCOPES = [
        'ZohoBooks.contacts.ALL',
        'ZohoBooks.invoices.ALL',
        'ZohoBooks.customerpayments.ALL',
        'ZohoBooks.settings.READ',
    ];

    private const ACCESS_TOKEN_CACHE = 'zoho-books:access-token';

    /** Zoho data-centre account hosts we accept in the OAuth callback. */
    private const ACCOUNTS_HOSTS = [
        'accounts.zoho.com', 'accounts.zoho.eu', 'accounts.zoho.in', 'accounts.zoho.com.au',
        'accounts.zoho.jp', 'accounts.zoho.ca', 'accounts.zoho.com.cn', 'accounts.zoho.sa',
    ];

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly AuditLogger $audit,
    ) {}

    public function settingRow(): IntegrationSetting
    {
        return IntegrationSetting::query()->firstOrCreate(['provider' => self::PROVIDER], ['settings' => []]);
    }

    /** @return array<string, mixed> non-secret settings */
    public function settings(): array
    {
        return $this->settingRow()->settings ?? [];
    }

    public function isConnected(): bool
    {
        return filled($this->settingRow()->secrets['refresh_token'] ?? null);
    }

    public function organisationId(): string
    {
        return (string) ($this->settings()['organization_id'] ?? config('wcc.zoho.organisation_id') ?? '');
    }

    public function apiBase(): string
    {
        $domain = $this->settings()['api_domain'] ?? null;

        // The token response tells us the data centre's API domain.
        return is_string($domain) && $domain !== ''
            ? rtrim($domain, '/').'/books/v3'
            : rtrim((string) config('wcc.zoho.api_base_url'), '/');
    }

    /**
     * @param  array{organization_id?: ?string, item_id?: ?string, tax_id?: ?string, deposit_account_id?: ?string}  $values
     */
    public function saveSettings(array $values, User $actor): void
    {
        $row = $this->settingRow();
        $previous = $row->settings ?? [];
        $row->settings = array_merge($previous, array_map(fn ($value) => blank($value) ? null : trim((string) $value), $values));
        $row->updated_by = $actor->getKey();
        $row->save();

        $this->audit->record('integration.zoho.settings_saved', $row,
            previous: array_intersect_key($previous, $values), new: $values, actor: $actor);
    }

    public function authorizationUrl(string $state): string
    {
        $this->assertConfigured();

        return rtrim((string) config('wcc.zoho.accounts_base_url'), '/').'/oauth/v2/auth?'.http_build_query([
            'scope' => implode(',', self::SCOPES),
            'client_id' => config('wcc.zoho.client_id'),
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'redirect_uri' => config('wcc.zoho.redirect_uri'),
            'state' => $state,
        ]);
    }

    /**
     * Exchange the one-time code (valid ~2 minutes) for tokens and keep the
     * refresh token. $accountsServer is Zoho's "accounts-server" callback
     * parameter; it is only used if it is a known Zoho accounts host.
     */
    public function connect(string $code, ?string $accountsServer, User $actor): void
    {
        $this->assertConfigured();

        $accounts = $this->accountsUrl($accountsServer);

        $data = $this->tokenRequest($accounts, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('wcc.zoho.client_id'),
            'client_secret' => config('wcc.zoho.client_secret'),
            'redirect_uri' => config('wcc.zoho.redirect_uri'),
        ]);

        if (blank($data['refresh_token'] ?? null)) {
            throw new ZohoException('Zoho did not return a refresh token. Remove the app\'s access in Zoho and connect again.');
        }

        $row = $this->settingRow();
        $row->secrets = ['refresh_token' => $data['refresh_token']];
        $row->settings = array_merge($row->settings ?? [], [
            'accounts_url' => $accounts,
            'api_domain' => $data['api_domain'] ?? null,
            'connected_at' => now()->toIso8601String(),
            'connected_by' => $actor->email,
        ]);
        $row->updated_by = $actor->getKey();
        $row->save();

        $this->cacheAccessToken($data);
        $this->audit->record('integration.zoho.connected', $row, new: ['api_domain' => $data['api_domain'] ?? null], actor: $actor);
    }

    public function disconnect(User $actor): void
    {
        $row = $this->settingRow();
        $refresh = $row->secrets['refresh_token'] ?? null;

        if ($refresh !== null) {
            try {
                $this->http->asForm()->timeout(15)->post($this->accountsUrl(null).'/oauth/v2/token/revoke', ['token' => $refresh]);
            } catch (\Throwable) {
                // Revocation is best-effort; the token is forgotten locally regardless.
            }
        }

        $row->secrets = null;
        $row->settings = array_merge($row->settings ?? [], ['connected_at' => null, 'connected_by' => null]);
        $row->save();
        $this->cache->forget(self::ACCESS_TOKEN_CACHE);

        $this->audit->record('integration.zoho.disconnected', $row, actor: $actor);
    }

    /** A valid access token, refreshed when the cached one has expired. */
    public function accessToken(): string
    {
        $cached = $this->cache->get(self::ACCESS_TOKEN_CACHE);

        if (is_string($cached) && $cached !== '') {
            return decrypt($cached);
        }

        $refresh = $this->settingRow()->secrets['refresh_token'] ?? null;

        if (blank($refresh)) {
            throw new ZohoNotConnectedException('Zoho Books is not connected. Connect it under Integrations → Zoho Books.');
        }

        $this->assertConfigured();

        $data = $this->tokenRequest($this->accountsUrl(null), [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id' => config('wcc.zoho.client_id'),
            'client_secret' => config('wcc.zoho.client_secret'),
        ]);

        return $this->cacheAccessToken($data);
    }

    public function forgetAccessToken(): void
    {
        $this->cache->forget(self::ACCESS_TOKEN_CACHE);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function cacheAccessToken(array $data): string
    {
        $token = (string) ($data['access_token'] ?? '');

        if ($token === '') {
            throw new ZohoException('Zoho did not return an access token.');
        }

        $this->cache->put(self::ACCESS_TOKEN_CACHE, encrypt($token), max(60, (int) ($data['expires_in'] ?? 3600) - 120));

        return $token;
    }

    /**
     * @param  array<string, string>  $form
     * @return array<string, mixed>
     */
    private function tokenRequest(string $accounts, array $form): array
    {
        try {
            $response = $this->http->asForm()->acceptJson()->timeout(20)->connectTimeout(5)->post($accounts.'/oauth/v2/token', $form);
        } catch (ConnectionException) {
            throw new ZohoTransientException('Could not reach Zoho Accounts.');
        }

        if ($response->serverError()) {
            throw new ZohoTransientException('Zoho Accounts error ('.$response->status().').');
        }

        $data = $response->json() ?? [];

        if ($response->failed() || isset($data['error'])) {
            // Zoho's error code only (for example invalid_code) — never the request.
            throw new ZohoException('Zoho rejected the request: '.($data['error'] ?? 'HTTP '.$response->status()));
        }

        return $data;
    }

    private function accountsUrl(?string $accountsServer): string
    {
        if ($accountsServer !== null) {
            $host = parse_url($accountsServer, PHP_URL_HOST);

            if (is_string($host) && in_array(strtolower($host), self::ACCOUNTS_HOSTS, true) && str_starts_with($accountsServer, 'https://')) {
                return 'https://'.strtolower($host);
            }
        }

        $stored = $this->settings()['accounts_url'] ?? null;

        return rtrim(is_string($stored) && $stored !== '' ? $stored : (string) config('wcc.zoho.accounts_base_url'), '/');
    }

    private function assertConfigured(): void
    {
        foreach (['client_id', 'client_secret', 'redirect_uri', 'accounts_base_url'] as $key) {
            if (blank(config('wcc.zoho.'.$key))) {
                throw new ZohoNotConnectedException('ZOHO_'.Str::upper($key).' is not set on the server.');
            }
        }
    }
}
