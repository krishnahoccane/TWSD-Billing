<?php

namespace App\Modules\Domains\Providers;

use App\Modules\Domains\Contracts\DomainVerificationProviderInterface;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceRateLimitException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceTransientException;
use App\Modules\GoogleWorkspace\Providers\GoogleAccessTokenProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * Google Site Verification API v1.
 * Reference: https://developers.google.com/site-verification/v1/invoking
 *
 *   POST /token        {site:{type:"INET_DOMAIN",identifier}, verificationMethod:"DNS_TXT"} -> {token, method}
 *   POST /webResource?verificationMethod=DNS_TXT   {site:{type:"INET_DOMAIN",identifier}}   -> verified resource
 *
 * STATUS: written from the published reference and tested against a faked
 * HTTP layer; never run against Google. The reference does not document
 * the error returned when the DNS record is missing, so any 4xx from
 * webResource.insert other than auth/quota errors is treated as "not
 * verified yet" and its message is recorded. Confirm in the first live test.
 */
class GoogleDomainVerificationProvider implements DomainVerificationProviderInterface
{
    private const BASE = 'https://www.googleapis.com/siteVerification/v1';

    public function __construct(
        private readonly Http $http,
        private readonly GoogleAccessTokenProvider $tokens,
    ) {}

    public function name(): string
    {
        return 'google';
    }

    public function dnsTxtToken(string $domainName): string
    {
        $response = $this->post('/token', [
            'site' => ['type' => 'INET_DOMAIN', 'identifier' => $domainName],
            'verificationMethod' => 'DNS_TXT',
        ]);

        if ($response->failed() || blank($response->json('token'))) {
            throw $this->exception($response->status(), (string) $response->json('error.message'), 'token');
        }

        return (string) $response->json('token');
    }

    public function verify(string $domainName): bool
    {
        $response = $this->post('/webResource?verificationMethod=DNS_TXT', [
            'site' => ['type' => 'INET_DOMAIN', 'identifier' => $domainName],
        ]);

        if ($response->successful()) {
            return true;
        }

        $status = $response->status();

        if (in_array($status, [401, 403, 429], true) || $status >= 500) {
            throw $this->exception($status, (string) $response->json('error.message'), 'verify');
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $path, array $body)
    {
        try {
            return $this->http->withToken($this->tokens->token())
                ->acceptJson()->timeout(20)->connectTimeout(5)
                ->post(self::BASE.$path, $body);
        } catch (ConnectionException) {
            throw new WorkspaceTransientException('Google Site Verification API unreachable.');
        }
    }

    private function exception(int $status, string $message, string $operation): WorkspaceException
    {
        $text = "Google Site Verification {$status} on {$operation}: ".($message ?: 'no message');

        return match (true) {
            $status === 429 => new WorkspaceRateLimitException($text),
            $status === 401, $status >= 500 => new WorkspaceTransientException($text),
            default => new WorkspaceException($text),
        };
    }
}
