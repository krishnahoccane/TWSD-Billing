# Software licensing (LabelDesk via musicdesk.tech)

**Status (2026-11-20, phase 1 "licensing core" + payment lifecycle: create customer, confirm payment, refund):** built and tested locally (mock-free: no external service is
involved except the storefront's own webhook URL). Not yet used by musicdesk.tech or a real LabelDesk install.

## Who owns what

| Concern | Owner |
| --- | --- |
| Customers (= organisations), plans, prices per currency, licenses, domains/activations, releases, license server | **This application** (system of record) |
| Checkout, payment (Razorpay), invoices, receipts, refunds, tax on software sales | **The storefront** (musicdesk.tech) |
| Workspace mailbox billing (INR, GST, Zoho Books) | This application — unchanged and separate |

The storefront takes the payment, issues its invoice, then **reports the paid order** here (`POST /orders`); this
application creates or changes the license. LabelDesk sales are never sent to Zoho Books.

## Admin portal (Software Licensing in the sidebar)

| Screen | Who |
| --- | --- |
| Licenses (list, search by number/customer/domain, status filter) | Super Admin: all · organisation manager / billing manager: own organisation, read-only |
| License page: details, domains & installations (online/offline, version, last check-in), orders, history; Reveal key (audited) | same; changes (edit plan/extra domains/dates/auto-renew, suspend, reinstate, revoke with typed confirmation, add/deactivate domain) Super Admin only |
| Add License (manual sale: bank transfer, partner, replacement) | Super Admin |
| Products & Plans: product, plans (included domains, features, featured, on sale), a price per kind × currency, releases | Super Admin |
| Storefronts: create, webhook URL, new API key / webhook secret (shown once), link an existing organisation as a customer, license-server URLs and signing public key, recent webhooks | Super Admin |
| Organisation page → "Software licenses" card | anyone with `licensing.view` in it |

Permissions: `licensing.view` (Super Admin, organisation manager, billing manager), `licensing.manage` and
`licensing.storefronts.manage` (Super Admin only).

## Setup

1. Licensing → Storefronts → **Add a storefront** (`musicdesk`, webhook URL `https://musicdesk.tech/webhooks/wcc`).
   Copy `WCC_BILLING_KEY` and `WCC_BILLING_WEBHOOK_SECRET` from the page into musicdesk.tech's `.env` — they are
   shown once (stored as a hash / encrypted).
2. Licensing → Products & Plans → add product `labeldesk` (key prefix `LDSK`), plans `starter` (1 domain),
   `business` (3), `enterprise` (10), and prices: `annual`, `lifetime`, `extra_domain`, `extra_domain_lifetime`,
   `updates_renewal` in each currency you sell in (USD, EUR, GBP, INR…). Publish releases.
3. Give LabelDesk the license-server URL and the Ed25519 public key shown on the Storefronts page.
4. Existing Workspace clients who buy LabelDesk: "Link an existing organisation as customer" so the storefront can
   use their `cus_…` id instead of creating a duplicate.

## Storefront API

Base URL `https://billing.twsd.me/api/v1/storefront` (MusicDesk `WCC_BILLING_URL`). JSON, snake_case.

- `Authorization: Bearer sk_live_…` on every call; a key sees only its own products and customers.
- Customer-scoped calls send `X-Customer-Id: cus_…`; another customer's license is `404`. `X-Acting-User: usr_…`
  is recorded in the history and audit log.
- `POST /orders` and the activation POSTs need `Idempotency-Key` (kept 24 h; same key + same body = same response
  with `Idempotent-Replayed: true`; same key + different body = `422 idempotency_key_reused`).
- Money: `{ "amount": 129900, "currency": "USD" }` (minor units). Dates: ISO 8601 UTC. Ids: `cus_`, `lic_`, `act_`,
  `ord_`, `rel_`, `price_`, `plan_`, `evt_` + UUID.
- Errors: `{ "error": { "code", "message", "field" }, "request_id" }` with 400/401/403/404/409/422/429.
- Rate limit 600/min per key.

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/catalog/{product}?currency=USD` | plans, features, included domains, prices (all currencies without `?currency`) |
| GET | `/products/{product}/releases?channel=stable\|beta\|all` | |
| POST ✱ | `/customers` | create a customer at sign-up, before any order — see below |
| GET / PATCH | `/customers/{cus}` | PATCH: company, billing_email, country, currency, address{line1, city, state, postal_code} |
| POST ✱ | `/orders` | a **paid** order — see below |
| POST | `/orders/{ord}/confirm-payment` | a `pending` (bank transfer) order was paid — see below |
| POST | `/orders/{ord}/refund` | the storefront refunded the order — see below |
| GET | `/licenses?limit=25&cursor=lic_…&include=activations` | `next_cursor` |
| GET / PATCH | `/licenses/{lic}` | PATCH `{ auto_renew }` |
| POST | `/licenses/{lic}/reveal-key` | `{ key }` — audited; never cache it |
| GET | `/licenses/{lic}/events` | timeline |
| POST | `/licenses/{lic}/downloads` | `{ version }` → `201 { url, expires_at, version, file_name, size_bytes, sha256 }` — see below |
| GET / POST ✱ | `/licenses/{lic}/activations` | POST `{ domain, environment? }` pre-approves (`pending`) |
| POST ✱ | `/licenses/{lic}/activations/{act}/move` | `{ new_domain }`, max 3 per 30 days |
| DELETE | `/licenses/{lic}/activations/{act}` | frees the slot |
| POST | `/licenses/{lic}/activations/{act}/check-in-request` | install sees `check_in_requested: true` |

### `POST /orders` (after Razorpay confirms payment)

```json
{ "kind": "new_license",
  "customer": { "id": null, "name": "Nova Reyes", "email": "nova@lowtide.co", "company": "Lowtide Ltd", "country": "GB", "vat_id": "GB284119307" },
  "product": "labeldesk", "plan_code": "business", "billing_type": "annual", "extra_domains": 1,
  "primary_domain": "portal.lowtide.co", "auto_renew": true,
  "amount": { "amount": 142800, "currency": "USD" }, "amount_usd": 142800,
  "payment_provider": "razorpay", "payment_reference": "pay_N3xyz", "payment_status": "paid", "invoice_number": "MD-2026-0042" }
```

`kind`: `new_license` · `renewal` (+1 year) · `updates_renewal` (lifetime: +1 year of updates) · `extra_domains`
(`extra_domains: n`) · `change_plan` (`plan_code`) · `convert_lifetime`. All but `new_license` need `license_id` and
`X-Customer-Id`. A new customer becomes an organisation here (currency = the order's), unless it was created
beforehand with `POST /customers`. `payment_status: "pending"` (bank transfer) creates a `pending` license usable for
14 days; `POST /orders/{ord}/confirm-payment` activates it. Response `201`: the order plus the license (with
activations). **This application does not check the amount against the price list** — the storefront charged it.
An order is all-or-nothing (e.g. a taken `primary_domain` → `409 domain_in_use`, nothing created).

`amount_usd` (optional, minor units) is the same amount in US cents at the storefront's exchange rate, for revenue
reports; for a USD order it is filled in automatically. Responses return it as `amount_usd: { amount, currency: "USD" }`
(or `null`). The admin license page shows it next to non-USD orders.

Orders carry `payment_status` (`paid` · `pending` · `refunded`), `paid_at`, `refunded_at` and `refund_reference` in
every response.

### `POST /customers` (storefront sign-up)

```json
{ "company": "Analytical Records", "email": "ada@analytical.test", "name": "Ada", "country": "GB", "vat_id": "GB123", "currency": "GBP" }
```

Needs `Idempotency-Key` (musicdesk.tech sends `customer-{its organisation id}`). Creates the organisation, links it to
the storefront and returns `201` with the customer (`id: "cus_…"`). Later orders send `customer: { "id": "cus_…" }`.
`company`, `email` and `country` are required.

### `POST /orders/{ord}/confirm-payment` (bank transfer received)

`{ "payment_reference": "NEFT-778", "paid_at": "2026-11-25T10:00:00Z" }` (both optional). The order becomes `paid`;
its license, if still `pending` (even past the 14-day grace), becomes `active` (event `payment_confirmed`, webhook
`license.updated`). Already paid → `200` unchanged. Refunded → `409 order_refunded`. Response: the order plus the license.

### `POST /orders/{ord}/refund` (refund made on the storefront)

`{ "refund_reference": "rfnd_9", "reason": "30-day money-back guarantee" }` (both optional). The order becomes
`refunded`. A refunded `new_license` order **revokes** its license (reason = the refund reason; webhook
`license.revoked`; installs are refused at their next check-in). For other kinds (renewal, extra domains…) the refund
is recorded on the license timeline (`order_refunded`) and a Super Admin decides what to change. Repeating is `200`
unchanged.

Both order actions accept `X-Customer-Id` (then the order must be that customer's) and record `X-Acting-User`. An order
of another storefront or customer is `404 order_not_found`.

### `POST /licenses/{lic}/downloads` (release packages)

`{ "version": "4.3.0" }` → `201` with a **single-use link valid 15 minutes** (`url` = `https://billing.twsd.me/downloads/<token>`).
Redirect the browser to it; never proxy or store the link. The response's `sha256` lets the customer check the file.

- Entitled = a published release of the license's product **released on or before `support_until`**. Otherwise
  `403 updates_expired` (the message names the newest version still available). Every license response carries
  `latest_available_version` for the portal's "updates expired" state.
- `404 release_not_found`, `409 package_unavailable` (no package uploaded yet), `403 license_suspended` / `license_revoked`.
- An unknown, used or expired link answers `410`. A link issued before the license was suspended stops working.
- Packages are uploaded by a Super Admin (Products & Plans → release → Upload package, or with "Publish release").
  They are stored on this server under `storage/app/private/releases/…`, never in `public/`; size and SHA-256 are
  computed from the file. Max size `LICENSING_MAX_PACKAGE_MB` (default 200) — also raise PHP's `upload_max_filesize`
  and `post_max_size` in cPanel → MultiPHP INI Editor, and keep an eye on the disk quota.
- History events: `download_link_issued`, `downloaded`.

## License server (LabelDesk installs)

Base `https://billing.twsd.me/api/license/v1`, 30 requests/min per IP, same error envelope.

| Method | Path | Body → result |
| --- | --- | --- |
| POST | `/activate` | `{ key, domain, environment?, version?, php_version?, instance_id? }` → `{ token, status, support_until, max_version, check_in_requested, messages }`; `404 invalid_key`, `409 domain_limit_reached` / `domain_in_use`, `403 license_suspended` |
| POST | `/check-in` | `{ token, version?, php_version? }` every 24 h → same shape with a fresh token |
| POST | `/deactivate` | `{ token }` → `204` |
| GET | `/public-key` | `{ alg: "EdDSA", crv: "Ed25519", kid, public_key }` (base64) |

Tokens are JWTs signed with Ed25519 (`alg: EdDSA`, header `kid`), valid 30 days. Claims: `lic`, `act`, `dom`, `env`,
`plan`, `status`, `support_until` (unix), `max_version`, `exp`, `iat`, `iss`. Domain rules: production activations ≤
included + extra domains; staging is free (`staging.*`, `dev.*`, `*.test`, `*.local`, `localhost`, or
`environment: staging`); domains normalised (lowercase, no scheme/path/port/`www.`); one active license per domain.
An expired annual license keeps working (no updates); suspended/revoked are refused.

## Webhooks (to the storefront)

`POST {webhook_url}`, body `{ id: "evt_…", type, created_at, storefront, customer_id, data }`, header
`WCC-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>`. At-least-once, retried 1 m … 24 h (8
attempts); de-duplicate on `id`. Types sent: `license.created`, `license.updated`, `license.suspended`,
`license.revoked`, `license.expiring` (30/14/3 days, daily 08:00), `license.expired`, `activation.created`,
`activation.deactivated`. The Storefronts page lists recent deliveries and errors.

## Not in this phase (from the MusicDesk spec)

OIDC login (MusicDesk keeps its own login and stores `wcc_customer_id`), team members/roles per customer, preview
(proration) endpoints (the storefront prices renewals and upgrades itself), checkout sessions, payment methods,
invoices/PDFs (the storefront's), offline `.ldlic` activation, license certificate PDF, support tickets,
`activation.stale` / `subscription.*` / `release.published` webhooks, coupons, resellers. Renewal-reminder e-mails:
see decision D-35.
