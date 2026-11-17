<?php

namespace App\Modules\Domains\Support;

/**
 * In-memory DNS for automated tests.
 */
class FakeDnsResolver extends DnsResolver
{
    /** @var array<string, list<string>> */
    public array $records = [];

    public function txtRecords(string $domainName): array
    {
        return $this->records[strtolower($domainName)] ?? [];
    }
}
