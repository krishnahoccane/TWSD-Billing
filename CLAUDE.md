# Workspace Control Center — permanent project instructions

Multi-organisation portal over **one** Google Workspace customer (~25 organisations, ~600 mailboxes).
Organisations manage only their own mailboxes/domains/billing; Super Admins control everything.
Laravel is the system of record for ownership, inventory, pricing and billing calculations.
Zoho Books is the accounting/invoice system. Full spec: `docs/PRODUCT_REQUIREMENTS.md`.

## Stack (do not change without asking)

- PHP 8.2+ (target 8.3), Laravel 12, Redis queues (database driver locally).
- Database: spec says MySQL 8 / InnoDB; the remote database in use is **MariaDB 10.11** (`DB_CONNECTION=mariadb`).
  Keep migrations portable across MySQL 8, MariaDB 10.11 and SQLite (tests).
- Blade + Livewire 3 + Alpine (bundled with Livewire) + Tailwind 4 + Vite + Lucide (`<x-ui.icon>`).
- **No** React/Vue/Next/SPA. Server-rendered Blade, enhanced with Livewire.
- Auth: Laravel Fortify (web, TOTP 2FA) + Sanctum (API tokens). No self-registration.
- Tests: PHPUnit, SQLite in-memory. Pin packages to Laravel-12-compatible versions (Laravel 13 exists — do not upgrade).

## Commands

```bash
php artisan test                      # full suite — must be green before a phase is called done
php artisan migrate:fresh --seed      # schema + roles/permissions (no users are seeded)
php artisan wcc:create-super-admin    # first Super Admin (interactive password)
php artisan db:seed --class=LocalDevelopmentSeeder   # fictional demo data, local env only, needs WCC_DEV_PASSWORD
npm run build                         # public/build IS committed (cPanel has no Node) — rebuild and commit after view/CSS changes
php artisan queue:work --queue=google,zoho,billing,notifications,default   # integrations, billing, notifications are queued
vendor/bin/pint                       # code style
```

No `.env` = install mode: every page redirects to the web installer (`/install`, `app/Modules/Installer`), which writes a
production `.env` and `storage/app/installed.lock`. Tests use the `APP_KEY` in `phpunit.xml` and the empty `.env.testing`, and never need `.env`.
When a `.env` exists it may point at the REMOTE database. Never seed demo data or run destructive commands (`migrate:fresh`, `db:wipe`)
against it. For UI checks use the `wcc-demo-sqlite` launch config (port 8001) or prefix commands with
`DB_CONNECTION=sqlite DB_DATABASE=<abs path>/database/database.sqlite`, and always pass `--no-interaction`.
Tests always use in-memory SQLite and the mock Workspace provider.

On Windows PowerShell, quote composer constraints (`"vendor/pkg:^1.0"`) — a bare `^` is swallowed.

## Architecture

Modular monolith under `app/Modules/<Module>/{Models,Services,Enums,Http,Jobs,Events,Listeners,Exceptions,Support}`.
Controllers and Livewire components stay thin: validate, authorise, call a service. Business logic lives in services.
External systems sit behind interfaces (`WorkspaceProviderInterface`, later `DomainRegistrarInterface`,
`DnsProviderInterface`, `ServerProviderInterface`) with a mock implementation for local/test use.
One billing engine for all current and future services.

Key pieces:

- `app/Modules/AccessControl/Enums/Ability.php` — every permission; source of truth for the `permissions` table.
- `app/Modules/AccessControl/Services/AccessResolver.php` — the only implementation of "may user do X in org Y".
- `app/Modules/AccessControl/Services/RoleAssignmentService.php` — the only way to grant/revoke roles (audited).
- `app/Modules/AccessControl/Concerns/BelongsToOrganisation.php` — trait for every organisation-owned model.
- `app/Modules/Audit/Services/AuditLogger.php` — the only way to write audit rows (redacts secrets).
- `app/Modules/Organisations/Services/OrganisationContext.php` — current working organisation (server-validated).
- `app/Modules/Organisations/OrganisationsServiceProvider.php` — tenant-scoped `{organisation}` route binding (foreign uuid → 404) + `OrganisationPolicy`.
- `app/Modules/Organisations/Services/OrganisationQuery.php` — the one listing query shared by UI, export and API.
- `app/Modules/GoogleWorkspace/Contracts/WorkspaceProviderInterface.php` — the only door to Google; mock vs live is bound in `GoogleWorkspaceServiceProvider` (always mock in tests).
- `app/Modules/GoogleWorkspace/Services/GoogleAccountService.php` (request side) and `GoogleAccountOperations.php` (job side, idempotent) — every mailbox change goes through these two.
- `app/Modules/GoogleWorkspace/Services/GoogleDirectoryImporter.php` — import/sync; never changes an existing account's organisation.
- `app/Modules/Domains/Services/DomainRequestService.php` (request side) and `DomainRequestProcessor.php` (job side) — domain add/remove workflow; ownership comes only from Google verification.
- `app/Modules/Billing/Services/` — `PricingService` (all price changes), `InvoiceCalculator` (proration + GST), `InvoiceService` (generate/issue/void/credit), `PaymentService::recordVerified` (the only way an invoice becomes paid), `BillingPeriodTracker` (billable days).
- `app/Modules/Billing/Support/Money.php` — integer-paise helpers; use these, never floats.
- `app/Modules/Zoho/Services/ZohoSync.php` — everything that talks to Zoho Books; `ZohoConnection` holds OAuth. Payments enter only via `PaymentService` after being read from Zoho's API.
- In production the real Google / Zoho clients are always bound — never a mock.
- `app/Modules/Notifications/NotificationEvents.php` — the wording and recipients of every notification; send through it (`Notifier` resolves recipients by role in the event's organisation — never notify by hand).
- `app/Http/Middleware/SecurityHeaders.php` — nonce CSP. Inline `<script>` needs `nonce="{{ Vite::cspNonce() }}"`; no inline `on*=` handlers (use Alpine).
- `resources/views/components/ui/*` — the design system. `/design-system` shows all of it (non-production).
- `config/navigation.php` — sidebars; items appear automatically once their route exists.

## Non-negotiable rules

Security and tenancy
1. Never trust an organisation id/uuid from the browser. Resolve allowed organisations from
   `$user->organisationScope($ability)` / `OrganisationContext`.
2. Every organisation-owned model uses `BelongsToOrganisation`; list queries start from `Model::visibleTo($user, $ability)`
   **and** single-record access goes through a policy/Gate. Both layers, always.
3. No `Gate::before` bypass. Super Admin works through the explicit `platform.global_access` ability.
4. Authorise server-side on every action (controller, Livewire action, API, job dispatch). Hidden UI is not a control.
5. Expose UUIDs only (`HasUuid`), never numeric ids, in URLs and API payloads.
6. Never put credentials, tokens, service-account keys or secrets in Blade, Livewire public properties, logs or audit rows.
   Integration tokens are encrypted at rest. Never commit `.env` or key files.
7. Every new organisation-scoped feature ships with a test proving Organisation A cannot reach Organisation B.

Data integrity
8. Money is never a float: integer minor units (paise) or `decimal` columns + `bcmath`. Financial writes run in DB transactions.
9. Invoices are immutable snapshots; never recalculate or overwrite issued invoice amounts. One invoice per organisation per billing period (DB unique constraint).
10. Never mark an invoice paid without verification against Zoho's records.
11. No cascading deletes on financial, audit or Google-account history tables. Deactivating an organisation deletes nothing.
12. All administrative operations are audited through `AuditLogger`. Audit rows are append-only.

Google / Zoho
13. Soft deletion never permanently deletes a Google user. Permanent deletion needs Super Admin approval.
14. Never overwrite local organisation ownership from Google data. Unmatched accounts go to the Unassigned queue.
8a. Compare date columns with `whereDate()` (SQLite stores dates with a time part; a plain `where` on a date string silently mismatches in tests).
8b. `assertSessionHasNoErrors()` passes on a 500 — also assert the redirect or the resulting database state.
14a. Job classes must not use `readonly` properties (Laravel's queue serialisation writes to them).
15. External API calls run in queued jobs with retries, backoff, timeouts, idempotency keys and logged failures. Never swallow integration errors.
16. **Never call live Google or Zoho APIs in local development or tests without explicit authorisation from the user.** Use the mock providers.
17. Verify request payloads against current official Google/Zoho documentation before implementing — do not rely on memory.

Honesty
18. No dummy functionality presented as done. If a credential is missing, build the abstraction + mock + tests and record it in
    `docs/DEVELOPMENT_PROGRESS.md` and `docs/KNOWN_LIMITATIONS.md` as mock/not connected.
19. Dashboards show real data only. No hardcoded sample numbers outside `/design-system`.
20. When a business rule is ambiguous, write a proposed default into `docs/KNOWN_LIMITATIONS.md` (Open decisions) instead of silently inventing one.

## Working method

Phases: A Foundation → B Organisations → C Google Workspace → D Domains → E Billing → F Zoho → G Finalisation.
One phase at a time. After each: migrate, run tests, fix failures, check authorisation, update
`docs/DEVELOPMENT_PROGRESS.md` (and any affected doc), summarise files/migrations/tests/outstanding work.
Do not start the next phase with critical failures open. Do not remove existing functionality without checking dependants.

UI: reuse `x-ui.*` components; do not hand-write duplicate markup. Neutral palette, one accent, light + dark, responsive.
Destructive actions use `<x-ui.confirm-modal>` (reason and/or typed confirmation).
