# General ledger — design

**Status:** approved in conversation, 24 October 2026 (sections 1–3). Buildium accounting parity, first part; bank
reconciliation follows as its own spec.

## 1. Purpose

Keep the company's double-entry books inside the app, built from the money records it already keeps, so an accountant
can read a trial balance, balance sheet, profit and loss and general ledger, post manual journals, and lock closed
months — without staff changing how they record anything.

**Decisions:** the books are the **company's**; money collected for owners is a liability (**Owner payable**). The ledger
starts at an Admin-chosen **go-live date** with **opening balances**. Each payment method maps to **one** ledger account.

**Out of scope:** bank reconciliation (next); export to accounting software; budgets; several bank accounts per method;
trust accounting; any change to how staff record money.

## 2. Approach

**Journals are derived and rebuildable.** A posting service reads existing money records and writes balanced journal
lines tagged with their source record. Each source is posted when it is created or reversed; any unlocked month can be
**rebuilt** (automatic journals deleted and regenerated). Manual journals are separate and never rebuilt. Locked months
are frozen.

Rejected: posting inline in each money action (every action changes; a later rule change only affects new entries).

## 3. Accounts and setup

- **Chart of accounts:** code, name EN/AR, type (asset, liability, equity, income, expense), active. Seeded defaults:
  - Assets: Cash on hand; Bank; Card clearing; Tenant receivables.
  - Liabilities: Tenant deposits held; Owner payable; Vendor payable; Tenant credit; VAT payable.
  - Income: Rent income; Other charges income; Management fee income.
  - Expenses: Lease rent to owners; Maintenance and repairs; one per expense category as needed.
  - Equity: Opening balance equity; Retained earnings.
  Admin adds, renames, deactivates (an account with lines cannot be deleted; a deactivated account takes no new
  postings).
- **Posting rules:** one settings screen mapping each event to its accounts (§4), including payment method → account
  and expense category → account. Changing a rule offers to rebuild the open months.
- **Go-live:** Admin sets the start date and one opening balance per account; the opening journal must balance (the
  difference goes to Opening balance equity). Nothing is posted before the start date. The start date can move only
  while no month is locked.
- **Permissions:** Admin: accounts, rules, start date, unlock. Finance: view ledger and reports, manual journals, lock
  months, rebuild. Others: no access.

## 4. What is posted

Dated with the record's own date; linked to the source record; reversals and credit notes post the exact opposite.

| Event | Debit | Credit |
|---|---|---|
| Rent / charge invoice issued — managed building | Tenant receivables (total) | Owner payable (net); VAT payable (tax) |
| Rent / charge invoice issued — leased building | Tenant receivables (total) | Rent income or Other charges income (net); VAT payable (tax) |
| Deposit invoice issued | Tenant receivables | Tenant deposits held (Owner payable when the owner holds deposits) |
| Credit note | opposite of the invoice it credits | |
| Receipt (per tender method) | Cash / Bank / Card clearing (cleared cheque → Bank) | Tenant receivables (allocated); Tenant credit (unallocated) |
| Post-dated cheque held | nothing until cleared; bounced / returned → reversal | |
| Deposit applied to invoices | Tenant deposits held | Tenant receivables |
| Deposit refunded | Tenant deposits held | Bank (or the payout method's account) |
| Credit refund | Tenant credit | payout method's account |
| Expense — charged to company | category expense account | Vendor payable |
| Expense — charged to owner | Owner payable | Vendor payable |
| Expense — charged to tenant | category expense account | Vendor payable (the tenant's recharge invoice posts separately) |
| Vendor paid | Vendor payable | payout method's account |
| Management fee (owner charge) | Owner payable | Management fee income (net); VAT payable (fee tax) |
| Leased building rent due to owner (payable due date) | Lease rent to owners | Owner payable |
| Payout to owner | Owner payable | payout method's account |
| Owner opening balance / other owner charges | Owner payable or Opening balance equity as fits | |
| Manual journal | Finance-entered lines | |

- Every journal must balance before it is saved; a manual journal needs ≥2 lines and a description, is never edited,
  and is corrected by reversal.
- **Ledger health:** a nightly check compares ledger balances with the app's own figures (tenant balances, deposits
  held, owner balances, vendor payable); differences show on a "Ledger health" card for Finance and Admin.

## 5. Rebuild and locking

- **Rebuild:** Finance or Admin, any unlocked month from the start date; deletes and regenerates automatic journals in
  the background with progress; audited.
- **Locking:** Finance sets "Locked up to"; nothing is posted, rebuilt or changed on or before it. A source dated in a
  locked month posts on the first open day, marked **late posting**. Unlocking is Admin-only and audited.

## 6. Reports

All with Excel download, dd/mm/yyyy, BHD 3 dp:

- **Trial balance** at a date (balances sum to zero).
- **Balance sheet** at a date (current-year profit inside equity).
- **Profit and loss** for a period, from the ledger (the existing income statement stays).
- **General ledger** for an account and period: opening balance, lines with click-through to the source, running balance.
- **Journal list:** filter by date, account, source; expands to lines.
- **Report builder:** a new "Journal lines" subject (saved reports and packages).

## 7. Tables

- `ledger_accounts`: id, code (unique), name_en, name_ar, type, system_key (nullable, for seeded accounts the rules use),
  active, timestamps.
- `ledger_settings` (one row): start_date, locked_until, timestamps; posting rule mappings in `ledger_rules`
  (event key → account, plus method → account and expense category → account).
- `journals`: id, date, kind (`auto|manual|opening`), source_type, source_id (nullable), description, late_posting
  (bool), reverses_journal_id (nullable), created_by (nullable), timestamps.
- `journal_lines`: id, journal_id, ledger_account_id, debit (decimal 15,3), credit (decimal 15,3), customer_id,
  owner_id, building_id, unit_id (nullable dimensions), memo.
- Balance enforced by the posting service (and a DB check that each line has exactly one of debit or credit > 0).
- Existing money tables are **read only** by the ledger; `EXPECTED_TRIGGERS` gains only what the new tables need
  (manual and locked journals immutable).

## 8. Safety

- Only Finance and Admin; actions re-check permission; manual journal input validated (balanced, active accounts,
  open date); tampered input gives a message, never a 500.
- Posting and rebuild are idempotent per source; a failure rolls back that source's journal only and is reported in
  Ledger health.
- Money as exact decimals through the `Fils` helpers, never floats.

## 9. Testing

- Each event type posts balanced lines to the right accounts, managed and leased; reversals and credit notes exactly
  opposite.
- Rebuild of an unlocked month is identical to live posting; manual journals survive.
- Locked months never change; late postings land on the first open day; unlock is Admin-only.
- Opening balances balance via Opening balance equity; nothing before the start date.
- Ledger health matches tenant, deposit, owner and vendor figures on a worked example.
- Reports correct against a worked example; Excel downloads; report builder subject.
- Permissions: only Finance and Admin.
