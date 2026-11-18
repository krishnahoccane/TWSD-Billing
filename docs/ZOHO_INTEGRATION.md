# Zoho Books integration

**Status (end of Phase F):** built and tested against the **mock** Zoho Books and against a faked HTTP layer. It
has **never called a real Zoho Books organisation**. Every screen says "mock" while the mock is in use.

Reference: https://www.zoho.com/books/api/v3/ (checked 2026-11-17).

## Who owns what

| This application | Zoho Books |
| --- | --- |
| Mailboxes, prices, billable days, calculation, the invoice snapshot and its number | The accounting invoice (tax split, PDF, ledger), the customer, payments |

The invoice is created in Zoho **with our invoice number** and marked sent. GST is sent as one 18% tax per line;
Zoho splits it into CGST + SGST or IGST from the customer's place of supply.

## Shape

```
ZohoBooksClientInterface
├── ZohoBooksClient          HTTPS, "Authorization: Zoho-oauthtoken …", ?organization_id=…
├── MockZohoBooksClient      no network; memory (tests) or storage/app/private/mock-zoho-books.json (local)
└── LoggingZohoBooksClient   decorator → integration_sync_logs (no payloads, no tokens)
ZohoConnection               OAuth 2.0, refresh token (encrypted), settings
ZohoSync                     customers, invoices, payments, reconciliation, webhooks
```

Binding: the mock is used in the test suite always, and locally while `ZOHO_ENABLED=false`. **In production the
real client is always used** — if Zoho is not connected, sending fails visibly instead of being simulated. (The same
rule now applies to Google Workspace and domain verification.)

## Connecting (go-live checklist)

1. In the Zoho API console for the **India** data centre (api-console.zoho.in) create a *Server-based application*
   with redirect URI `https://<your host>/integrations/zoho/callback`.
2. On the server set `ZOHO_ENABLED=true`, `ZOHO_CLIENT_ID`, `ZOHO_CLIENT_SECRET`, `ZOHO_REDIRECT_URI`,
   `ZOHO_ACCOUNTS_BASE_URL=https://accounts.zoho.in`, `ZOHO_API_BASE_URL=https://www.zohoapis.in/books/v3`,
   `ZOHO_WEBHOOK_SECRET` (12–50 letters/digits).
3. Integrations → Zoho Books → **Connect Zoho Books**, approve in Zoho (scopes: `ZohoBooks.contacts.ALL`,
   `ZohoBooks.invoices.ALL`, `ZohoBooks.customerpayments.ALL`, `ZohoBooks.settings.READ`; offline access).
4. Save the **organisation id**, the **GST 18% tax id** (required — invoices are refused without it), optionally a
   service **item id** with the SAC code, and the **deposit account id** for offline payments.
5. **Test connection** (lists organisations and checks the id).
6. Create the payment webhook (below), then issue one test invoice and check it in Zoho.

OAuth details: authorise at `{accounts}/oauth/v2/auth` with `access_type=offline&prompt=consent` and a random
`state` checked on return; exchange at `{accounts}/oauth/v2/token`; access tokens (1 hour) are cached encrypted and
refreshed with the refresh token, which is stored encrypted with `APP_KEY` and never shown, logged or audited. The
`accounts-server` value Zoho sends back is used only if it is a known Zoho accounts host. Disconnect revokes the
token at `{accounts}/oauth/v2/token/revoke`.

## What happens when

| Event | Zoho call(s) |
| --- | --- |
| Invoice issued (by hand or by the automatic monthly run) | Find or create the customer (`contact_name` = "Name (CODE)", GSTIN, billing address, billing e-mail) → find the invoice by number, else `POST /invoices?ignore_auto_number_generation=true` → `POST /invoices/{id}/status/sent` → `GET /invoices/{id}` (status, total, balance, `invoice_url`) |
| Invoice voided here | `POST /invoices/{id}/status/void` |
| Credit note issued here | **Not pushed.** The invoice is flagged "Needs attention in Zoho" with the amount to credit there |
| Offline payment recorded by a Super Admin | `POST /customerpayments` against the Zoho invoice(s), `reference_number` starting `WCC-PAY-<uuid>` |
| Payment made in Zoho (online or by your accountant) | Webhook (immediate) or the hourly sync: `GET /customerpayments?customer_id=…` → `GET /customerpayments/{id}` → recorded here against the invoices Zoho applied it to |
| Hourly (`ReconcileZohoPaymentJob`, also "Sync now") | Retry invoices not yet in Zoho, push offline payments, record new Zoho payments, compare every open invoice's total / balance / void status with Zoho |

**An invoice is marked paid here only from a payment read from Zoho's API, or an offline payment a Super Admin
verified.** The pay link and any "success" page are never trusted.

Duplicates are impossible by construction: one `zoho_mappings` row per local record and per Zoho record, look-ups
by contact name / invoice number before creating, Zoho payments stored under `zoho:<payment_id>` (unique), and our
own pushed payments recognised by their `WCC-PAY-` marker.

## Customers that already exist in Zoho

Integrations → **Zoho Customers** lists the customers in Zoho Books. **Link** one to an organisation before its first
invoice is sent, so that invoice goes to the existing customer instead of a new duplicate. With "Copy details" ticked,
the legal name, GSTIN, PAN (or the PAN inside the GSTIN), billing address, e-mail and primary contact are copied into
the organisation. A linked customer is never renamed or updated in Zoho from here (decision D-27). Organisations that
are not linked still get a customer named "Name (CODE)" created on their first invoice.

## Pay link and PDF

- The invoice page shows **Pay ₹…** to the organisation, linking to the invoice's `invoice_url` from Zoho — Zoho's
  customer page for that invoice, where it can be paid if a payment gateway is enabled in Zoho Books.
- **PDF** (`/invoices/{id}/pdf`, `GET /api/v1/invoices/{id}/pdf`) streams Zoho's PDF of the invoice.
- Zoho also lists a "generate payment link" endpoint (`GET /share/paymentlink`) but does not document its
  parameters, so it is **not used**.

## Webhook

`POST https://<host>/api/v1/webhooks/zoho` — set it up in Zoho Books → Settings → Automation → Webhooks on the
Customer Payments module, triggered by a workflow rule when a payment is created.

- Accepted if header `X-WCC-Webhook-Token` equals `ZOHO_WEBHOOK_SECRET` (recommended: add it as a custom header),
  or `X-Zoho-Webhook-Signature` is a valid HMAC-SHA256 (base64) with that secret. Zoho's documentation describes
  the signed string inconsistently, so the documented variants are all accepted (each needs the secret).
- Duplicate deliveries (same body) are ignored. Only the payment id is stored.
- The webhook only names a payment; the payment is re-read from Zoho's API before anything is recorded. Amounts in
  the webhook body are ignored.

## Differences from Zoho

Shown under Integrations → Zoho Books → *Invoices needing attention*, and on each invoice:
**Sync failed** (with Zoho's error), **Differs from Zoho** (total, balance or void status), **Needs attention in
Zoho** (a credit note to mirror by hand).

## To confirm in the first live test (unverified)

- The list filters `contact_name`, `invoice_number` and `customer_id` behave as exact filters.
- `GET /invoices/{id}?accept=pdf` returns the PDF (documented for payments, assumed for invoices).
- Whether line items need `item_id`; whether negative-rate lines (credit adjustments) are accepted — if not, the
  invoice shows "Sync failed" with Zoho's message.
- `gst_treatment` values `business_gst` / `business_none`, and whether `place_of_supply` must be sent.
- Zoho's tax rounding equals ours (CGST and SGST each per line, half up — matches a real single-line invoice; not yet
  checked on an invoice with several mailboxes). Up to ₹1 apart is treated as rounding (see BILLING_ENGINE.md);
  more shows "Differs from Zoho".
- The webhook signature construction; the custom-header token is the reliable option.
