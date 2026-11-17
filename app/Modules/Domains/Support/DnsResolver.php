<?php

namespace App\Modules\Domains\Support;

/**
 * Public DNS TXT lookup used for the informational "check DNS" step.
 * It is a convenience for the operator only — Google's own verification
 * is what counts. Tests bind FakeDnsResolver so no lookup leaves the machine.
 */
class DnsResolver
{
    /**
     * @return list<string>
     */
    public function txtRecords(string $domainName): array
    {
        $records = @dns_get_record($domainName, DNS_TXT);

        if (! is_array($records)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $record) => isset($record['txt']) ? (string) $record['txt'] : null,
            $records,
        )));
    }
}
