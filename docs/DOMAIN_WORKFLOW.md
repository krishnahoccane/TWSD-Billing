# Domain workflow

**Status (end of Phase D):** built and tested against the **mock** verification and Workspace providers. The real
Google clients are written and tested against a faked HTTP layer but have never called Google.

## Concepts

| Type | Meaning |
| --- | --- |
| `primary` | The Workspace customer's primary domain. Cannot be removed here. |
| `secondary` | An additional domain with its own independent mailboxes — what organisations request. |
| `alias` | A domain alias of another domain. Not a substitute for a secondary domain. |

A domain belongs to exactly one organisation (`organisation_domains.domain_name` is unique).

Two ways a domain reaches an organisation:

1. **Mapping** (Phase B) — a Super Admin records that a domain *already in Google Workspace* belongs to an organisation.
2. **Request** (this workflow) — a manager asks for a *new* domain; it is verified and added to Google.

## Adding a domain

| # | Step | Who | Status afterwards |
| --- | --- | --- | --- |
| 1 | Submit the domain | Organisation Manager (`domains.request`) | `pending` |
| 2 | Checks: syntax; not already an organisation's domain; no request in progress; not already in Google Workspace | System | |
| 3 | Approve or reject (reason required to reject) | Super Admin (`domains.approve`) — **never the requester** | `approved` / `rejected` |
| 4 | Obtain Google's DNS TXT verification value | `ProcessDomainRequestJob` | `verification_pending` |
| 5 | Show the TXT record (type, host `@`, value) to the organisation | System | |
| 6 | Organisation publishes the record at its DNS host | Organisation | |
| 7 | Optional public DNS lookup ("Check public DNS") — informational only | `domains.process` | |
| 8 | Confirm and ask Google to verify | Super Admin / assigned Ops Admin (`domains.process`) | `verifying` |
| 9 | Google checks the record | `VerifyGoogleDomainJob` | `verified`, or back to `verification_pending` if not found yet |
| 10 | Add the secondary domain to Google Workspace; record its state | `ActivateGoogleDomainJob` | |
| 11 | Activate the domain for the organisation | same job | `completed` |

The requester can cancel while the request is still `pending`. A Super Admin can reject a request while it is
pending, awaiting verification or failed.

Principles enforced in code and covered by tests

- A request is only a claim. Nothing is added to Google and no domain is activated until Google has verified it.
- Approval does not prove ownership; it only starts verification.
- "Record not found" is not an error: the request keeps waiting and can be verified again later.
- Activation only runs for a request in the `verified` state; calling the step on anything else does nothing.
- Every transition is written to `domain_history` and the audit log; every check to `domain_verification_checks`.

## Removing a domain

1. A Super Admin (`domains.remove`) requests removal with a reason and confirms that no aliases or groups use it.
2. **Blocked while any mailbox uses the domain** — active, suspended and soft-deleted mailboxes all count; only
   permanently deleted ones do not.
3. A **different** Super Admin approves by typing the domain name (`DOMAIN_REQUIRE_SECOND_APPROVER`, default on).
4. `RemoveGoogleDomainJob` re-checks the mailboxes, deletes the domain from Google Workspace and marks the local
   row `removed`. The row is kept because historical mailboxes and invoices refer to it.
5. A removed domain can be requested again; the same row is revived.

Aliases and groups are **attested**, not checked automatically.

## Failures

Jobs share the mailbox-job guarantees: 5 attempts with backoff, timeouts, rate-limit release, uniqueness per
request and step. A step that finally fails sets the request to `failed` with the step and error recorded (request,
history, audit log); **Retry** re-runs just that step. Adding the domain is idempotent — a retry after a partial
success finds the domain already in Google and does not add it twice.

## Google APIs

| Purpose | Call | Verified against docs |
| --- | --- | --- |
| Verification value | Site Verification API `POST /siteVerification/v1/token` `{site:{type:"INET_DOMAIN",identifier}, verificationMethod:"DNS_TXT"}` | Yes (2026-11-17) |
| Verify ownership | Site Verification API `POST /siteVerification/v1/webResource?verificationMethod=DNS_TXT` `{site:{…}}` | Yes; the error returned when the record is missing is **not documented**, so any 4xx other than auth/quota is treated as "not verified yet" |
| Add / remove / read domain | Directory API `domains.insert` / `domains.delete` / `domains.get` | Resource fields yes; URL paths to confirm live |

Extra OAuth scope required: `https://www.googleapis.com/auth/siteverification`. With domain-wide delegation the
impersonated admin becomes the verified owner of the domain in Google's records.

**To confirm in the first live test:** that verifying through the Site Verification API with this account is
sufficient for Google Workspace to treat the newly added secondary domain as verified, and how long MX/activation
takes. Until then the workflow should be treated as unproven against Google.

## Screens

Domains (all / mine) · domain details with activity and mailbox count · request new domain · domain requests ·
pending approvals · domain verification · request detail (status, DNS record, checks, history, actions).

## Not included in Phase D

- Notifications to Super Admins and requesters (Phase G) — work queues are the Pending approvals and Domain
  verification screens.
- MX record checks and automated DNS (Phase 3, `DnsProviderInterface`).
- Changing a domain's organisation, or its type, after activation.
- Removing a domain that was only mapped is supported; un-mapping without deleting it from Google is not.
