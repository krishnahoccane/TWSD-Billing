# API reference

Base path `/api/v1`. JSON only. Resources are identified by UUID; numeric ids are never exposed.

## Conventions

- **Authentication:** `Authorization: Bearer <token>` (Laravel Sanctum). Tokens expire after `WCC_API_TOKEN_TTL`
  minutes (default 480).
- **Authorisation:** every endpoint applies the same policies as the web UI. Organisation ids in a payload are never
  trusted; access is derived from the token owner's role assignments.
- **Rate limits:** 120 requests/minute per user; login 5/minute per email+IP.
- **Errors:** always

```json
{ "message": "Human readable message.", "errors": { "field": ["…"] }, "request_id": "0193…" }
```

`errors` is present for validation failures (422). `request_id` matches the `X-Request-Id` response header and the
audit log. Status codes: 401 unauthenticated, 403 forbidden, 404 not found (also used when a resource belongs to
another organisation), 422 validation, 429 throttled.

## Implemented

### POST /login

| Field | Required | Notes |
| --- | --- | --- |
| `email` | yes | |
| `password` | yes | |
| `code` | if the user has 2FA | 6-digit TOTP code |
| `device_name` | no | Token label |

`200`

```json
{
  "data": {
    "token": "1|…",
    "token_type": "Bearer",
    "expires_at": "2026-11-17T18:00:00+00:00",
    "user": { "id": "<uuid>", "name": "…", "email": "…", "two_factor_enabled": false, "global_access": false,
              "platform_roles": [], "organisations": [{ "id": "<uuid>", "name": "…", "code": "…", "roles": ["Organisation Manager"] }] }
  }
}
```

`422` bad credentials, deactivated account (same message), or missing/invalid `code`.
`403` privileged account that has not enrolled in two-factor authentication.

### POST /logout
Revokes the current token. `204`.

### GET /profile
Returns the `user` object shown above under `data`.

### GET /organisations
Organisations the caller may see. Query: `search`, `status` (`active|suspended|inactive`), `per_page` (1–100,
default 25), `page`. Standard Laravel pagination (`data`, `links`, `meta`).

### POST /organisations — Super Admin
Body: `name`*, `code`* (letters, digits, hyphens; unique; immutable afterwards), `country`* (ISO-2),
`legal_name`, `pan`, `gstin`, `primary_contact_name`, `primary_contact_email`, `contact_phone`, `billing_email`,
`registered_address`, `billing_address`, `state`, `city`, `postal_code`, `notes`. `201` with the organisation.

### GET /organisations/{id}
`{id}` is the organisation uuid. `404` if it does not exist **or** is not one of the caller's organisations.

```json
{ "data": { "id": "<uuid>", "name": "…", "legal_name": "…", "code": "ACME", "status": "active", "pan": null,
            "gstin": null, "primary_contact_name": null, "primary_contact_email": null, "contact_phone": null,
            "billing_email": null, "registered_address": null, "billing_address": null, "country": "IN",
            "state": null, "city": null, "postal_code": null, "created_at": "…", "updated_at": "…" } }
```

`notes` is included only for callers who may edit the organisation.

### PUT /organisations/{id} — Super Admin
Same fields as create, except `code` (ignored). Status is not changed through this endpoint.

### GET /organisations/{id}/managers
Everyone with a role in the organisation:
`[{ "user": { "id", "name", "email", "is_active" }, "role", "role_label", "assigned_at" }]`.

### POST /organisations/{id}/managers — Super Admin
Body: `email`*, `role`* (`organisation_manager|billing_manager|operations_admin`), `name` (required only when
no dashboard user has that e-mail; the new user is e-mailed a set-password link). `201` when granted, `200`
when the user already held the role.

### Accounts

`{id}` is the account uuid; `404` if it does not exist or is outside the caller's organisations. Mutating calls
answer **`202`**: the change is recorded and queued, and `status` / `pending_operation` show progress.

| Endpoint | Body | Notes |
| --- | --- | --- |
| `GET /accounts` | query: `search`, `status`, `organisation` (uuid), `per_page` | Paginated, tenant-scoped |
| `POST /accounts` | `organisation`*, `domain`* (uuids), `username`*, `first_name`*, `last_name`*, `recovery_email`, `recovery_phone` (E.164) | Organisation and domain are re-resolved inside the caller's scope |
| `GET /accounts/{id}` | | |
| `PUT /accounts/{id}` | `first_name`*, `last_name`*, `display_name`, `recovery_email`, `recovery_phone` | Address, domain and organisation cannot be changed |
| `POST /accounts/{id}/suspend` | `reason`* | Stays billable |
| `POST /accounts/{id}/reactivate` | | |
| `POST /accounts/{id}/soft-delete` | `reason`*, `confirm_email`* | Suspends in Google; does not delete |
| `POST /accounts/{id}/restore` | | Super Admin |
| `POST /accounts/{id}/permanent-delete` | 1st call: `reason`*, `ownership_verified`, `retention_checked`, `vault_checked`, `dependencies_checked` (all true). 2nd call by a **different** Super Admin: `approve: true`, `confirm_email`* | Two-person rule |
| `GET /accounts/{id}/history` | | Lifecycle events |

Account object: `id`, `organisation_id` (uuid), `primary_email`, `first_name`, `last_name`, `display_name`,
`recovery_email`, `recovery_phone`, `organisational_unit_path`, `status`, `pending_operation`, `is_suspended`,
`billing_status`, `billable_from`, `deletion_effective_date`, `last_error`, `google_created_at`, `last_synced_at`,
`created_at`. Google's internal user id and passwords are never returned.

### Domains

| Endpoint | Who | Notes |
| --- | --- | --- |
| `GET /domains` | `domains.view` | Tenant-scoped; `{id, organisation_id, domain_name, domain_type, verification_status, is_primary, status}` |
| `GET /domains/requests` | `domains.view` | Optional `status` filter |
| `POST /domains/requests` | `domains.request` | `organisation`* (uuid, resolved in the caller's scope), `domain_name`*, `note`. `201` |
| `POST /domains/requests/{id}/approve` | `domains.approve` (removals: `domains.remove` + `confirm_domain`) | Not the requester. Returns the request with the DNS record under `verification` |
| `POST /domains/requests/{id}/reject` | same | `reason`* |
| `POST /domains/requests/{id}/verify` | `domains.process` | `202`; Google checks DNS, then the domain is added and activated |

Request object: `id`, `organisation_id`, `type` (`add|remove`), `domain_name`, `status`,
`verification` (`{method, record_type: "TXT", host: "@", value}` once issued), `last_error`, `requested_at`,
`decided_at`, `verified_at`, `completed_at`.

### Billing (read-only)

Amounts are decimal strings with a separate `currency`. Drafts are returned only to users who may manage invoices.

| Endpoint | Who | Returns |
| --- | --- | --- |
| `GET /billing/summary` | `billing.view` | MRR, billable / suspended / soft-deleted counts, invoiced, received, outstanding, overdue — for the caller's organisations |
| `GET /billing/pricing` | `pricing.view` | Current organisation prices |
| `GET /invoices` | `invoices.view` | Paginated invoices |
| `GET /invoices/{id}` | `invoices.view` | Invoice with `customer` snapshot and `items` |
| `GET /payments` | `payments.view` | Paginated payments |

`GET /invoices/{id}/pdf` — the PDF rendered by Zoho Books (`application/pdf`); `404` until the invoice is in Zoho.

### Zoho Books — Super Admin

| Endpoint | Notes |
| --- | --- |
| `POST /integrations/zoho/test` | `{ ok, mode, message }` — lists organisations and checks the configured id |
| `POST /integrations/zoho/sync` | `202`; queues the reconciliation |

### Webhook

`POST /webhooks/zoho` — no bearer token. Verified by header `X-WCC-Webhook-Token` (= `ZOHO_WEBHOOK_SECRET`) or a valid
`X-Zoho-Webhook-Signature`. `200` received / already received, `401` bad signature, `503` not configured. The
payment is re-read from Zoho before anything is recorded.

### Integrations — Super Admin

`GET /integrations/status` → `{ google_workspace: { mode: "mock"|"live", configured, last_test_status, last_tested_at }, zoho_books: { mode: "mock"|"live", connected } }`.
`POST /integrations/google/test` → `{ ok, mode, message }` (reads one user through the active provider).

## Planned (same conventions)

| Phase | Endpoints |
| --- | --- |
