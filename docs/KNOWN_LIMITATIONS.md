# Known limitations and open decisions

## Current limitations

- Phases A–G are complete. Google Workspace and domain verification run in **mock mode only**; Zoho Books runs against a mock until it is connected; the real client has never called Zoho.
- The Google client has never called Google. Domain/OU URL paths and some HTTP verbs need confirming in the first live test.
- The remote database is MariaDB 10.11, not MySQL 8 (D-01). The schema migrated cleanly; the application has not yet been used through a browser against it.
- The remote database is reached over the public internet on port 3306 and is slow per query from the development machine. Restrict access by IP / TLS before real data is stored, and rotate the database password, which was shared in chat.
- Notifications are in-app and e-mail; e-mail goes through the `log` mailer until an SMTP/API provider is configured (D-13), so nobody receives notification e-mail yet.
- Mailbox operations and notifications are queued: without a running queue worker they stay pending.
- There is no screen to undelete a permanently deleted user from Google inside the ~20-day window.
- The domain workflow is unproven against Google: it must be confirmed live that Site Verification by the service account is accepted by Workspace for a newly added secondary domain.
- Domain alias/group dependencies and Vault/retention checks are attested by the admin, not checked automatically.
- The Super Admin account on the remote database was created with a 9-character password at the owner's request, below the 12-character policy the application otherwise enforces. Change it from Account → Security.
- There is no `.env` on the development machine until the web installer is run (`/install`); the installer always writes a production `.env`. For local development edit it afterwards (`APP_ENV=local`, `APP_DEBUG=true`, `SESSION_SECURE_COOKIE=false`). The `wcc-demo-sqlite` launch config does not need `.env`.
- Installer: only MySQL/MariaDB are offered; Redis needs the PHP redis extension (phpredis); the database user needs rights to create tables. If `.env` is lost but the lock file remains, the application stops with an error rather than reopening the installer — restore `.env` from backup.
- Credit notes issued here are not created in Zoho Books automatically; the invoice is flagged so it can be done by hand.
- Zoho details still to confirm live: list filters, invoice PDF via `accept=pdf`, item/tax ids on lines, negative (credit) lines, `gst_treatment` values, tax rounding, webhook signature format.
- Contacts can be added and removed but not edited; `organisation_settings` has no screen yet.
- New users set their password through an e-mailed link that expires after 60 minutes; with the `log` mailer the link only appears in `storage/logs`. A Super Admin can resend it.
- The Content-Security-Policy allows `'unsafe-eval'` because Alpine (bundled with Livewire 3) needs it (D-15). Inline scripts need the per-request nonce; inline event handlers are not allowed.
- Support requests have one response field (latest answer), not a threaded conversation, and no attachments.
- Audit-log retention is not enforced yet (D-22).
- The organisation switcher dropdown no longer fades in (the transition was removed while checking it in the in-app preview browser, which pauses animation frames when hidden). Purely cosmetic.
- Audit rows are append-only at the Eloquent layer. A direct query-builder `update`/`delete` is not blocked by the
  application; production should also revoke `UPDATE`/`DELETE` on `audit_logs` from the app's DB user.
- API tokens currently carry all of the user's abilities (no per-token scopes).
- Password reset e-mails use the `log` mailer until SMTP is configured.

## Open decisions

Each has a **proposed default** that will be implemented unless you decide otherwise. None is silently assumed.

| # | Decision | Proposed default | Needed by |
| --- | --- | --- | --- |
| D-01 | **Development database.** Spec requires MySQL 8. The dev machine has XAMPP MariaDB 10.4 (stopped) and a MySQL 8.0.35 that belongs to the "Local" WordPress app. Neither was touched. | Install a dedicated MySQL 8.x for this project (or approve Docker). Until then, SQLite for UI work and tests. MariaDB 10.4 is not recommended: it differs from MySQL 8 in JSON and locking behaviour that billing relies on. | **Changed in Phase C:** you supplied a remote database, which is MariaDB 10.11.19. Schema created there with the `mariadb` driver. Decide whether MariaDB 10.11 is the production target (then the spec's "MySQL 8" should be amended) or whether a MySQL 8 server will be provided before billing. |
| D-02 | **PHP version/extensions.** Local PHP is 8.2.4 without `intl`, `sodium`, `redis`. | Works for now. Enable `intl` (currency formatting) before Phase E; production on PHP 8.3 with `intl` + `redis`. | Phase E |
| D-03 | **Operations Admin reach.** Spec says "assigned operational resources". | Ops Admins act only in organisations they are assigned to; platform-level technical abilities (sync, logs, queues) are global. A Super Admin can grant `platform.global_access` to the role to make it global. | Decided by default in Phase A |
| D-04 | **Redis locally.** Not installed. | `database` queue/cache/session drivers locally; Redis + Supervisor in production. | Phase C |
| D-05 | **Soft deletion by managers.** Spec lists "request account soft deletion" for managers and a Stage-1 soft-delete workflow. | A manager's request executes Stage 1 immediately (suspend, sign out, mark soft-deleted, stop billing) because it is reversible. Restore and permanent deletion are Super Admin only; permanent deletion needs approval by a second Super Admin. | **Implemented in Phase C** as proposed |
| D-06 | **Suspended / inactive organisations.** Effect on managers and billing is unspecified. | Suspended: managers get read-only access plus invoices/payments; billing continues. Inactive: managers cannot access; no new billing cycles; nothing is deleted in Google. | **Implemented in Phase B** as proposed |
| D-18 | **Billing start for imported mailboxes.** | An imported mailbox is billable from the day it is imported (or assigned, if it was unassigned), not from its Google creation date. Earlier periods are outside this system. | **Decided 2026-11-17:** from the import date |
| D-19 | **Soft-deletion effective date.** | Always the day of the request (billing timezone). A future or back-dated effective date is not offered. | **Implemented in Phase E** as proposed |
| D-07 | **Initial mailbox password delivery.** "Approved one-time secure delivery mechanism" is not defined. | Password shown once to the creating manager via a single-use, expiring reveal (encrypted, purged after view or 24 h); never e-mailed or stored in plaintext; change forced at first login. | **Implemented in Phase C** as proposed |
| D-08 | **Payment channel.** "Configured payment channels" is not defined. | Pay through the Zoho Books hosted invoice payment link; no card data touches this application. | **Decided 2026-11-17:** the Zoho invoice link (`invoice_url`) is the pay link, plus offline payments verified by Super Admins |
| D-09 | **GST treatment.** CGST+SGST vs IGST depends on supplier and customer state; HSN/SAC code and rounding are not specified. | Tax rate and split configurable per organisation from GSTIN state code; exact rules to be confirmed with your accountant before Phase E. | **Decided 2026-11-17:** single GST 18% here; Zoho Books splits CGST/SGST/IGST |
| D-10 | **Proration arithmetic.** | Daily rate = monthly price ÷ days in that calendar month; each line rounded half-up to the paisa; start and end dates inclusive. | **Implemented in Phase E** as proposed |
| D-11 | **Billing in advance or arrears.** | Arrears: on the 1st, invoice the previous calendar month (so proration uses actual usage). | **Decided 2026-11-17:** automatic monthly billing in arrears, issued without review |
| D-12 | **Google authentication model.** Direct admin-role assignment to the service account vs domain-wide delegation. | Evaluate against current Google documentation at the start of Phase C; prefer the least-privileged option that supports users, domains and OUs. | **Open** — both models are supported by configuration; choose at go-live |
| D-13 | **Mail provider** for password resets and notifications. | Any SMTP/API provider you already use; needed before real users are onboarded. | **Open** — before go-live |
| D-14 | **Passkeys.** Fortify 1.42 ships passkey support (pulls `laravel/passkeys`). | Leave disabled; TOTP only, as specified. | — |
| D-15 | **Content-Security-Policy.** Livewire/Alpine need `unsafe-eval` unless the CSP build of Alpine is used. | Add a nonce-based CSP in Phase G. | **Implemented in Phase G**: nonce-based CSP with `'unsafe-eval'` kept for Alpine. Removing it needs the Alpine CSP build and rewriting inline Alpine expressions — not planned. |
| D-22 | **Audit-log retention.** | Keep audit rows for 7 years (aligned with Indian GST record-keeping), then archive off-database; no automatic purge until you confirm. | Before go-live |
| D-23 | **Who receives notification e-mail by default.** | Failures, suspensions, deletions, domain decisions, invoices, payments and overdue reminders e-mail by default; routine events (account created, reactivated, domain verified) are in-app only. Each user can change this. | **Implemented in Phase G** as proposed |
| D-24 | **On-demand synchronisation without the queue.** Rule 15 puts external calls in queued jobs. | "Run now" (Synchronisation page, "Sync domains from Google") runs the same job class inside the web request: one run per type at a time (cache lock), up to 600 s, failures recorded in the sync log and notified. Scheduled runs and every mailbox/domain change still use the queue, so the cron worker is still required. | **Decided 2026-11-17** by the owner |
| D-25 | **Domains found in Google with no organisation.** | Stored in `google_domains` and listed under Domains → Unassigned; never assigned automatically. A Super Admin assigns each one (optionally with its unassigned mailboxes, billed from that day); mailboxes already owned by an organisation are never moved. Google's primary domain is listed like any other — assign it to the organisation that owns it, or leave it unassigned. | Implemented 2026-11-17 |
| D-26 | **Invoice number year.** Owner asked for `YY/TWSD-001`; GST requires numbers unique within a financial year. | `YY` = calendar year of the issue date and the sequence restarts each January. Because the year is part of the number it stays unique within any financial year. If you prefer the financial year (restart each 1 April), that needs a small change. | Proposed 2026-11-18 — confirm |
| D-27 | **Customer details for a linked Zoho customer.** | Zoho Books is the source: legal name, GSTIN, PAN, address and contact are copied from Zoho when linking (or on "Update details from Zoho") and are never pushed back. Later edits made here are not sent to Zoho for that customer. | Implemented 2026-11-18 |
| D-17 | **Organisation code** changes. | Immutable after creation (stable reference for invoices, exports and Zoho). | Implemented in Phase B |
| D-20 | **Manual payments.** Rule: an invoice is paid only from authoritative (Zoho) records. | Off by default (`BILLING_ALLOW_MANUAL_PAYMENTS=false`). Decide whether Super Admins may record verified bank transfers by hand before/alongside Zoho. | **Decided 2026-11-17:** allowed for Super Admins, pushed to Zoho |
| D-21 | **Draft review.** | The monthly run creates drafts; a Super Admin reviews and issues each one. No auto-issue. | **Changed 2026-11-17:** the monthly run auto-issues; hand-started runs for older months create drafts |
| D-16 | **Account price storage.** `google_accounts.monthly_price` vs `account_pricing_overrides`. | Treat `monthly_price` as a cached display value; overrides + organisation pricing + platform default are authoritative, resolved by effective date. | **Implemented in Phase E**: `monthly_price` is unused; prices come from the pricing tables |
