# Security

Status key: ✅ implemented and tested · 🟡 implemented, needs production configuration · ⏳ planned.

## Authentication

| Control | Status | Detail |
| --- | --- | --- |
| Password hashing | ✅ | bcrypt (`BCRYPT_ROUNDS=12`), `hashed` cast. |
| Password policy | ✅ | 12+ characters, mixed case, number, symbol; breached-password check in production. |
| No self-registration | ✅ | Users are created by Super Admins / `wcc:create-super-admin`. |
| Login throttling | ✅ | 5 attempts/minute per email+IP (web and API); 2FA challenge throttled. |
| No account enumeration | ✅ | Unknown, wrong-password and deactivated accounts return the same error; dummy hash for unknown users. |
| Session rotation | ✅ | Session id regenerated on login; invalidated on logout and on deactivation. |
| TOTP 2FA | ✅ | Fortify; secrets and recovery codes encrypted; recovery codes shown once. |
| 2FA mandatory for privileged users | ✅ | `two-factor.enrolled` middleware (web and API). `WCC_ENFORCE_2FA=true`. |
| Password confirmation for sensitive account changes | ✅ | Enabling/disabling 2FA and regenerating codes. |
| Secure cookies | 🟡 | Set `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, HTTPS only. |
| API tokens | ✅ | Hashed at rest, expiring, revoked on logout and on deactivation. |

## Authorisation and tenancy

| Control | Status | Detail |
| --- | --- | --- |
| RBAC with organisation scope | ✅ | See [ROLE_PERMISSION_MATRIX.md](ROLE_PERMISSION_MATRIX.md). |
| No implicit super-user | ✅ | Explicit `platform.global_access`; no `Gate::before`. |
| Tenant filtering at query layer | ✅ | `visibleTo()` / `OrganisationScope`. |
| Policy check on every record action | ✅ (pattern) | Applied per module as features land; each module ships A-vs-B tests. |
| IDOR protection | ✅ | UUIDs only; lookups always inside the user's scope; other tenants' resources answer 404/403. |
| Organisation ids from the browser are never trusted | ✅ | `OrganisationContext` re-validates on each read. |
| Mass-assignment protection | ✅ | `is_active`, roles and ownership columns are not fillable; strict mode throws. |
| Two-person approval for permanent deletion / domain removal | ✅ | Permanent deletion needs a second Super Admin (Phase C); domain approval optionally needs a second approver (`domains.require_second_approver`, Phase D). |
| Role permission changes | ✅ | Super Admin only, audited; the Super Admin role is not editable. |

## Application

| Control | Status | Detail |
| --- | --- | --- |
| CSRF | ✅ | Laravel middleware on all web forms and Livewire. |
| XSS | ✅ | Blade escaping; toasts use `x-text`; the only raw output is the server-generated 2FA QR SVG. |
| SQL injection | ✅ | Eloquent / query builder bindings only; no string-built SQL. |
| Security headers | ✅ | `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS in production. |
| Content-Security-Policy | ✅ | Per-request script nonce (Vite, Livewire, theme script); `frame-ancestors 'none'`, `object-src 'none'`, `form-action 'self'`, `base-uri 'self'`; no inline event handlers. `'unsafe-eval'` is still required by Alpine as bundled with Livewire 3 (D-15). |
| Input validation | ✅ | Form Requests / Fortify actions. |
| Request tracing | ✅ | Server-generated `X-Request-Id` in logs and audit rows. |
| File upload validation | ⏳ | No uploads exist yet. |
| Webhook verification and idempotency | ✅ | Zoho webhook: shared-secret header or HMAC signature, de-duplicated by body hash, trigger-only (amounts re-read from Zoho's API). |
| Notification links | ✅ | Opening a notification only follows links inside the application; each user sees only their own. |
| Notification recipients | ✅ | Resolved from role assignments in the event's organisation — another organisation is never told. |

## Secrets

- Never committed: `.env`, service-account JSON, OAuth tokens. `.gitignore` covers `.env*` and `storage/*.key`.
- Google service-account key: a file outside the web root, referenced by path; never loaded into views or Livewire state.
- Zoho access/refresh tokens: stored encrypted (`encrypted` cast) in `integration_settings` (Phase F).
- `APP_KEY` protects 2FA secrets and integration tokens — back it up securely; rotating it requires re-encryption.

## Audit

Central `AuditLogger`; rows are append-only (model blocks update/delete). Keys containing `password`, `secret`,
`token`, `private_key`, `recovery_code`, `authorization`, `credential`, `api_key`, `otp`, `service_account` are
redacted at any depth. Production hardening: revoke `UPDATE`/`DELETE` on `audit_logs` from the application DB user.

The **Audit Logs** screen (Phase G) filters by action, organisation, user, status and date and exports CSV.
Super Admins (`audit.view`) see everything; Operations Admins (`audit.view_technical`) see only technical actions
(accounts, domains, Workspace, integrations, Zoho, queues) — never pricing, invoices or payments.

## Operational

- Database backups: daily full + binary logs for point-in-time recovery, encrypted, restore tested quarterly
  (see [DEPLOYMENT.md](DEPLOYMENT.md)).
- Least privilege for Google: dedicated service account and a custom admin role limited to users, domains and OUs
  where Google supports it; domain-wide delegation only if required (D-12).
- Dependencies: `composer audit` and `npm audit` in CI.
