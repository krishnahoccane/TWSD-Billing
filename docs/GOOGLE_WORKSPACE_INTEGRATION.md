# Google Workspace integration

**Status (end of Phase C):** the module is built and tested against the **mock provider**. The real Google
client is written and tested against a faked HTTP layer, but it has **never been run against a live Google
Workspace customer**. Nothing is connected until the go-live checklist below is completed.

Reference: https://developers.google.com/workspace/admin/directory/reference/rest

## Shape

```
WorkspaceProviderInterface                     app/Modules/GoogleWorkspace/Contracts
├── GoogleWorkspaceProvider                    Admin SDK Directory API over HTTPS (REST v1)
│     └── GoogleAccessTokenProvider            service-account JWT → access token (cached, encrypted)
├── MockWorkspaceProvider                      no network; in memory (tests) or a JSON file (local)
└── LoggingWorkspaceProvider (decorator)       writes every call to google_api_logs
```

Which provider is bound (`GoogleWorkspaceServiceProvider`):

| Situation | Provider |
| --- | --- |
| Automated tests | Mock — always, even if `GOOGLE_WORKSPACE_ENABLED=true` (covered by a test) |
| `GOOGLE_WORKSPACE_ENABLED=false` (default) | Mock |
| `GOOGLE_WORKSPACE_ENABLED=true` outside tests | Google |

Every screen that shows mailbox data displays a "Mock mode — not connected to Google" banner while the mock is in use.

## API calls used

Verified against the published reference on 2026-11-17: `users.list`, the `User` resource, `users.undelete`,
`users.signOut`, the user-management guide and the `Domains` resource. The domain and organisational-unit **URL
paths** and the HTTP verb of `users.patch` / `users.delete` were written from the standard Directory API layout and
must be confirmed during the first live test.

| Operation | Request |
| --- | --- |
| List users | `GET /users?customer=…&maxResults=500&orderBy=email&projection=basic[&pageToken=…]` — repeated until `nextPageToken` is absent |
| Get user | `GET /users/{userKey}` (404 → "does not exist") |
| Create user | `POST /users` `{primaryEmail, name{givenName,familyName}, password, changePasswordAtNextLogin:true, orgUnitPath, recoveryEmail?, recoveryPhone?}` |
| Update user | `PATCH /users/{userKey}` with only the changed fields |
| Suspend / reactivate | `PATCH /users/{userKey}` `{suspended: true|false}` |
| Sign out | `POST /users/{userKey}/signOut` (empty body) |
| Delete | `DELETE /users/{userKey}` |
| Restore deleted user | `POST /users/{id}/undelete` `{orgUnitPath}` — immutable id only |
| Domains | `GET|POST /customer/{customer}/domains`, `GET|DELETE …/domains/{domainName}` |
| Organisational units | `GET /customer/{customer}/orgunits?type=all`, `POST …/orgunits` |

Constraints taken from the documentation and enforced here: passwords 8–100 ASCII characters (we generate 20);
recovery phone in E.164 format; first and last name up to 60 characters.

Scopes requested: `admin.directory.user`, `admin.directory.user.security` (required by `signOut`),
`admin.directory.domain`, `admin.directory.orgunit`.

## Error handling

| Google response | Exception | Job behaviour |
| --- | --- | --- |
| 429, or 403 with a rate/quota reason | `WorkspaceRateLimitException` | Released back to the queue for `Retry-After` seconds |
| 5xx, connection failure, timeout, 401 (token refreshed) | `WorkspaceTransientException` | Retried: 5 attempts, backoff 15 s → 60 s → 3 min → 10 min |
| 404 | `WorkspaceNotFoundException` | Fails immediately |
| 409 | `WorkspaceConflictException` | Fails immediately |
| Other 4xx | `WorkspaceException` | Fails immediately |

A job that finally fails: the account returns to its previous state (a failed creation becomes
`creation_failed`), the error is stored on the account, in its history and in the audit log, and the job stays in
`failed_jobs`. Nothing is discarded.

## Idempotency and reconciliation

- Each account stores the operation in flight (`pending_operation`). An operation runs only while the account is
  still waiting for exactly that operation, so a duplicated or retried job does nothing the second time.
- Jobs are unique per account, operation and payload.
- The Google call always happens before the local transaction. If Google succeeds and the local save fails, the
  retry finds the existing Google user (by id or address), adopts it, sets a fresh password and completes the local
  record — it never creates a second user.
- `ReconcileGoogleAccountsJob` re-queues operations that have been pending for more than 10 minutes.

## Account lifecycle

`google_accounts.account_status` (local lifecycle) is separate from `is_suspended` (Google's flag) and from
`billing_status`. The Google immutable user id (`google_user_id`) is the identity; the address is not.

| Action | Who (default roles) | What happens |
| --- | --- | --- |
| Create | Organisation Manager, Ops Admin, Super Admin | Local `pending_creation` → job creates the user → `active`, billable from that day. Address checked for duplicates locally and in Google first. |
| Edit | same | Name, display name, recovery details; organisational unit for platform admins only. Address, domain and organisation cannot be edited. |
| Suspend | same | Reason required → Google suspended + sessions signed out → `suspended`. **Stays billable.** |
| Reactivate | same | Organisation must be active → `active`. |
| Delete (soft, stage 1) | same | Reason + typed address → Google user **suspended and signed out, not deleted** → `soft_deleted`, not billable from the effective date (today, billing timezone). |
| Restore | Super Admin | `active` again, billable from the restore date. |
| Permanent delete (stage 2) | Two different Super Admins | Requester confirms four checks (ownership, retention, Vault, dependencies) and gives a reason → a second Super Admin types the address to approve → Google user deleted → `permanently_deleted`. The row, Google id and history are kept; the address becomes reusable. |
| Assign organisation | Super Admin | The only way ownership changes. Used for the Unassigned queue. |

### Initial password (decision D-07)

Generated server-side (20 characters), sent to Google with "change at next sign-in", and kept only as an
`APP_KEY`-encrypted value. The user who created the mailbox can reveal it **once** within 24 hours; revealing
deletes it. It is never e-mailed, never put in the session, and never written to logs, history or the audit trail.

### Recovery limits — read before deleting

- A **soft-deleted** mailbox still exists in Google (suspended) with all its data. Local soft deletion is not a
  retention mechanism: if someone deletes the user in the Google Admin console, the data follows Google's rules.
- After **permanent deletion**, Google can restore the user for about **20 days** (per Google's documentation),
  only by a Google super administrator and only with the immutable user id. After that the mailbox and Drive data
  are unrecoverable. This application keeps the id for that purpose but does not yet offer an "undelete from
  Google" button (the provider method exists; the screen is not built).
- Google Vault holds and retention rules are **attested by the requester**, not checked automatically.

## Import and synchronisation

| Job | Schedule | Purpose |
| --- | --- | --- |
| `SyncGoogleAccountsJob` | every 15 minutes | New users; changed names, addresses, OU and suspension state |
| `ReconcileGoogleAccountsJob` | every 30 minutes | Re-queue stuck operations; purge expired one-time passwords |
| `ImportGoogleDomainsJob` | every 6 hours | Domain verification state; organisational units |
| `ImportGoogleUsersJob` | daily 02:30 and on demand | Full import; also flags accounts Google no longer returns |

The Directory API has no cheap "changed since" listing and the whole customer (~600 users) is two requests, so the
15-minute job lists every user. All four can be started from **Google Workspace → Synchronisation**.

Rules

- All pages are fetched (500 per page) until there is no `nextPageToken`.
- A **new** account is assigned to an organisation only if its domain is mapped to one. Otherwise it goes to
  **Unassigned accounts** and is not billed until a Super Admin assigns it.
- An **existing** account's organisation is never changed by synchronisation, even if its address moves to another
  organisation's domain.
- Suspension changes made directly in Google are reflected locally, except while an operation is in flight.
- Accounts missing from Google are flagged (`missing_from_google_at`), never deleted.
- Domains found in Google but not mapped are reported in the sync log, never auto-assigned.
- Every run is recorded in `google_sync_logs`; every provider call in `google_api_logs`.

## Go-live checklist (not done)

1. Decide the authentication model (D-12): an admin role assigned directly to the service account, or domain-wide
   delegation to a dedicated admin user (`GOOGLE_WORKSPACE_DELEGATION_ENABLED=true`, `GOOGLE_ADMIN_EMAIL`).
   Note: Google documents that only a super administrator can undelete users.
2. Create the service account, enable the Admin SDK API, grant the four scopes above.
3. Put the key file on the server outside the web root; set `GOOGLE_SERVICE_ACCOUNT_JSON` to its path.
4. Map every organisation's domains (Organisations → organisation → Domains).
5. In a controlled window set `GOOGLE_WORKSPACE_ENABLED=true`, open **Integrations → Google Workspace**, confirm the
   checklist is green and run **Test connection** (a read of one user).
6. Run the domain sync, then the full user import. Review **Unassigned accounts**.
7. Test create → suspend → reactivate → delete → restore on one disposable mailbox before giving managers access.
8. Start a queue worker for the `google` queue and the scheduler (see `DEPLOYMENT.md`).

Until step 5, no request is sent to Google.

## Tests (Phase C)

Import of 600 users across two pages; assignment by domain; unmatched users unassigned; idempotent re-import;
ownership unchanged when an address moves domain; status drift; missing accounts flagged; failed listing recorded
and rethrown; domain sync; create through the mock; one-time password; duplicate address (local and Google);
validation; update; suspend; reactivate; soft delete without deleting the Google user; restore; permanent deletion
refused without approval and without a second admin; API failure; timeout and retry; rate-limit release; exhausted
retries; duplicate job execution; Google-success/local-failure reconciliation; stuck-operation re-queue; tenant
isolation on every web and API route; HTTP client payloads and error mapping; the test suite cannot reach Google.
