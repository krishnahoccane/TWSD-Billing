<?php

namespace App\Modules\Domains\Providers;

use App\Modules\Domains\Contracts\DomainVerificationProviderInterface;
use Throwable;

/**
 * Stand-in for Google's Site Verification API. Makes no network calls and
 * proves nothing about real domain ownership.
 *
 * By default every domain "verifies" so the workflow can be walked through
 * locally; tests switch $autoVerify off or inject failures.
 */
class MockDomainVerificationProvider implements DomainVerificationProviderInterface
{
    public bool $autoVerify = true;

    /** @var list<string> domains that verify even when autoVerify is off */
    public array $verifiable = [];

    /** @var list<Throwable> */
    private array $failures = [];

    /** @var list<array{operation: string, domain: string}> */
    public array $calls = [];

    public function name(): string
    {
        return 'mock';
    }

    public function failNext(Throwable $exception): void
    {
        $this->failures[] = $exception;
    }

    public function dnsTxtToken(string $domainName): string
    {
        $this->called('token', $domainName);

        // Deterministic and clearly fake.
        return 'google-site-verification=MOCK-'.substr(hash('sha256', strtolower($domainName)), 0, 32);
    }

    public function verify(string $domainName): bool
    {
        $this->called('verify', $domainName);

        return $this->autoVerify || in_array(strtolower($domainName), $this->verifiable, true);
    }

    private function called(string $operation, string $domainName): void
    {
        $this->calls[] = ['operation' => $operation, 'domain' => $domainName];

        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }
    }
}
