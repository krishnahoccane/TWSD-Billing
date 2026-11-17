# Billing engine

**Status (end of Phase F):** implemented and tested; issued invoices are sent to Zoho Books (mock until connected —
see ZOHO_INTEGRATION.md). Decisions confirmed by the owner on 2026-11-17 are marked *(decided)*.

Customer billing here is independent of what Google charges for licences.

## Money

- Every amount is an integer number of paise in a `*_minor` column. There are no floats anywhere in the engine.
- Tax rates are basis points (1800 = 18.00%).
- Rounding is half-up, symmetric for negative amounts (`Money::divideRound`).
- The API returns decimal strings (`"1250.00"`) plus a currency code.

## Prices

Precedence for a mailbox on a given day: **mailbox override → organisation price → platform default**.

- Every price is effective-dated. Setting a new price closes the previous row the day before; rows are never
  edited or deleted, and each change is written to `pricing_history` and the audit log.
- A new price must start after the current one, and **cannot start inside a period that has already been
  invoiced** — use an adjustment for corrections.
- If no price covers a billable day, that organisation's billing cycle fails with a clear message; other
  organisations are unaffected.

## Billable days

`account_billing_periods` records the days each mailbox is chargeable and to which organisation:

| Event | Effect |
| --- | --- |
| Created, imported with a mapped domain, assigned, restored | Opens a period from that day |
| Suspended / reactivated | **No change — suspended mailboxes stay billable** |
| Soft-deleted | Closes the period the day **before** the effective deletion date (the effective date is not charged) |
| Moved to another organisation | Old organisation charged up to the day before; new one from that day |

Imported mailboxes are billable from the import/assignment day, not their Google creation date *(decided, D-18)*.

## Calculation

For each mailbox and each run of consecutive billable days at one price:

```
line amount = round_half_up( monthly price × billable days ÷ days in the period )      (dates inclusive)
line tax    = round_half_up( line amount × tax rate )
```

- A price change inside the month produces two lines.
- `BILLING_PRORATION_POLICY=full_month` charges the whole price for any mailbox billable on at least one day.
- Totals are the sums of the lines, so the invoice always equals its lines exactly.
- **Automatic billing in arrears** *(decided, D-11/D-21)*: on the 1st at 03:00 (billing timezone) the previous
  calendar month is billed and the invoices are **issued and sent to Zoho Books** without review.
- A Super Admin can bill **older months by hand** (Billing cycles); those runs create **drafts** to review and issue.
  Only a completed month can be billed.
- Active and suspended organisations are billed; inactive ones are not.

## GST *(decided, D-09)*

- One rate for every organisation: `BILLING_DEFAULT_TAX_RATE` = **18%**, shown as a single "GST 18%" figure.
- Calculated per line (round half up) and summed.
- The CGST + SGST / IGST split is made by **Zoho Books** from the customer's place of supply.
- Not handled here: SAC codes (set on the Zoho item), reverse charge, exemptions, TDS, e-invoicing.

## Invoices

`draft → issued → partially_paid → paid`, or `void`. "Overdue" is derived (open and past the due date).

- **One invoice per organisation per period**: unique `billing_cycles (organisation, period)` and unique
  `invoices.billing_cycle_id`, plus a row lock, so retries, duplicate jobs and double clicks return the same invoice.
- **Drafts** (older months billed by hand) are internal, unnumbered and can be recalculated.
- **Issuing** assigns the next number from a locked, gap-free sequence (`INV-000001`), sets the issue and due
  dates, and freezes the invoice.
- **Immutable after issue**: the models refuse changes to charges, tax, period, number, customer snapshot and
  lines. Each line stores the mailbox address, name, status, days, price and price source as they were.
- **Void**: unpaid invoices only; the number is kept. **Credit note**: reduces what is owed without touching the
  invoice (`CN-000001`).
- **Adjustments**: one-off credits or charges (optionally taxable) added to the next draft. A credit larger than
  the invoice waits for a later cycle.

## Payments

- An invoice becomes partially paid or paid only through `PaymentService` — from a payment **read from Zoho's
  API** (`recordAllocated`, applied exactly as Zoho applied it) or an **offline payment a Super Admin verified**
  (`recordVerified`, source `offline`). Never from a payment page or redirect.
- Offline payments *(decided, D-20)*: Super Admins (`payments.reconcile`) record bank transfers, UPI, cheques and
  cash on the invoice page (amount up to the balance, date not in the future, method, UTR / reference). The payment
  is then recorded in Zoho Books too. `BILLING_ALLOW_MANUAL_PAYMENTS=false` turns this off.
- Idempotent on the external payment id; a webhook plus the hourly sync records one payment.
- Allocation: the named invoice first, then open invoices oldest due date first. Overpayment stays as credit and is
  applied when the next invoice is issued. An invoice is never paid beyond its total.

## Screens and API

Billing overview · Pricing (platform default, organisation prices) · organisation pricing (price history, tax
rate, mailbox overrides, adjustments) · Billing cycles · Invoices · Invoice (printable) · Payments · Outstanding.

API (read-only): `GET /billing/summary`, `/billing/pricing`, `/invoices`, `/invoices/{id}`, `/payments`.

## Not included in Phase E

- Credit notes are not pushed to Zoho (flagged for manual entry there).
- Invoice and overdue notifications (Phase G).
- Charts on the billing dashboard; currency other than a single configured one.
- Two-person approval for adjustments (the creator approves).
