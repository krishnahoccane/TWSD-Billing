<?php

namespace App\Modules\Domains\Contracts;

use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;

/**
 * Google's domain-ownership verification (Site Verification API).
 * Implementations: GoogleDomainVerificationProvider and
 * MockDomainVerificationProvider. Throws WorkspaceException subclasses
 * with the same retry semantics as the Workspace provider.
 *
 * @throws WorkspaceException
 */
interface DomainVerificationProviderInterface
{
    public function name(): string;

    /** The value the domain owner must publish as a DNS TXT record on the domain. */
    public function dnsTxtToken(string $domainName): string;

    /**
     * Ask Google to check the DNS record now. True = ownership verified.
     * False = Google could not find the record (not an error; try again later).
     */
    public function verify(string $domainName): bool;
}
