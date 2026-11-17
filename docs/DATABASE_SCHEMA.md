# Database schema

MySQL 8 / InnoDB, utf8mb4 is the specified target. The remote database currently in use is **MariaDB 10.11**
(InnoDB, tables created as utf8mb4 although the database default is latin1) — see decision D-01.
All changes through Laravel migrations.

Conventions

- `id` bigint primary key (internal only) + `uuid` (unique, exposed externally) on every exposed resource.
- Foreign keys always declared. Default `ON DELETE RESTRICT`; cascade only for pure join/configuration rows.
- Money: `decimal(12,2)` (or integer paise) — never float. Currency stored alongside amounts.
- Timestamps in UTC; billing periods interpreted in `BILLING_TIMEZONE`.

## Implemented (Phase A)

### users
`id`, `uuid` (unique), `name`, `email` (unique), `email_verified_at`, `password` (hashed), `is_active` (indexed),
`two_factor_secret`, `two_factor_recovery_codes` (both encrypted), `two_factor_confirmed_at`, `remember_token`,
`last_login_at`, `last_login_ip`, timestamps.
No `organisation_id` — organisation membership lives in `organisation_user_roles`.

### organisations
`id`, `uuid` (unique), `name` (indexed), `legal_name`, `code` (unique), `pan`, `gstin`, `primary_contact_name`,
`primary_contact_email`, `contact_phone`, `billing_email`, `registered_address`, `billing_address`, `country`
(default `IN`), `state`, `city`, `postal_code`, `status` (`active|suspended|inactive`, indexed), `notes`,
`created_by` → users, `updated_by` → users, timestamps, `deleted_at`.

### roles
`id`, `name` (unique), `label`, `description`, `scope` (`platform|organisation|both`), `is_system`, timestamps.

### permissions
`id`, `name` (unique), `label`, `group` (indexed), `is_sensitive`, timestamps.

### role_permissions
PK (`role_id`, `permission_id`); both cascade on delete (configuration, not history).

### user_roles — platform-level assignments
`id`, `user_id`, `role_id`, `assigned_by`, timestamps. Unique (`user_id`, `role_id`). Restrict on delete.

### organisation_user_roles — organisation-scoped assignments
`id`, `organisation_id`, `user_id`, `role_id`, `assigned_by`, timestamps.
Unique (`organisation_id`, `user_id`, `role_id`); index (`user_id`, `organisation_id`). Restrict on delete.

### audit_logs — append-only
`id`, `uuid` (unique), `user_id` (nullable), `user_email`, `user_role` (snapshots), `organisation_id` (nullable),
`action`, `resource_type`, `resource_id`, `previous_data` (json), `new_data` (json), `ip_address`, `user_agent`,
`request_id` (indexed), `status` (`success|failure|denied`), `created_at`.
Indexes: (`organisation_id`, `created_at`), (`resource_type`, `resource_id`), (`action`, `created_at`),
(`user_id`, `created_at`). Restrict on delete; no `updated_at`.

### organisation_contacts (Phase B)
`id`, `uuid` (unique), `organisation_id`, `type` (`general|billing|technical`), `name`, `email`, `phone`,
`designation`, `created_by`, timestamps. Index (`organisation_id`, `type`). Hard-deleted on removal; the removed
values are preserved in `audit_logs`.

### organisation_settings (Phase B)
`id`, `organisation_id`, `key`, `value` (json), `updated_by`, timestamps. Unique (`organisation_id`, `key`).

### organisation_domains (Phase B)
`id`, `uuid` (unique), `organisation_id`, `domain_name` (**unique** — one organisation per domain),
`google_domain_id`, `domain_type` (`primary|secondary|alias`), `verification_status` (default `unverified` = not
yet checked against Google), `mx_status`, `is_primary`, `status` (indexed), `created_by`, timestamps.

### google_accounts (Phase C)
`id`, `uuid` (unique), `organisation_id` (nullable = unassigned; never changed by sync), `domain_id`,
`google_user_id` (**unique**, Google's immutable id), `primary_email` (indexed), `email_unique_key` (**unique**,
equals the address while it is in use and null after permanent deletion, so an address can be reissued while the
old row is kept), `first_name`, `last_name`, `display_name`, `recovery_email`, `recovery_phone`,
`organisational_unit_path`, `account_status` (indexed), `is_suspended`, `billing_status` (indexed),
`monthly_price` (display cache), `billable_from`, `suspension_reason`, `deletion_reason`,
`deletion_effective_date`, `pending_operation` (indexed), `previous_status`, `pending_since`, `last_error`,
`idempotency_key` (unique), `google_created_at`, `last_synced_at`, `missing_from_google_at`,
`permanently_deleted_at`, `created_by`, `updated_by`, `deleted_at` (lifecycle soft-deletion time — rows are never
hidden or removed), timestamps. Index (`organisation_id`, `account_status`).

### google_account_history (Phase C) — append-only
`google_account_id`, `organisation_id`, `user_id`, `event`, `from_status`, `to_status`, `details` (json),
`request_id`, `created_at`. Restrict on delete. Phase E derives billable periods from these events.

### account_deletion_requests (Phase C)
`uuid`, `google_account_id`, `organisation_id`, `requested_by`, `reason`, `checks` (json), `status`
(`pending|approved|rejected|executed|failed`), `decided_by`, `decided_at`, `decision_note`, `executed_at`,
`api_response` (json), timestamps.

### account_secrets (Phase C)
One-time initial passwords: `google_account_id`, `user_id` (who may view), `payload` (encrypted), `expires_at`,
`viewed_at`. Row deleted on reveal or expiry.

### google_api_logs, google_sync_logs, google_organisational_units, integration_settings (Phase C)
Call log (operation, target, status, error, duration, request id — no payloads); sync run reports with counters
and details; cached OU list; per-provider settings with last connection-test result and an encrypted `secrets`
column reserved for Phase F tokens.

### domain_requests (Phase D)
`uuid`, `organisation_id`, `organisation_domain_id` (set on completion / for removals), `type` (`add|remove`),
`domain_name` (indexed), `domain_type`, `status` (indexed), `requested_by`, `request_note`, `checks` (json, removal
attestations), `decided_by`, `decided_at`, `decision_note`, `verification_method`, `verification_token` (public DNS
value), `verified_by`, `verified_at`, `completed_at`, `failed_step`, `last_error`, `pending_since`, timestamps.
"One open request per domain" is enforced in the service.

### domain_verification_checks (Phase D)
`domain_request_id`, `type` (`dns_txt|google_verify|google_domain`), `result`, `details` (json), `checked_by`, `created_at`.

### domain_history (Phase D) — append-only
`organisation_id`, `organisation_domain_id`, `domain_request_id`, `user_id`, `domain_name`, `event`, `details`,
`request_id`, `created_at`. Restrict on delete.

### Billing (Phase E)
All amounts are integer paise in `*_minor` columns; tax rates are basis points. Nothing cascades on delete.

- `pricing_plans`, `organisation_pricing`, `account_pricing_overrides` — effective-dated prices
  (`monthly_price_minor`, `currency`, `effective_from`, `effective_until`, `status`).
- `pricing_history` — append-only record of every price change.
- `account_billing_periods` — `google_account_id`, `organisation_id`, `starts_on`, `ends_on` (inclusive; null = open).
- `number_sequences` — locked counters for invoice and credit-note numbers.
- `billing_cycles` — **unique (`organisation_id`, `period_start`, `period_end`)**; `status`, `error`.
- `invoices` — **unique `billing_cycle_id`**, **unique `invoice_number`**; status, period, issue/due dates,
  subtotal, adjustments, taxable, tax rate/treatment, CGST/SGST/IGST, tax, total, paid, credited, balance,
  `organisation_snapshot` (json), issue/void metadata.
- `invoice_items` — snapshot lines: account address/name/status, period, days, days in period, unit price, price
  source, amount, tax, total.
- `billing_adjustments` — credit/debit, amount, taxable, status, `invoice_id` once applied.
- `payments` — amount, unallocated, `paid_on`, source, **unique `external_id`**; `payment_allocations` —
  **unique (`payment_id`, `invoice_id`)**.
- `credit_notes` — **unique `credit_note_number`**, invoice, amount, reason.

### Zoho Books (Phase F)
- `zoho_mappings` — `mappable_type`/`mappable_id` (**unique**), `zoho_entity` (`contact|invoice|payment`), `zoho_id`,
  `zoho_organisation_id` (**unique with entity and id**), `sync_status` (`pending|synced|failed|mismatch|needs_attention`),
  `zoho_number`, `zoho_status`, `zoho_url`, `zoho_total_minor`, `zoho_balance_minor`, `last_error`, `attempts`, `last_synced_at`.
- `webhook_events` — **unique (`provider`, `event_id`)** (`event_id` = SHA-256 of the body), `event_type`, minimal `payload`, `status`, `error`.
- `integration_sync_logs` — one row per Zoho call: operation, target, status, error, duration, request id.

### Finalisation (Phase G)
- `support_requests` — `uuid`, `organisation_id` (no cascade), `created_by`, `category`, `subject`, `message`,
  `status` (`open|in_progress|resolved|closed`), `response`, `responded_by`, `responded_at`.
- `users.notification_preferences` — JSON; per notification type, whether e-mail is wanted (null = defaults).
- `invoices.overdue_notified_at` — set when the one overdue reminder for that invoice has been sent.

### Framework tables
`personal_access_tokens` (Sanctum), `notifications` (in-app notifications), `sessions`, `password_reset_tokens`,
`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.

## Deletion and archival policy

| Data | Policy |
| --- | --- |
| Users | Never hard-deleted through the application; deactivated with `is_active`. |
| Organisations | Soft delete only; status change for suspend/deactivate. Nothing cascades. |
| Role assignments | Hard-deleted on revoke; the change is preserved in `audit_logs`. |
| Google accounts | Lifecycle status + soft delete; row and `google_user_id` retained after permanent deletion. |
| Google account history, API logs | Never cascade-deleted. |
| Invoices, items, payments, allocations, adjustments, credit notes | Never deleted; corrections by void / credit note. |
| Audit logs | Append-only; retention period still to be agreed (D-22, proposed 7 years). |
| Support requests, notifications | Never deleted by the application. |
