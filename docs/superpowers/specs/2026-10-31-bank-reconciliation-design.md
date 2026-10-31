# Bank reconciliation — design

**Status:** approved in conversation, 31 October 2026. Builds on the general ledger and the ledger money screens
(`2026-10-28-ledger-money-screens-design.md`; cash and bank movements).

## 1. Purpose

Let Finance prove each money account against the bank: upload the bank statement, match its lines to ledger entries,
and finish a reconciliation only when the statement balance is fully explained.

**Out of scope:** live bank feeds; PDF or MT940 statements; matching across accounts; automatic matching without
confirmation.

## 2. Statement upload

- Page **Bank reconciliation** (Finance). Pick a money account (`ledger_accounts.is_money`), upload CSV or XLSX.
- **Column mapping**, saved per account: date column and date format (dd/mm/yyyy, d/m/Y, Y-m-d, d-M-Y), description,
  reference (optional), amount as either debit + credit columns or one signed column, balance (optional), header row
  number. First upload asks; later uploads reuse it (editable).
- Each row becomes a **statement line**: account, date, description, reference, amount (signed: + money in, − money
  out, exact BHD 3 dp), balance (nullable), upload id, row number.
- **Duplicates** (same account, date, amount and description as an existing line) are skipped; the upload reports
  counts imported and skipped, and rows that failed with the reason (bad date, bad amount).
- Statement lines are immutable. An upload can be deleted only while none of its lines is matched (deletes its lines).

## 3. Reconciliation

- Start: account, statement end date, statement closing balance. Opening balance = the previous finished
  reconciliation's closing balance for that account; the first one asks for it.
- One open reconciliation per account at a time; end dates move forward only.
- **Matching.** For each unmatched statement line dated up to the end date, suggest unreconciled ledger entries on the
  account: same direction and amount, dated within ±7 days of the statement line; a reference or number appearing in
  the statement description or reference ranks first. Finance confirms, or picks other entries.
- A statement line may match **several** ledger entries whose total equals it (one bank deposit of several receipts),
  and a ledger entry matches at most one statement line.
- **Unmatched bank-only items:** from a statement line, create a cash and bank movement (bank charge, bank interest,
  transfer) through the existing action; it is matched automatically.
- Matches can be removed while the reconciliation is open.

## 4. Finishing

- The page shows: statement closing balance; deposits in transit (ledger money-in entries to the end date not
  matched); outstanding payments (ledger money-out entries to the end date not matched); adjusted balance = statement
  balance + in transit − outstanding; ledger balance of the account at the end date; difference.
- Also: statement lines to the end date must all be matched, and statement opening + Σ matched statement lines =
  closing balance.
- **Finish** only when the difference is 0.000 and every statement line to the end date is matched. Finished:
  matched entries are reconciled; the reconciliation report (the figures above plus the in-transit and outstanding
  lists) downloads as Excel through SafeCells.
- **Protection:** a source whose ledger entry is reconciled cannot be reversed with a date on or before the
  reconciliation's end date (a later-dated reversal is fine). Matches reference the source record (source type and id,
  or the journal for manual journals), so a ledger rebuild keeps them.
- Admin can **reopen** the most recent finished reconciliation of an account (audited); its entries become
  unreconciled.

## 5. Tables

- `bank_mappings` (one per account): columns and formats as JSON-free typed columns.
- `bank_uploads`: account, file name, uploaded by, counts, created at.
- `bank_statement_lines`: as §2; unique (account, date, amount, description hash).
- `bank_reconciliations`: account, end date, opening balance, closing balance, status (open|finished), finished by/at,
  reopened by/at.
- `bank_matches`: reconciliation, statement line, ledger entry reference (source type + source id, or journal id),
  amount; unique per ledger entry reference while active.
- Immutability triggers on statement lines (no update) and finished reconciliations' matches; `EXPECTED_TRIGGERS`
  updated.

## 6. Safety

- Finance (`ledger.manage`) uploads, matches, finishes; `ledger.view` views; Admin (`ledger.admin`) reopens.
  Actions re-check permissions; audited.
- Uploads validated (type, size ≤ 5 MB, ≤ 5000 rows); file contents are data, never formulas; tampered input gives a
  message, never a 500. BHD 3 dp through Fils; dates dd/mm/yyyy; EN/AR.

## 7. Testing

- Mapping saved and reused; debit/credit and signed amounts; date formats; duplicates skipped; bad rows reported.
- Suggestions by amount, direction, ±7 days and reference ranking; many-to-one matching; one entry matched once.
- Bank-only line → cash movement created and matched.
- Finish refused until difference 0 and all lines matched; report figures; Excel through SafeCells.
- Reversal refused on or before a finished reconciliation's end date, allowed after; matches survive a rebuild;
  reopen by Admin only.
- Upload delete refused once a line is matched; permissions; Arabic.
