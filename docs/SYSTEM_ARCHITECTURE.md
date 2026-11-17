# System architecture

Modular monolith on Laravel 12. One deployable, one database, queue workers for external calls.

## Directory structure

```
app/
├── Console/Commands/            artisan commands (wcc:create-super-admin)
├── Http/
│   ├── Controllers/             cross-module controllers (DashboardController)
│   └── Middleware/              AssignRequestId, SecurityHeaders, EnsureAccountIsActive, EnsureTwoFactorEnrolled
├── Livewire/                    Livewire components (thin; call module services)
├── Models/User.php
├── Modules/
│   ├── AccessControl/           Ability, RoleName, RoleScope, AccessResolver, RoleAssignmentService, traits   [A]
│   ├── Authentication/          API auth controllers, token issuer, auth audit listener                        [A]
│   ├── Audit/                   AuditLog, AuditLogger                                                          [A]
│   ├── Organisations/           Organisation, OrganisationContext, PlatformOverview                            [A schema, B features]
│   ├── GoogleWorkspace/         provider interface, Google + mock providers, accounts, sync jobs               [C]
│   ├── Domains/                 requests, verification, activation                                             [D]
│   ├── Billing/                 pricing, cycles, calculation, adjustments                                      [E]
│   ├── Invoices/                invoices, items, numbering, PDF                                                [E]
│   ├── Payments/                payments, allocations, reconciliation                                          [E/F]
│   ├── Zoho/                    OAuth, client, mappings, webhooks                                              [F]
│   ├── Notifications/           notification classes and preferences                                           [G]
│   └── Integrations/            shared integration settings, logs, future provider interfaces                  [C+]
├── Providers/                   AppServiceProvider, FortifyServiceProvider
└── Support/                     HasUuid, NavigationBuilder
config/wcc.php                   application settings (security, google, zoho, billing)
config/navigation.php            sidebar definitions
resources/views/components/ui    design system
resources/views/components/layouts
```

Each module owns its `Models`, `Services`, `Enums`, `Http` (controllers, requests, resources), `Jobs`, `Events`,
`Listeners`, `Exceptions`, `Policies`. Empty module folders are placeholders for later phases.

## Layers

```
Browser ── Blade / Livewire ──┐
API client ── /api/v1 ────────┤→ Form Request (validate) → Policy/Gate (authorise) → Service (business logic)
Scheduler / Queue ── Jobs ────┘                                                        │
                                                                                       ├→ Eloquent (tenant-scoped)
                                                                                       ├→ AuditLogger
                                                                                       └→ Provider interface → Google / Zoho / Mock
```

- Controllers, Livewire components and jobs are thin and never contain business rules.
- Services never read organisation identity from the request; they receive a resolved `Organisation` model.
- External APIs are reached only through interfaces bound in a service provider, so the mock and the real
  implementation are interchangeable and future providers (ResellerClub, DNS, servers) plug in the same way.

## Multi-tenancy

Single database, shared schema, `organisation_id` on every organisation-owned table. Isolation is enforced twice:

1. **Query layer** — `Model::visibleTo($user, $ability)` constrains lists to `OrganisationScope` (all, or an id list
   derived from role assignments).
2. **Policy layer** — every single-record action checks `AccessResolver::can($user, $ability, $organisation)`.

The "current organisation" (`OrganisationContext`) is a UI convenience stored in the session as a uuid and
re-validated on every read. See [ROLE_PERMISSION_MATRIX.md](ROLE_PERMISSION_MATRIX.md).

## Request lifecycle additions

`AssignRequestId` (uuid v7 → log context, audit rows, `X-Request-Id` header) → standard Laravel stack →
`auth` → `active` → `two-factor.enrolled` → controller. `SecurityHeaders` decorates every response.

## Background processing (from Phase C)

Redis queues with Supervisor-managed workers; separate queues for `google`, `zoho`, `billing`, `notifications`.
Every external job defines `tries`, `backoff`, `timeout`, a unique/idempotency key, rate-limit handling, and writes
to an API log table. Failed jobs stay in `failed_jobs` and are surfaced in the admin UI — never discarded.

## Package choices

| Need | Package | Note |
| --- | --- | --- |
| Web auth, 2FA | laravel/fortify 1.42 | Headless; our Blade views. Registration and passkeys disabled. |
| API tokens | laravel/sanctum 4.4 | Expiring personal access tokens. |
| Interactivity | livewire/livewire 3.8 | Bundles Alpine + focus/anchor plugins. |
| Icons | mallardduck/blade-lucide-icons | Wrapped by `<x-ui.icon>`. |
| Roles/permissions | *none (custom)* | The brief prescribes the exact tables and organisation scoping. |
| Google (Phase C) | google/apiclient + google/apiclient-services | Trim unused services via composer cleanup. |
| PDF (Phase E) | to be chosen (dompdf vs Zoho-generated PDF) | Zoho is the authoritative invoice; prefer its PDF. |

Compatibility notes: Laravel 13 is current — constraints are pinned to `^12.0`. PHP 8.2 is the minimum for
Laravel 12; target 8.3 in production.
