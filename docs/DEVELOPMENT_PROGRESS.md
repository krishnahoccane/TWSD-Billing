# Development progress

Updated after every phase. Last update: 2026-11-17 (end of Phase G).

| Phase | Scope | Status |
| --- | --- | --- |
| A | Foundation: project, auth, roles, permissions, layouts, design system | **Complete** |
| B | Organisation management | **Complete** |
| C | Google Workspace (provider, mock, import, lifecycle, sync) | **Complete against the mock provider; not connected to Google** |
| D | Domain management | **Complete against the mock providers; not connected to Google** |
| E | Billing engine | **Complete** |
| F | Zoho Books | **Complete against the mock; not connected to Zoho** |
| G | Finalisation (audit UI, notifications, hardening, deployment) | **Complete** (notification e-mail needs a mail provider) |

## External integrations

| Integration | State |
| --- | --- |
| Google Workspace Admin SDK | **Not connected — running in mock mode.** The real client is written and tested against a faked HTTP layer but has never called Google. See the go-live checklist in GOOGLE_WORKSPACE_INTEGRATION.md. |
| Zoho Books | **Not connected — running in mock mode locally.** Client, OAuth, sync and webhook are built and tested against a faked HTTP layer but have never called Zoho. See the go-live checklist in ZOHO_INTEGRATION.md. |
| Mail | `log` mailer locally. An SMTP provider is needed for password-reset and notification e-mail (decision D-13). |

**Running in mock mode:** every Google Workspace operation (create, update, suspend, reactivate, sign-out, delete,
import, domain and OU listing) and domain ownership verification. The mocks make no network calls.

## Database

| Environment | Database | State |
| --- | --- | --- |
| Remote (`114.29.238.3`, `billingtwsd_db`) | **MariaDB 10.11.19** — not MySQL 8 (decision D-01) | All 49 tables created (migrated through Phase G), roles and 48 permissions seeded. One Super Admin account (created at the owner's request with a password shorter than the 12-character policy — change it after first sign-in). No organisations or accounts. |
| Local demo (`database/database.sqlite`) | SQLite | Fictional demo data for UI checks (`wcc-demo-sqlite` launch config, port 8001). |
| Automated tests | SQLite in memory | Never touches the remote database. |

## Phase A — Foundation (complete)

### Implemented

- Laravel 12.72 project, Livewire 3.8, Tailwind 4, Vite, Lucide icons; module folders under `app/Modules`.
- **Authentication** (Fortify): login, logout, password reset, password confirmation, TOTP two-factor with
  recovery codes. No self-registration. Login throttling (5/min per email+IP). Deactivated users fail with the
  generic credential error and are signed out mid-session.
- **Enforced 2FA** for privileged users (any platform role) via `two-factor.enrolled` middleware.
- **Access control**: `roles`, `permissions`, `role_permissions`, `user_roles`, `organisation_user_roles`;
  46 abilities (47 after Phase B); four system roles; `AccessResolver`; every ability registered as a Gate; explicit
  `platform.global_access`; tenant query scope (`OrganisationScope`, `BelongsToOrganisation`, `Organisation::visibleTo`).
- **Role assignment service** — scope-checked, idempotent, audited.
- **Organisations table and model** (schema only; CRUD screens are Phase B).
- **Audit log** — append-only model, central `AuditLogger` with secret redaction, request id on every row;
  authentication and role-assignment events are audited.
- **API v1** — `POST /login` (password + TOTP), `POST /logout`, `GET /profile`; Sanctum tokens with expiry;
  JSON error envelope with `request_id`.
- **UI** — app and guest layouts, role-aware sidebar, header with breadcrumbs, organisation switcher (Livewire),
  light/dark theme, notification panel (reads real database notifications; none are sent yet), user menu.
- **Design system** — 28 reusable Blade components (see `/design-system`).
- **Dashboards** — platform and organisation dashboards showing only real data available so far.
- `wcc:create-super-admin` command; idempotent `RolesAndPermissionsSeeder`; local-only `LocalDevelopmentSeeder`.
- Security headers, server-generated `X-Request-Id`, strict Eloquent mode outside production.

### Migrations

`users` (+ two-factor and platform columns), `cache`, `jobs`, `personal_access_tokens`, `notifications`,
`organisations`, `roles`, `permissions`, `role_permissions`, `user_roles`, `organisation_user_roles`, `audit_logs`.

### Tests

70 tests, 456 assertions, all passing (`php artisan test`, SQLite in-memory).

| Suite | Covers |
| --- | --- |
| `Auth/LoginTest` | login, wrong password, deactivated user, mid-session deactivation, throttling, 2FA challenge, no registration, logout, audit without credentials, security headers |
| `Auth/TwoFactorEnforcementTest` | forced enrolment, password confirmation, pending state, recovery codes not re-shown |
| `Auth/CreateSuperAdminCommandTest` | command creates a global-access admin; weak password rejected |
| `AccessControl/AccessResolverTest` | seeding, idempotency, global access, **Organisation A cannot reach B**, multi-org managers, multiple managers, per-org roles, manager and billing-manager restrictions, Operations Admin defaults and grantable abilities, inactive users, Gates, tenant-filtered queries, deleted organisations, uuid substitution |
| `AccessControl/RoleAssignmentTest` | scope rules, idempotency, audit, immediate revocation, no mass assignment |
| `Audit/AuditLogTest` | redaction, context capture, append-only |
| `Api/AuthenticationTest` | token login, error envelope, deactivated user, TOTP, privileged-without-2FA, throttling, profile, logout, expiry |
| `Ui/NavigationAndDashboardTest` | icons, real counts, role-specific navigation, server-side 403, tenant-safe dashboard and switcher |

Manually checked in a browser: login, platform dashboard, design-system page, dark mode, 375 px mobile layout.

### Not verified in Phase A

- **MySQL 8.** Migrations and tests have only run on SQLite. No project MySQL 8 instance is available on the
  development machine yet (decision D-01). Must be verified before Phase E.
- Password-reset e-mail delivery (mailer is `log`).
- The full TOTP confirm step in a real browser with an authenticator app (covered by Fortify; enrolment start and
  enforcement are tested here).

### Required environment variables (Phase A)

`APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `DB_*`, `SESSION_DRIVER`, `CACHE_STORE`,
`QUEUE_CONNECTION`, `REDIS_*` (production), `MAIL_*`, `WCC_ENFORCE_2FA`, `WCC_API_TOKEN_TTL`.
Google, Zoho and billing variables are defined in `.env.example` but not read by any feature yet.

### Outstanding / carried forward

- User management screens (create users, activate/deactivate) — Phase B with manager assignment.
- Role/permission editor UI for Super Admins (the data model already supports it) — Phase B.
- Open decisions D-01 … D-15 in [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md).

## Phase B — Organisation management (complete)

### Implemented

- **Organisations**: create, view, edit; activate / suspend / deactivate with a mandatory reason. A status change
  touches nothing else (no Google accounts, domains, role assignments or financial records). The organisation
  code is immutable after creation.
- **Status rules (decision D-06, implemented in `AccessResolver`)**: suspended → the organisation's own users are
  read-only plus invoices and payments; inactive → they have no access. Super Admins are unaffected.
- **List**: Livewire table with search, status filter, pagination and CSV export (same tenant-filtered query as
  the screen; formula-injection safe; audited).
- **Managers and access**: grant or remove Organisation Manager / Billing Manager / Operations Admin per
  organisation by e-mail; many managers per organisation and many organisations per manager; platform-wide
  "Organisation managers" list.
- **Dashboard users** (Super Admin): create users (they receive a set-password link — nobody sets or sees another
  person's password), optional platform role, deactivate / reactivate (revokes API tokens; cannot deactivate
  yourself or the last active Super Admin), resend password link.
- **Contacts**: general / billing / technical. Organisation Managers manage all; Billing Managers manage billing
  contacts only.
- **Domain mapping**: a Super Admin records that a domain already in Google Workspace belongs to an organisation
  (unique per domain, stored as "not checked with Google"). Nothing is changed in Google. This is what the
  Phase C import will match accounts on.
- **Organisation activity**: paginated audit view with before/after details, filterable by organisation.
- **Organisation-side pages**: Organisation Profile and Managers for the organisation currently selected.
- **Isolation**: `{organisation}` route binding resolves only inside the caller's scope (another tenant's uuid is
  a 404) and `OrganisationPolicy` authorises every action.
- **API**: `GET/POST /organisations`, `GET/PUT /organisations/{id}`, `GET/POST /organisations/{id}/managers`.
- New ability `domains.manage` (Super Admin only). 47 abilities in total.

### Migrations

`organisation_contacts`, `organisation_settings`, `organisation_domains`.

### Tests

104 tests, all passing. Added in Phase B:

| Suite | Covers |
| --- | --- |
| `Organisations/OrganisationManagementTest` | create, validation, duplicate code, update, immutable code, status change audited and non-destructive, suspended = read-only, inactive = no access and recoverable, non-admins cannot create/update/change status, **uuid substitution on every organisation route → 404**, tenant-scoped search/filter, pagination, CSV export scoping and formula escaping, activity page, organisation profile |
| `Organisations/OrganisationMembersTest` | assign manager, multiple managers, one manager for multiple organisations, new user via set-password link, Super Admin role not grantable in an organisation, only Super Admins manage members, removal, contact permissions by type, contact cannot be deleted through another organisation's URL, domain mapping rules, user create/deactivate/reactivate, self and last-admin protection, user management restricted |
| `Api/OrganisationApiTest` | authentication, scoped index, 404 for other tenants, create/update authorisation, request body cannot redirect a write, managers endpoints |

Manually checked in a browser: organisation list, detail page, map-domain dialog with a validation error,
dashboard users, managers list, activity page.

### Not done in Phase B (carried forward)

- `organisation_settings` has a table and model but no screen yet; its first consumers are billing settings (Phase E).
- Contacts can be added and removed but not edited in place.
- A mapped domain cannot be removed yet: removal needs the dependency checks and approval of Phase D.
- Organisation dashboard figures for accounts, subscription value, balance and payments arrive with Phases C and E.
- Role/permission editor screen (the data model supports it; changes currently need a database update).
- `support_requests` table and Support page — Phase G.
- Still unverified on MySQL 8 (D-01); password e-mails still go to the log mailer (D-13).

## Phase C — Google Workspace (complete against the mock provider)

### Implemented

- **Provider layer**: `WorkspaceProviderInterface`; `MockWorkspaceProvider` (no network); `GoogleWorkspaceProvider`
  (Admin SDK Directory API over HTTPS with a service-account token provider); `LoggingWorkspaceProvider` writing
  every call to `google_api_logs`. Typed exceptions separate rate limits, transient and permanent failures.
- **Account lifecycle** with pending states and queued jobs: create, edit, suspend (with sign-out), reactivate,
  soft delete (suspends, never deletes), restore, permanent delete (request with recorded checks + approval by a
  second Super Admin), retry of a failed creation. Suspended stays billable; soft-deleted stops billing from the
  effective date.
- **One-time initial password** (D-07): generated server-side, encrypted, revealed once by the creator within 24 h.
- **Jobs**: `CreateGoogleUserJob`, `UpdateGoogleUserJob`, `SuspendGoogleUserJob`, `ReactivateGoogleUserJob`,
  `SoftDeleteGoogleUserJob`, `RestoreGoogleUserJob`, `PermanentlyDeleteGoogleUserJob`, `ImportGoogleUsersJob`,
  `SyncGoogleAccountsJob`, `ImportGoogleDomainsJob`, `ReconcileGoogleAccountsJob` — retries with backoff, timeouts,
  rate-limit release, uniqueness, idempotent operations, failures recorded on the account/history/audit log.
- **Import and synchronisation**: full paginated import, assignment by mapped domain, Unassigned queue with
  explicit Super Admin assignment, status reconciliation, missing-account flagging, domain and OU sync, sync
  reports, manual triggers, scheduler entries (15 min / 30 min / 6 h / daily).
- **Screens**: email accounts (all / suspended / deleted / unassigned), create, edit, account detail with history
  and all actions, Google Workspace overview, synchronisation, Google integration status with connection test,
  integration logs. Mock mode is labelled on every relevant screen.
- **API**: `/accounts` (list, create, show, update, suspend, reactivate, soft-delete, restore, permanent-delete,
  history), `/integrations/status`, `/integrations/google/test`.
- **Isolation**: tenant-scoped `{account}` binding (foreign uuid → 404), `GoogleAccountPolicy` on every action,
  organisation and domain re-resolved server-side on create.

### Migrations

`integration_settings`, `google_organisational_units`, `google_accounts`, `google_account_history`,
`google_api_logs`, `google_sync_logs`, `account_deletion_requests`, `account_secrets`.

### Tests

151 tests, all passing. Added in Phase C (47 tests):

| Suite | Covers |
| --- | --- |
| `GoogleWorkspace/AccountLifecycleTest` | create via mock, one-time password (encrypted, creator only, once, expiry, never logged), duplicate address locally and in Google, validation, update with forbidden fields ignored, suspend/reactivate, soft delete leaves the Google user, restore, permanent deletion (checks, no self-approval, second admin, row kept), operation refuses without approval, rejected request, API failure → `creation_failed` → retry, timeout retried, rate-limit release, exhausted retries, duplicate job execution, Google-success/local-failure reconciliation, stuck operations re-queued |
| `GoogleWorkspace/DirectoryImportTest` | **600 users over two pages**, assignment by domain, unmatched users unassigned, idempotent re-import, ownership unchanged when the address moves domain, status drift, missing flagged not deleted, failed listing recorded and rethrown, domain sync, explicit assignment, sync screen restricted and queues jobs |
| `GoogleWorkspace/AccountIsolationTest` | **Organisation A cannot see or touch Organisation B's accounts** on any web or API route, scoped list and filters, locked list preset, no creation in another organisation or on its domain, Billing Managers have no mailbox access, managers cannot restore / permanently delete / assign / open integrations, suspended and inactive organisations, API two-admin deletion |
| `GoogleWorkspace/GoogleProviderTest` | the test suite can never reach live Google, pagination parameters, create payload, suspend / sign-out / delete / undelete requests, domain and OU mapping, HTTP error mapping, connection failures, missing key file, integration screen never shows credentials |

Manually checked in a browser (SQLite demo data, mock provider): manager's account list (own organisation only),
create form, creation end to end, account page with history and the password-reveal prompt.

### Not done / not verified in Phase C

- **No live Google call has ever been made.** Domain and OU URL paths and the `PATCH`/`DELETE` verbs are to be
  confirmed in the first controlled live test.
- **Notifications** (creation failed, suspended, deletion requested…) are not sent yet — Phase G. Failures are
  visible on the account page, the Synchronisation screen and the integration log.
- No "undelete from Google" screen (the provider method exists).
- Google Vault / retention checks are attested by the requester, not automated.
- Billing eligibility on creation/reactivation is only "the organisation is active" until Phase E.
- The app has not been exercised through a browser against the remote MariaDB database (no user exists there yet).
- Mailbox operations need a running queue worker (`php artisan queue:work --queue=google,default`).

## Phase D — Domain management (complete against the mock providers)

### Implemented

- **Add-domain workflow**: manager request → duplicate checks (local, in-progress, already in Google) → Super
  Admin approval or rejection (never the requester) → Google DNS TXT verification value → guided DNS instructions
  → optional public DNS check → Google verification → secondary domain added to Workspace → activated.
- **Removal workflow**: Super Admin request with attested alias/group checks → blocked while any mailbox uses the
  domain → approval by a different Super Admin with typed confirmation → removed from Google; row retired, not deleted.
- **Providers**: `DomainVerificationProviderInterface` with a mock and a Google Site Verification client;
  `DnsResolver` (faked in tests).
- **Jobs**: `ProcessDomainRequestJob`, `VerifyGoogleDomainJob`, `ActivateGoogleDomainJob`, `RemoveGoogleDomainJob`
  — retries, backoff, rate-limit release, uniqueness, idempotent steps, failures recorded and retryable.
- **Screens**: domains list and detail, request form, requests list, pending approvals, domain verification,
  request detail with DNS record, checks and history.
- **API**: `GET /domains`, `GET/POST /domains/requests`, `POST /domains/requests/{id}/approve|reject|verify`.
- **Isolation**: tenant-scoped `{domain}` and `{domainRequest}` bindings and `DomainRequestPolicy`.

### Migrations

`domain_requests`, `domain_verification_checks`, `domain_history`.

### Tests

168 tests, all passing. `Domains/DomainWorkflowTest` (17 tests) covers: submit (nothing changes in Google);
duplicate and invalid domains; another organisation cannot claim an in-progress or owned domain; only a Super Admin
other than the requester can approve; a Super Admin cannot approve their own request; reject and cancel; DNS check
and verification through to activation and first mailbox; activation impossible without authority or verification;
assigned Operations Admin can process but not approve; **cross-organisation isolation on every route**; failed
step recorded and retried; transient failure retried without duplicates; removal blocked by mailboxes and requiring
two Super Admins; removal re-checks at execution; API roles and isolation; Google verification request payloads;
tests can never use live verification or real DNS.

Manually checked in a browser (SQLite demo data): request form, submitted request page, domains list.

### Not done / not verified in Phase D

- No live Google call. Whether Site Verification by this account is enough for Workspace to treat the new
  secondary domain as verified must be confirmed in the first live test.
- No notifications to Super Admins or requesters (Phase G).
- No MX checks or DNS automation (Phase 3). Alias and group dependencies are attested, not checked.
- A domain's organisation or type cannot be changed after activation.

## Phase E — Billing (complete)

### Implemented

- **Pricing**: platform default, organisation price, mailbox override; effective-dated, never overwritten,
  history and audit; no backdating into an invoiced period.
- **Billable days** tracked per mailbox and organisation, driven by create / import / assign / restore /
  soft-delete; suspension does not stop billing.
- **Calculation**: daily proration (or `full_month`), price changes split lines, per-line GST with CGST/SGST/IGST
  split, integer paise throughout.
- **Invoices**: one per organisation per period (DB-enforced), drafts → issue with gap-free numbering →
  immutable snapshot; recalculation of drafts; void; credit notes; adjustments.
- **Payments**: verified-only recording, idempotent on external id, FIFO allocation, partial payments,
  overpayment credit applied to later invoices.
- **Jobs and schedule**: `GenerateBillingCycleJob`, `CalculateOrganisationInvoiceJob`; monthly on the 1st for the
  previous month; per-organisation failures isolated and recorded on the cycle.
- **Screens**: billing overview, pricing, organisation pricing, billing cycles, invoices, invoice, payments,
  outstanding. **API**: billing summary, pricing, invoices, invoice, payments.

### Migrations

`pricing_plans`, `organisation_pricing`, `account_pricing_overrides`, `pricing_history`,
`account_billing_periods`, `number_sequences`, `billing_cycles`, `invoices`, `invoice_items`,
`billing_adjustments`, `payments`, `payment_allocations`, `credit_notes`.

### Tests

193 tests, all passing. `Billing/BillingEngineTest` (25 tests): money helpers; fixed monthly billing; price
precedence; suspended remain billable; real suspension and soft deletion drive billable days; mid-cycle proration;
price change splits the line; full-month policy; GST split and rounding; organisation tax rate; **invoice
uniqueness** (service, duplicate jobs and database constraints); **immutable snapshot** after price, organisation
and mailbox changes; no backdating; consecutive numbering and draft recalculation; partial payment; overpayment
credit; idempotent reconciliation and oldest-first allocation; manual payments disabled by default; adjustments,
credit notes and voiding; billing run with failures isolated and retry without duplicates; moving a mailbox
splits the charge; **role and tenant isolation on every billing route**; Super Admin screens and cycle runs;
read-only API; overdue derivation.

The schema is migrated on the remote MariaDB database and the billing read queries were run there; invoice
generation itself has only been exercised on SQLite (tests) because the remote database has no mailboxes yet.
Manually checked in a browser: billing overview, invoices, outstanding, and pricing forbidden to a manager.

### Not done in Phase E

- No Zoho Books sync, online payment or automatic payment records (Phase F). With manual payments off by
  default, **no invoice can be marked paid in production until Zoho is connected.**
- No PDF (the invoice page prints); no invoice/overdue notifications (Phase G); no dashboard charts.
- GST rules are the proposed defaults and need your accountant's confirmation (D-09).
- `CreateInvoiceItemsJob` and `SendInvoiceNotificationJob` from the brief's suggested list were not created:
  lines are written in the same transaction as the invoice, and notifications are Phase G.

## Phase F — Zoho Books (complete against the mock)

### Decisions applied first (owner, 2026-11-17)

1. GST is one 18% figure here; Zoho splits CGST/SGST/IGST. The per-organisation rate and the split were removed.
2. Super Admins record verified offline payments (bank transfer, UPI, cheque, cash); the Zoho invoice link is shown
   as the pay link.
3. Automatic billing: the monthly run issues invoices and sends them to Zoho; hand-started runs for older months
   create drafts.
4. Imported mailboxes are billed from the import date (already the behaviour).

### Implemented

- **Client layer**: `ZohoBooksClientInterface`, `ZohoBooksClient` (HTTPS, India data centre by default),
  `MockZohoBooksClient`, `LoggingZohoBooksClient` → `integration_sync_logs`.
- **OAuth 2.0**: connect / callback with state check / disconnect with revoke; encrypted refresh token; cached
  encrypted access token with refresh; data-centre host allow-list.
- **Sync**: customer find-or-create (once), invoice with our number → marked sent → link, total and balance
  stored; void mirrored; credit notes flagged; offline payments pushed; Zoho payments recorded exactly as applied;
  mismatch detection (total, balance, void).
- **Webhook** `POST /api/v1/webhooks/zoho`: shared-secret header or HMAC signature, de-duplicated, trigger-only.
- **Jobs**: `SyncInvoiceToZohoJob`, `VoidZohoInvoiceJob`, `PushPaymentToZohoJob`, `ProcessZohoWebhookJob`,
  `ReconcileZohoPaymentJob` (hourly). A Zoho failure never blocks issuing; it is recorded and retried.
- **Screens**: Integrations → Zoho Books (checks, connect, settings, test, sync now, webhook set-up, invoices needing
  attention, recent webhooks and calls); invoice page (Zoho status, pay link, PDF, offline payment form, mock-only
  "simulate online payment"); integration logs include Zoho calls.
- **API**: `POST /integrations/zoho/test`, `POST /integrations/zoho/sync`, `GET /invoices/{id}/pdf`,
  `POST /webhooks/zoho`; `/integrations/status` reports Zoho's mode.
- **Safety**: in production the real Google, domain-verification and Zoho clients are always used — nothing is
  ever simulated in production.

### Migrations

`zoho_mappings`, `webhook_events`, `integration_sync_logs`.

### Tests

208 tests, all passing. `Zoho/ZohoBooksTest` (15): tests never reach live Zoho; issuing sends the invoice with our
number, customer created once; retries and lost local records never duplicate; failures recorded and retried by
reconciliation; transient errors and rate limits; Zoho payments recorded only after reading the API (partial, full,
repeated reconciliation); mismatch and credit-note flags; offline payments pushed once and recognised; void
mirrored; webhook verification, de-duplication, trigger-only (body amounts ignored); automatic billing vs drafts;
pay link and PDF only for the organisation; Zoho screens Super Admin only; OAuth (state check, host allow-list,
encrypted refresh token, no secret in audit or page); live client request format and error mapping.
`Billing/BillingEngineTest` updated for the single 18% GST and offline payments.

Checked in a browser (SQLite demo copy, mock Zoho): automatic October run issued and synced invoices; a manager's
invoice shows "Synced with Zoho (mock)", GST 18% per line, the pay button and the PDF.

### Not done / not verified in Phase F

- **Never connected to a real Zoho Books organisation.** See "To confirm in the first live test" in ZOHO_INTEGRATION.md.
- Credit notes are not created in Zoho automatically (flagged for manual entry).
- No notifications (invoice issued, overdue, payment received) — Phase G.
- The remote database has no organisations or mailboxes yet, so no invoice has been generated there.

## Phase G — Finalisation (complete)

### Implemented

- **Notifications** (`app/Modules/Notifications`): 18 event types (mailbox created/failed/suspended/reactivated/
  deleted, permanent deletion requested, import and integration errors, domain submitted/approved/rejected/
  verified/failed, invoice issued, payment received, overdue, Zoho differences, support). Recipients come from role
  assignments in the event's organisation, so another organisation is never told; platform staff receive failures.
  In-app (header panel + Notifications page) and e-mail per user preference (Account → Notification settings).
  Integration errors are throttled to one per kind per hour. Queued on `notifications`.
- **Overdue reminders**: `SendOverdueInvoiceRemindersJob`, daily 09:00, once per invoice.
- **Support requests**: organisation users raise them (category, subject, message); Super Admins answer and change
  status; both sides notified; audited; tenant-isolated.
- **Audit Logs screen**: filters (action, organisation, user, status, dates), detail, CSV export; Ops Admins see
  technical actions only; secrets stay redacted.
- **System → Queues**: pending per queue, failed jobs with first error line, retry / discard (audited).
- **System → Health**: database, cache, queue, scheduler heartbeat, integrations, production configuration checks.
- **Settings**: configuration summary and a role-permission editor (Super Admin only, audited; Super Admin role
  locked).
- **Dashboards**: platform dashboard with real figures (accounts, unassigned, domains, pending requests, MRR,
  invoiced/received, outstanding, failures, Zoho problems) and 12-month charts; organisation dashboard with sections
  shown only for the abilities the user has.
- **Security hardening**: nonce-based Content-Security-Policy (D-15); inline event handlers replaced with Alpine.
- **Docs**: DEPLOYMENT.md finalised (workers, cron, health, go-live checklist, backups); SECURITY, schema, role
  matrix and limitations updated.

### Migrations

`support_requests`; `users.notification_preferences`; `invoices.overdue_notified_at`. New permission
`support.manage` (Super Admin). Applied to the remote database and the local demo copy.

### Tests

219 tests, all passing. `Finalisation/NotificationsTest` (5): mailbox events reach only the owning organisation's
users with the right abilities; failures reach the technical team and are throttled; domain, invoice, payment and
overdue events (once per invoice, never for paid invoices); e-mail follows preferences; users see and open only their
own notifications, and external links are not followed. `Finalisation/AdministrationTest` (6): support requests
isolated between organisations; audit screen access, filters, export, redaction; queue and health screens restricted,
discard audited; role editor (Super Admin locked, invalid permission rejected); dashboards scoped; CSP nonce changes
per request and no inline handlers.

Checked in a browser (SQLite demo copy): organisation dashboard figures, organisation switcher, support request
modal; no console errors under the CSP. Note: the in-app preview browser pauses animation frames while hidden, which
made Alpine transitions look broken during the check; they work when the pane is visible.

### Not done / not verified in Phase G

- No notification e-mail is actually delivered until a mail provider is configured (D-13).
- Google and Zoho remain unconnected (mock mode) — see their go-live checklists.
- Audit-log retention not decided (D-22).
- No load test at production scale; the ~600-mailbox size is well within single-server capacity, but has not been measured.

## After Phase G — web installer (2026-11-17)

- `app/Modules/Installer`: with no `.env` (no `APP_KEY`) and no `storage/app/installed.lock`, the application runs
  in install mode (temporary key in `storage/framework/installer.key`, encrypted file sessions, no database) and
  every page redirects to `/install`; the API answers 503.
- Four steps: requirements → application, database and optional Redis (connections tested) → Super Admin (or proof
  of an existing Super Admin when the database already has users) → SMTP (test e-mail, or skip). Install writes a
  production `.env` from `.env.example`, migrates without wiping, seeds roles, creates the Super Admin, audits
  `platform.installed` and locks the installer (404 afterwards).
- `.env` was removed from the development machine at the owner's request; `.env.example` now holds production
  defaults. Tests carry their own test-only `APP_KEY` in `phpunit.xml`; the `wcc-demo-sqlite` launch config sets
  its own local variables.
- Tests: `Installer/InstallerTest` (5) — redirects before installation, 404 after, full installation and the
  resulting `.env`, existing database requires a Super Admin and keeps its data, `.env` value quoting round-trip.
- Not verified: an installation against a real MySQL/MariaDB server through the browser, Redis (extension not
  installed locally) and a real SMTP test e-mail.

## Domain inventory and immediate sync (2026-11-17)

- **Immediate sync** (D-24): `GoogleWorkspace/Services/DirectorySyncRunner` runs a directory job inside the request
  when an administrator clicks Run now; the flash message shows the counts. Scheduled runs are unchanged (queued).
- **Domain inventory** (D-25): migration `2026_11_24_000001_create_google_domains_table`, model
  `Domains/Models/GoogleDomain`. The domain sync stores every Google domain and domain alias (`domainAliases[]`),
  links each to its organisation mapping, and flags domains that disappear (never deleted).
- **Domains → Unassigned** tab (`/domains/unassigned`, Super Admin with `domains.manage`): assign a domain to an
  organisation through `DomainMappingService::assignGoogleDomain` (audited `domain.mapped`; Google's verification
  state copied; optionally its unassigned mailboxes via `GoogleAccountService::assignOrganisation`).
  "Sync domains from Google" button on All domains and Unassigned.
- `config/queue.php` `retry_after` raised to 660 s (longer than the 600 s job timeout).
- Tests: `Domains/GoogleDomainInventoryTest` (5, including the tenancy test) and the updated immediate-sync tests in
  `GoogleWorkspace/DirectoryImportTest`.
- Not verified against live Google: the alias flattening follows the Directory API reference (Domains resource,
  DomainAlias fields `domainAliasName`, `parentDomainName`, `verified`).

## Mailbox transfer and mail errors (2026-11-18)

- **Change organisation** on a mailbox that already belongs to an organisation (Super Admin,
  `accounts.reassign_organisation`): reason required, refused while a Google operation is in flight, billing period
  of the old organisation closed the day before and a new one opened from today, audited
  `account.organisation_reassigned` in both organisations, history event `organisation_reassigned`.
  Nothing changes in Google. Same endpoint as Unassigned assignment (`accounts.assign`).
- **SMTP failures are no longer a 500**: a `TransportExceptionInterface` on a web POST is logged and the page shows
  why (`Notifications/Support/MailFailure`: unreachable server / rejected credentials / unverified sender / TLS);
  guests get a generic message without server details. SMTP timeout 20 s (`MAIL_TIMEOUT`).
- `php artisan wcc:mail-test you@example.com` sends one test e-mail and prints the mail server's answer.
- Tests: `GoogleWorkspace/AccountReassignTest` (4, including tenancy), `Auth/MailFailureTest` (6, including a
  host that intercepts SMTP and answers with its own certificate).

## Billing start, organisation and domain pages (2026-11-18)

- Invoices are calculated from `account_billing_periods`, not from `google_accounts.billable_from` (a display
  field). Editing `billable_from` in the database therefore changes nothing on an invoice.
- **Change billing start** on a mailbox (`pricing.manage` in its organisation; Super Admin by default):
  `POST /accounts/{account}/billing-start` with a date and a reason → `GoogleAccountService::changeBillingStart` →
  `BillingPeriodTracker::moveStart`. It moves the open period's `starts_on` and keeps `billable_from` in step, and is
  audited (`account.billing_start_changed`) and recorded in the history. Refused when the date is in the future,
  is before the mailbox was created in Google, overlaps an earlier period of the mailbox (another organisation),
  or when an issued invoice covers any day between the old and new date. The flash message names the completed
  months to re-run or recalculate. A skipped cycle ("Nothing to bill") is re-run from Billing cycles.
- Organisation page: "Billable email accounts" count and billable accounts per domain (for users with
  `accounts.view` there); domain names link to the domain page.
- Domain page: list of mailboxes on the domain (scoped by `accounts.view`), linking to each mailbox.
- Tests: `Billing/BillingStartTest` (5, including tenancy).

## Invoice numbering and Zoho customer linking (2026-11-18)

- Invoice numbers are `{YY}/TWSD-{SEQ}` (`26/TWSD-001`), from `BILLING_INVOICE_NUMBER_FORMAT` /
  `BILLING_INVOICE_NUMBER_PADDING` (replaces `BILLING_INVOICE_PREFIX`). `{YY}` is the calendar year of the issue date;
  the locked, gap-free sequence restarts at 1 each year (`number_sequences` row `invoice:26/TWSD-`). Issued numbers
  are never renumbered. The Zoho PDF file name replaces `/` with `-`.
- **Zoho customers** (Integrations → Zoho Customers, `integrations.zoho.manage`): lists the customers in Zoho Books
  (live read, 50 per page, search). **Link** connects one to an organisation (resolved in the user's
  `organisations.update` scope), so invoices go to that customer instead of a new duplicate. Optionally copies the
  legal name, GSTIN, PAN (from Zoho's `pan_no`, else characters 3–12 of the GSTIN), billing address, billing e-mail
  and primary contact into the organisation through `OrganisationService::update` (audited); invalid values are
  skipped and the organisation's name and code are never changed. **Update details from Zoho** repeats the copy.
- A linked mapping has `zoho_mappings.origin = 'zoho'` (migration `2026_11_25_000001`): its Zoho contact is never
  updated from here. One Zoho customer links to one organisation; moving an organisation that already has invoices
  in Zoho to another customer is refused. Audited `zoho.customer_linked`.
- Client: `listContacts` (`GET /contacts?contact_type=customer&filter_by=Status.All&sort_column=contact_name`) and
  `getContact` (`GET /contacts/{id}`), checked against the Zoho Books v3 Contacts reference; mock and logging
  clients updated. Not yet run against the live Zoho organisation.
- Tests: `Zoho/ZohoCustomerLinkTest` (6, including access), numbering in `Billing/BillingEngineTest`.

## GST calculated like Zoho Books (2026-11-18)

- Reported: ₹39.48 + single 18% GST (7.1064 → 7.11) = ₹46.59 here; Zoho charged CGST 3.55 + SGST 3.55 = ₹46.58, so
  after the customer paid the invoice stayed "partially paid" for ₹0.01.
- `Billing/Support/GstState`: GST state codes, place-of-supply decision and per-line split. `InvoiceCalculator` now
  stores `tax_treatment` `intra_state` (CGST + SGST, each half the rate rounded per line) or `inter_state` (IGST),
  with `cgst_minor`/`sgst_minor`/`igst_minor` filled. New `BILLING_SUPPLIER_STATE_CODE` (unset = same state).
  Invoice page and API show CGST/SGST or IGST; older invoices keep the single "GST" figure. The mock Zoho uses the
  same rule.
- Safety net (`BILLING_ROUNDING_TOLERANCE_PAISE`, default 100): Zoho totals within ₹1 of ours are not flagged; when Zoho
  shows the invoice paid, an open remainder that small is closed by an automatic credit note
  (`InvoiceService::writeOffRounding`, audited `invoice.rounding_written_off`, not sent to Zoho); if Zoho's total was
  higher, the extra paise of the Zoho payment are not left as credit (`rounding_minor` in the payment audit row).
  The existing ₹0.01 invoice is closed by the next hourly Zoho sync after deployment.
- Tests: `Zoho/ZohoGstRoundingTest` (4), GST tests in `Billing/BillingEngineTest`. Decision D-28.

## WhatsApp, mailbox security, reminders and non-payment suspension (2026-11-19)

- **Schedule:** Zoho reconciliation and Google domain import now every 30 minutes (Google mailboxes stay every 15,
  reconciliation every 30). Automatic billing on the 1st at 03:00 was already in place. All of it needs
  `schedule:run` in cron.
- **WhatsApp** (`app/Modules/WhatsApp`): Integrations → WhatsApp (`integrations.whatsapp.manage`, Super Admin) saves
  the app key and auth key encrypted in `integration_settings.secrets` (never shown, logged or audited — only which
  key changed), switches sending on, picks events, sends a test. `WhatsAppClient` posts the multipart form
  (`appkey`, `authkey`, `to`, `message`); mock in tests/local, logging decorator (masked number) to
  `integration_sync_logs`. `Notifier::send` hands every WhatsApp-eligible organisation event to `WhatsAppNotifier`:
  the organisation's contact phone and the users holding a role in that organisation (D-30), queued
  `SendWhatsAppMessageJob` with retries. Users set their number and per-event WhatsApp switch under Account →
  Notifications; Super Admins can enter it when creating a user (`users.phone`).
- **Mailbox password reset** (`accounts.reset_password`: Super Admin, Operations Admin, organisation manager):
  generated (created in the job, revealed once through the existing password vault) or typed (travels only in the
  encrypted job payload, `ShouldBeEncrypted`), with "Ask user to change their password when they sign in".
- **Turn off 2-Step Verification** (`accounts.manage_security`, same roles): `POST
  /users/{key}/twoStepVerification/turnOff` (scope already granted), reason required, audited.
- **Payment reminders** (`PaymentReminderService`, daily 09:00): due −2, −1, 0, +1, +2, each once
  (`invoice_reminders`), stating the automatic suspension date; nothing is sent once paid. Replaces the single
  overdue reminder.
- **Non-payment suspension** (`PaymentSuspensionService`, hourly and on payment): from due + 3, every active mailbox
  is suspended with `suspension_type = non_payment`; `payment_suspensions` / `payment_suspension_accounts` record
  each mailbox's state before (active → suspended, or already suspended by hand → left alone). When nothing is
  overdue any more, only the mailboxes it suspended are reactivated. Managers and Operations Admins cannot reactivate
  them (`accounts.reactivate_payment_suspended`, Super Admin). One summary notification per organisation instead of
  one per mailbox. Decision D-29.
- Migration `2026_11_26_000001` adds the tables/columns and grants the new permissions to existing roles.
- Tests: `WhatsApp/WhatsAppIntegrationTest` (6), `GoogleWorkspace/PasswordAndTwoStepTest` (5),
  `Billing/PaymentSuspensionTest` (6).

## Time zone and key-file path (2026-11-19)

- `APP_TIMEZONE` was ignored (`config/app.php` hard-coded UTC). The database keeps storing UTC (`app.timezone`,
  unchanged so existing rows do not shift); times are now shown in `APP_TIMEZONE` (`app.display_timezone`, default
  the billing timezone) through the Carbon macro `inDisplayTimezone()` in every view, the audit CSV and the
  organisation export. Relative times ("5 minutes ago") were already correct.
- `GOOGLE_SERVICE_ACCOUNT_JSON`: a relative path is resolved from the application folder, so web requests (cwd
  `public/`) and the queue worker (cwd = application folder) read the same file. Use an absolute path.

## Software licensing removed (2026-11-21)

- The MusicDesk / LabelDesk licensing module (storefront API, license server, admin screens) was removed at the
  owner's request: licensing is handled inside the MusicDesk application. Migration `2026_11_30_000001` drops its
  tables, permissions and `organisations.currency` on databases that had them (any licensing data there is deleted).

## Project status

All seven phases are built. Remaining work before production is configuration and the first live tests: mail
provider, Google service account, Zoho Books connection, production server (Redis, Supervisor, cron, HTTPS) and the
go-live checklist in [DEPLOYMENT.md](DEPLOYMENT.md).
