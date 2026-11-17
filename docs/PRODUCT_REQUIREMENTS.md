# Product requirements

Condensed from the master project brief (2026-11-16). Where this file and the brief differ, the brief wins;
ambiguities are tracked in [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md).

## Background

One Google Workspace customer and Admin Console is shared by about 25 organisations, 600 mailboxes and many
domains, run by one central infrastructure team. Organisation managers cannot be given Google Admin access because
it exposes other organisations. Workspace Control Center is the portal that lets each organisation manage only its
own accounts, request domains, see charges, get invoices and pay — with every Google operation executed by the
application backend.

## Core business rules

1. One Google Workspace customer, shared by ~25 organisations.
2. Every Google account maps to exactly one organisation in this application.
3. An organisation may own several domains and have several managers.
4. A manager may manage several organisations, only when explicitly assigned.
5. Managers can never see another organisation's information.
6. Every mailbox has a fixed monthly price.
7. Suspended mailboxes remain billable by default.
8. Soft-deleted mailboxes stop being billable from their effective deletion date.
9. Adding a domain requires Super Admin approval.
10. Permanently deleting a Google account requires Super Admin approval.
11. Zoho Books is the accounting and invoice system.
12. Laravel is the system of record for ownership, mailbox inventory, pricing and billing calculations.
13. Every administrative operation is audited.

## Roles

Super Admin (everything), Operations Admin (technical operations; financial and destructive abilities separately
grantable), Organisation Manager (own organisations: accounts, domain requests, billing view, payments),
Billing Manager (financial access only). Full matrix: [ROLE_PERMISSION_MATRIX.md](ROLE_PERMISSION_MATRIX.md).

## Modules

| Module | Summary | Phase |
| --- | --- | --- |
| Organisations | CRUD, status (active/suspended/inactive), contacts, managers, per-organisation dashboard, search/filter/export. Deactivation never deletes Google accounts or financial records. | B |
| Google Workspace | `WorkspaceProviderInterface` with Google and mock implementations. Create/update/suspend/reactivate/sign-out/soft-delete/restore/permanent-delete users; list domains and OUs. Idempotent creation, reconciliation, secure one-time initial password. | C |
| Synchronisation | Paginated full import (`customer=my_customer`, all pages), unmatched accounts into an Unassigned queue, scheduled reconciliation (15 min / 30 min / 6 h / daily), never overwrite ownership from Google. | C |
| Domains | Request → Super Admin approval → Google verification (guided DNS TXT) → add secondary domain → activate. Removal needs approval and dependency checks. | D |
| Billing | Fixed monthly price per mailbox; precedence account override → organisation price → platform default; effective-dated prices; daily proration (configurable); GST; immutable invoice snapshots; one invoice per organisation per period; adjustments and credit notes. | E |
| Zoho Books | OAuth 2.0 with encrypted tokens, customer mapping, invoice push, payment sync by webhook with scheduled reconciliation fallback, idempotent webhooks, partial payments. Invoices are never marked paid from a redirect. | F |
| Dashboards | Super Admin, Operations Admin, Organisation Manager and Billing Manager dashboards with charts. | B–G |
| Audit | Central append-only log: who, role, organisation, action, resource, before/after, IP, user agent, request id, status. | A (core), G (UI) |
| Notifications | In-app and e-mail for account, domain, invoice, payment and integration events; queued. | G |

## Future (design for, do not build)

Phase 2 ResellerClub domain registration (`DomainRegistrarInterface`), Phase 3 DNS management
(`DnsProviderInterface`), Phase 4 server management (`ServerProviderInterface`). All future services bill through
the same billing engine and appear on one consolidated organisation invoice.

## Acceptance

- 600 accounts importable and reconcilable; 25 organisations independently manageable.
- No cross-organisation access through UI, API or identifier substitution — proven by automated tests.
- All operations auditable; Google and Zoho calls have retry and error handling.
- The test lists in section 25 of the brief (organisation, Google, domain, billing, security) all pass.

## Hard restrictions

No hardcoded account data or credentials; no credentials in the frontend; no bypassing Laravel authorisation; no
trusting submitted organisation ids; soft delete never permanently deletes; no duplicate invoices; no rewriting
historical invoices; no "paid" without authoritative verification; no swallowed integration failures; no fake
integration presented as production-ready; destructive actions need confirmation; no raw SQL string
concatenation; no live Google/Zoho changes during local development without explicit authorisation.
