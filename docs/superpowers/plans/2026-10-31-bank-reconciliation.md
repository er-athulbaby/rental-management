# Bank Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Finance prove each money account against the bank: upload a CSV or XLSX statement with a saved column mapping, match its lines to ledger entries (suggestions first, several entries to one line), turn bank-only lines into cash and bank movements, and finish a reconciliation only when every statement line to the end date is matched and the difference is 0.000. Reconciled entries cannot be reversed on or before the end date; Admin can reopen the latest one.

**Architecture:** Five new tables: `bank_mappings`, `bank_uploads`, `bank_statement_lines` (never updated), `bank_reconciliations` (one open per account through a generated unique column) and `bank_matches` (never updated; no insert or delete while its reconciliation is finished). A ledger entry is one journal's net on the money account, keyed by its source (`source_type` + `source_id`, or `manual` + journal id), so matches survive a rebuild. `App\Ledger\BankEntries` holds the entry query, the suggestion query, the finish figures and the shared reversal guard. Nine Actions under `App\Actions\Ledger` are the only writers; every one locks the account's `ledger_accounts` row first. Two Livewire pages live under `routes/ledger.php`.

**Tech Stack:** Laravel 13, PHP 8.5, MySQL, Livewire 4 + Flux (free), Pest, spatie/simple-excel (OpenSpout 4.32), spatie/laravel-permission, `App\Audit\Audit`, `App\Support\{Fils, SafeCells}`, `App\Ledger\{Poster, Sources}`, `App\Actions\Ledger\{RecordCashMovement, PostManualJournal}`, `<x-date-input>`, `<x-file-button>`.

**Spec:** `docs/superpowers/specs/2026-10-31-bank-reconciliation-design.md`. Read it first; this plan argues from it. Where the plan and the spec differ, a ruling says why. The ledger money screens plan (`docs/superpowers/plans/2026-10-28-ledger-money-screens.md`) explains money accounts, cash movements and `Sources::assertPostable`; the general ledger plan (`docs/superpowers/plans/2026-10-24-general-ledger.md`) explains `Poster`, `Sources` and the rebuild.

## Global Constraints

- Spec §1, out of scope: live bank feeds; PDF or MT940 statements; matching across accounts; automatic matching without confirmation.
- Spec §6: "Finance (`ledger.manage`) uploads, matches, finishes; `ledger.view` views; Admin (`ledger.admin`) reopens. Actions re-check permissions; audited." "Uploads validated (type, size ≤ 5 MB, ≤ 5000 rows); file contents are data, never formulas; tampered input gives a message, never a 500. BHD 3 dp through Fils; dates dd/mm/yyyy; EN/AR."
- Actions are the only write path, and each one re-checks permission: `ledger.manage` uploads, matches and finishes; `ledger.view` views; `ledger.admin` reopens.
- New tables get immutability triggers where the spec says so (statement lines: no update; a finished reconciliation's matches: no insert, update or delete). `EXPECTED_TRIGGERS` goes from 73 to **77** (+1 `bank_statement_lines_no_update`, +3 `bank_matches_no_update`, `bank_matches_insert_guard`, `bank_matches_delete_guard`).
- Money is exact through `Fils`, never floats. Timezone is Asia/Bahrain. Dates show as dd/mm/yyyy.
- All strings go through `__()` with entries in ar.json, inserted after `"Credit refund reversal": "عكس استرداد رصيد",`, with no duplicate keys. Every key listed below was checked as absent from `lang/ar.json` and from the other tasks. Say "tenant", never "customer", in UI copy.
- Tampered Livewire input gives a message or a 404, never a 500. Internals are private or static. Ids use `#[Locked]`.
- Uploaded file contents are data and never formulas: cells are read as values (OpenSpout never returns a formula unless asked) and stored as plain text. Exports go through SafeCells.
- Ledger postings go through the existing Poster after commit. A bank-only line is recorded through the existing `RecordCashMovement`, so the lock and the open-date rules apply unchanged.
- Tests run serially on rms_test, never two at once.
- Gates: Pint `--dirty`; `phpstan clear-result-cache`, then phpstan `--memory-limit=2G` with 0 errors; `npm run build`.
- The PHP binary is /c/Users/ababy/.config/herd/bin/php85/php.exe (written `php` below).
- Never run migrate:fresh on the dev DB `rms`. Never change MySQL global settings. The dev DB is migrated with `php artisan migrate --database=migrator --force`.
- Migration `down()` methods guard against data loss before any DDL: refuse, changing nothing, while rows exist.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never commit `.superpowers/`.
- No visual polish. Pages are plain Flux, consistent with the existing ledger pages; a design pass comes later.
- Blade: do not mix inline `@php(...)` with `@php … @endphp` blocks in one file.

## Rulings made while planning

1. **What a ledger entry is.**
   - An entry is **one journal's net on the money account**: Σ debit − Σ credit of that journal's lines on the account, when not zero. Positive is money in, negative is money out.
   - Its **key** is `(entry_type, entry_id)`: the journal's `source_type` and `source_id` for an automatic journal, and `manual` with the journal id for a manual journal (manual journals are never rebuilt; a manual reversal is its own manual journal, so its own entry). `journals` already has a unique key on `(source_type, source_id)`, so a key names exactly one journal. In SQL: `COALESCE(j.source_type, 'manual')` and `COALESCE(j.source_id, j.id)` (`BankEntries::TYPE`, `BankEntries::ID`).
   - The **opening** journal is not an entry: no statement line can match it. It still counts in the ledger balance (ruling 4).
   - **Split receipts:** a split payment posts one line per tender on the tender method's account. On a given money account its entry is the net of the lines on that account (bank transfer 100 + cash 50 gives Bank +100 and Cash +50, each under the key `payment:<id>`, matched separately per account because the match's unique key includes the account). Two tenders on the same account are one entry; if the bank shows them as two lines, record two receipts instead.
   - **Date:** an entry is dated by its **journal date** (`j.date`), not by the source's own date. The ledger balance at the end date is by journal date, so in-transit and outstanding lists must be too, or the difference would not tie. A late posting (a source dated in a locked month) is dated on the first open day where it landed. An entry can be matched in a reconciliation only when its journal date is on or before the end date.
   - A payment and its reversal are two entries (`payment:<id>`, `payment_reversal:<id>`), as for every reversal type.
2. **Suggestion query** (`BankEntries::suggestions`). For one statement line, unmatched entries on the reconciliation's account where:
   - `j.date BETWEEN line.date − 7 days AND line.date + 7 days` and `j.date <= end_date`;
   - `SUM(l.debit) − SUM(l.credit) = line.amount` (signed, exact decimal: same direction and amount);
   - ranked by `hit DESC, ABS(DATEDIFF(j.date, line.date)), j.id`, top 20. `hit` is true when any of the journal description (a receipt's or payment out's number, a movement's reference, a manual journal's text), `payments.reference`, `disbursements.reference` or the cheque number (`cheques.cheque_no` through `payments.cheque_id` / `disbursements.cheque_id`) is at least 3 characters and appears (`INSTR`, case-insensitive under the table collation) in the line's description and reference.
   - Exact SQL (bindings in order: the line text ×4, account, end date, account, window from, window to, line amount, line date):

```sql
SELECT j.id AS journal, j.kind, j.source_type, j.source_id, j.date, j.description,
       COALESCE(j.source_type, 'manual') AS entry_type, COALESCE(j.source_id, j.id) AS entry_id,
       SUM(l.debit) - SUM(l.credit) AS amount,
       MAX((CHAR_LENGTH(j.description) >= 3 AND INSTR(?, j.description) > 0)
        OR (CHAR_LENGTH(p.reference) >= 3 AND INSTR(?, p.reference) > 0)
        OR (CHAR_LENGTH(d.reference) >= 3 AND INSTR(?, d.reference) > 0)
        OR (CHAR_LENGTH(c.cheque_no) >= 3 AND INSTR(?, c.cheque_no) > 0)) AS hit
FROM journal_lines l
JOIN journals j ON j.id = l.journal_id
LEFT JOIN payments p ON p.id = j.source_id AND j.source_type IN ('payment', 'payment_reversal')
LEFT JOIN disbursements d ON d.id = j.source_id AND j.source_type IN ('disbursement', 'disbursement_reversal')
LEFT JOIN cheques c ON c.id = COALESCE(p.cheque_id, d.cheque_id)
WHERE l.ledger_account_id = ? AND j.date <= ? AND j.kind <> 'opening'
  AND NOT EXISTS (SELECT 1 FROM bank_matches m WHERE m.ledger_account_id = ?
                  AND m.entry_type = COALESCE(j.source_type, 'manual') AND m.entry_id = COALESCE(j.source_id, j.id))
  AND j.date BETWEEN ? AND ?
GROUP BY j.id
HAVING SUM(l.debit) <> SUM(l.credit) AND SUM(l.debit) - SUM(l.credit) = ?
ORDER BY hit DESC, ABS(DATEDIFF(j.date, ?)), j.id
LIMIT 20
```

   - **Performance:** the 15-day window reads `journals` by its `(date, id)` index and the lines by `journal_lines (ledger_account_id, journal_id)`; the `NOT EXISTS` uses `bank_matches_entry_unique (ledger_account_id, entry_type, entry_id)`. On a year of lines it touches a few hundred journals, not the year. `GROUP BY j.id` is valid under ONLY_FULL_GROUP_BY (every `j` column depends on the primary key; the `p`, `d`, `c` columns are aggregated).
   - "Pick other entries" lists up to 100 unmatched entries dated by the end date, nearest the line's date first, filtered by description (`LIKE`) or by an exact amount in either direction.
3. **Matching.**
   - A match is one `bank_matches` row per (statement line, entry), storing the entry's live amount at match time. A line matches **one or more** entries whose live amounts total the line's amount exactly in fils; otherwise "The chosen entries total :total; the statement line is :amount." Up to 50 entries per line.
   - **One entry, one line:** `bank_matches_entry_unique (ledger_account_id, entry_type, entry_id)`. Removing a match **deletes** its rows (only while the reconciliation is open), so every row that exists is active and a plain unique key is enough. Reopening keeps the rows (ruling 6), and a reopened reconciliation is still the same reconciliation, so the key never collides. The Action checks first, under the account lock, and answers "A chosen entry is already matched to another statement line."
   - A line can be matched only in the account's open reconciliation, when dated on or before its end date and not yet matched. "Remove" takes off all of a line's rows at once.
   - The suggestions are only suggestions: Finance ticks entries (spec §1: no automatic matching).
4. **Finish figures** (`BankEntries::figures`, fils). For reconciliation R of account A with end date E:
   - **Matched as of R** = entries matched by any reconciliation of A whose end date ≤ E (R and the earlier ones). A finished reconciliation therefore reads the same after later ones match its in-transit items. Live, not stored.
   - **Deposits in transit** = Σ entries dated ≤ E, amount > 0, not matched as of R (listed). **Outstanding payments** = Σ |entries| dated ≤ E, amount < 0, not matched as of R (listed).
   - **Adjusted balance** = statement closing balance + deposits in transit − outstanding payments.
   - **Ledger balance** = Σ (debit − credit) on A over journals dated ≤ E, the opening journal included.
   - **Difference** = adjusted balance − ledger balance.
   - **Statement line coverage:** "Statement lines not matched" = lines on A dated ≤ E with no match (any reconciliation); and statement opening + Σ amounts of the lines matched in R = statement closing.
   - **Changed entries:** matches of R whose stored amount differs from the entry's live amount (or whose entry no longer posts to A, e.g. a rule moved and the month was rebuilt). Finishing refuses them: remove and match again.
   - **Opening balance chain:** the first reconciliation of an account takes a typed statement opening balance; every later one takes the previous (latest) reconciliation's closing balance and ignores any typed value. End dates move forward only: a new end date must be after the latest one. One open reconciliation per account (generated column `open_account_id` = account when open, unique).
   - **Finish** needs: no unmatched statement line to E, no changed match, coverage true, difference 0, and no ledger rebuild queued or running (a rebuild deletes journals before re-posting them). The error lists every reason at once.
   - Why the chain matters: every match pairs equal amounts, so when coverage holds on every reconciliation of the account, difference = the first reconciliation's statement opening − the account's opening-journal balance. Entries the bank cleared before the first statement stay unmatched and keep that difference away from 0. So the first reconciliation should start at the ledger start date, with the bank's balance on that day as its opening (spec §3: "the first one asks for it"). The worked example does exactly that: 1000.000 on both sides.
5. **Reversal guard** (`BankEntries::assertReversible(string $type, int $id, string $date, string $field)`). One query: is the entry key `(type, id)` matched in a **finished** reconciliation whose end date ≥ `$date`? If so, refuse on `$field` with "This entry is reconciled with the bank statement up to :date, so it cannot be reversed on or before that day." (`:date` = the latest such end date, dd/mm/yyyy). A reversal dated after every such end date is fine; an unmatched or merely open-matched entry is never blocked. Called from every reversal path, with the date each one posts on:

   | Path | Where | Key | Date | Field |
   |---|---|---|---|---|
   | Payment reversal request (also a bounced cleared cheque, through `BounceCheque`) | `RequestPaymentReversal::handle` | `payment`, payment id | today (Asia/Bahrain) | `reversalReason` |
   | Payment reversal approval | `PaymentReversal::approve` | `payment` | today: `payment_reversal` posts on `DATE(reversed_at)` | `approval` |
   | Payment out reversal request (owner, tenant, vendor) | `RequestDisbursementReversal::handle` | `disbursement` | today | `reversalReason` |
   | Payment out reversal approval | `PaymentOutReversal::approve` | `disbursement` | today | `approval` |
   | Expense reversal | `ReverseExpense::handle` | `expense` | today | `reason` |
   | Cash and bank movement / VAT return reversal | `ReverseMoneyEntry::handle` | `cash_movement` / `vat_return` | the chosen reversal date | `reverseDate` |
   | Manual journal reversal | `ReverseManualJournal::handle` | `manual`, journal id | the chosen reversal date | `reverseDate` |

   - **Expenses** post Dr cost or owner, Cr Vendor payable, never a money account (`ledger_accounts_money_chk` allows `is_money` only on assets, and the expense rules point at expense and liability accounts), so the expense guard can never fire; it is called anyway so the rule stays one rule.
   - **Credit notes** do not reverse receipts. `IssueCreditNote` cuts allocations (`allocation` journals: Tenant credit ↔ receivables) and posts the credit note as an invoice (receivables ↔ income and VAT). Neither writes a money-account line, so there is no reconciled key a credit note could touch; the payment stays as it is. Task 5 proves this with a reconciled card receipt. No guard call.
   - The guard reads without a lock. A reversal racing a finish in the same second can slip through; the finished figures stay right (the reversal shows as an unmatched entry). ponytail: take the account row lock in the reversal paths if that ever matters.
6. **Reopen and discard.**
   - **Reopen** (`ledger.admin`): only a finished reconciliation, and only the account's latest (no reconciliation of the account has a later end date, which also means none is open). Status back to `open`, `finished_by`/`finished_at` cleared, `reopened_by`/`reopened_at` set, audited `bank.reconciliation_reopened` with the old finish. Its matches stay; its entries are unreconciled again (the guard counts finished reconciliations only), so Finance can remove and redo matches, then finish again.
   - **Discard** (`ledger.manage`, not in the spec): an open reconciliation is deleted with its matches, audited. The spec gives no way to fix a mistyped end date or closing balance; discarding and starting again is the smallest one. A reopened reconciliation may be discarded too; the next one then chains from the one before it.
7. **Upload and mapping.**
   - **Mapping storage:** `bank_mappings`, one row per account, typed columns (no JSON): `header_row` (0–50; 0 = no header), `date_column`, `date_format`, `description_column`, `reference_column` (optional), `amount_mode` (`signed` | `split`), `amount_column` or `debit_column` + `credit_column`, `balance_column` (optional). Columns are numbers 1–26 shown as letters A–Z. Each upload saves the mapping it used; choosing the account on the page loads it back (editable). A column may be used for one thing only.
   - **Date formats:** `d/m/Y` (shown dd/mm/yyyy; PHP's `d` and `m` take 1 or 2 digits, so the spec's "dd/mm/yyyy" and "d/m/Y" are one format), `Y-m-d`, `d-M-Y` (05-Oct-2026). Parsed strictly (`createFromFormat('!'.$format)` with no warnings, so 31/02/2026 fails), year 2000–2099. An XLSX date cell arrives as a date and is used as it is.
   - **Amounts:** signed mode reads one column, + money in, − money out. Split mode: amount = |credit| − |debit|; both filled and non-zero is a bad row. A cell may be `1,250.500`, `(300.000)`, `+5` or `-2.5`, or an XLSX number; more than 3 decimals, text, or more than decimal(13,3) holds is a bad amount; zero or empty is a bad row.
   - **Duplicates:** unique `(ledger_account_id, date, amount, description_hash, occurrence)`, where `description_hash` = sha256 of the lower-cased description (whitespace collapsed) and `occurrence` = the n-th identical (date, amount, description) row **within the file**. Re-uploading a statement, or uploading an overlapping one, skips the rows already there; two genuine identical rows on one day ("SMS FEE −0.500" twice) are both kept. The spec's key without `occurrence` would drop the second and the statement would never balance.
   - **Bad rows** are reported (row number in the file, reason) and the rest import. Counts go on `bank_uploads` (`imported`, `skipped`, `failed`); the reasons are shown on the page after the upload.
   - **Limits:** the page validates `file|extensions:csv,xlsx|max:5120`; the Action refuses a type other than csv/xlsx, an unreadable file, and more than 5000 non-blank data rows, saving nothing. Blank rows are ignored and not counted.
   - **Formulas:** OpenSpout returns a formula cell's computed value (we never call `keepFormulas()`), and a CSV cell is a string. Text such as `=HYPERLINK(…)` is stored and shown as plain text (Blade escapes it); the report writes every cell through SafeCells.
   - **Delete:** an upload is deleted, with its lines, only while none of its lines is matched ("Lines of this upload are matched. Remove those matches first."). The FK from `bank_matches` is the backstop.
8. **Bank-only line to a cash movement** (`RecordBankOnlyLine`). From an unmatched line of the open reconciliation, Finance picks the kind: **bank charge** (money-out lines only: from = the account), **bank interest** (money-in lines only: to = the account) or **transfer** (the other money account is required: in → from other to this; out → from this to other). One transaction: the settings row lock, the account lock, then the existing `RecordCashMovement::handle` with the line's date, |amount|, and reference = the line's reference, else its description (first 100 characters); then a `bank_matches` row with entry `cash_movement:<id>` and the line's amount. `RecordCashMovement`'s own checks (open date, active money accounts, posting rules) apply and roll the whole thing back. Poster posts the movement after commit.
9. **Ledger lock and rebuild.**
   - **Matches survive a rebuild:** they reference source keys, and `RebuildLedgerJob` re-posts the same sources under the same `(source_type, source_id)`; manual journals are never rebuilt. A rebuilt journal gets a new id, which nothing here stores.
   - **Finishing does not require the period to be locked.** A reconciliation writes no journal, so the lock (which protects journals) has nothing to protect in it; the reversal guard protects reconciled entries instead. The natural order is reconcile, then lock: reconciling finds missing bank charges and interest, which must still be recordable in the open month (requiring the lock first would make them late postings on the next month, breaking the very figures being proven). Recommended practice, shown on no screen: reconcile a month before locking it.
   - Finishing refuses while a rebuild is queued or running (`LedgerSetting::rebuilding()`), because between deletion and re-posting the entries are missing.
10. **Locks.** Every bank reconciliation writer locks the account's `ledger_accounts` row first (`lockForUpdate`), so uploads, matching, finishing, reopening and upload deletion on one account run one at a time and the duplicate and "already matched" checks see each other. `RecordBankOnlyLine` takes the `ledger_settings` row lock **before** the account row (Poster takes the settings lock and then reads the account through the journal-line foreign key; the same order avoids a deadlock). ponytail: a 5000-row upload holds the account row for a few seconds, delaying postings to that account; a per-account advisory lock if that ever shows.
11. **Tables.**
    - `bank_mappings` (ruling 7). `bank_uploads (id, ledger_account_id, file_name, imported, skipped, failed, uploaded_by, created_at)`.
    - `bank_statement_lines (id, ledger_account_id, bank_upload_id, row_number, date, description (500), reference (100, nullable), amount decimal(13,3) ≠ 0, balance decimal(15,3) nullable, description_hash, occurrence, created_at)`; trigger `bank_statement_lines_no_update`. Deleting lines is allowed (upload deletion), guarded by the FK from matches.
    - `bank_reconciliations (id, ledger_account_id, end_date, opening_balance, closing_balance, status open|finished, created_by, finished_by/at, reopened_by/at, timestamps, open_account_id generated)`; unique `(ledger_account_id, end_date)`, unique `open_account_id`; CHECK finished ⇔ `finished_at` and `finished_by` set.
    - `bank_matches (id, bank_reconciliation_id, bank_statement_line_id, ledger_account_id, entry_type (30), entry_id, amount decimal(13,3) ≠ 0, created_by, created_at)`; unique `bank_matches_entry_unique`; index `(entry_type, entry_id)` for the guard. Triggers `bank_matches_no_update`, `bank_matches_insert_guard` and `bank_matches_delete_guard` (refuse when the reconciliation is finished).
12. **Pages, routes and the report.**
    - `ledger.bank-reconciliation` (`/ledger/bank-reconciliation`, `App\Livewire\Ledger\BankReconciliations`): pick a money account (`?account=`); the mapping and upload form, the upload result, the uploads list (delete while unmatched), the reconciliations list, and the start form (opening balance asked only for the first). `ledger.bank-reconciliation.show` (`/ledger/bank-reconciliation/{reconciliation}`, `App\Livewire\Ledger\BankReconciliationShow`): figures, finish / discard / reopen / download, unmatched lines with "Match", the match panel (suggestions, other entries, chosen total, bank-only form), matched lines with "Remove", the in-transit and outstanding lists with links to their sources (`Sources::url`). Both have `can:ledger.view` and re-check in `mount()`; forms show by permission; the Actions re-check. The sidebar's `@can('ledger.view')` block gets "Bank reconciliation" after "VAT returns".
    - **Report** (spec §4): an `.xlsx` of the heading row, the nine figure rows, then the in-transit and outstanding lists (date, source, description, signed amount), every row through `SafeCells::row()`. Downloadable for open and finished reconciliations; audited `report.exported`.
    - Audit events: `bank.statement_uploaded`, `bank.statement_deleted`, `bank.reconciliation_started`, `bank.line_matched`, `bank.line_unmatched`, `bank.line_movement_recorded`, `bank.reconciliation_finished`, `bank.reconciliation_reopened`, `bank.reconciliation_discarded`.

**Worked example** (`bankRecFixture()`: `ledgerFixture()`, start 01/10/2026, at 20/10/2026 10:00), the figures Task 7 asserts.

- **Ledger, Bank (1010):** Admin's opening balance 1000.000 Dr (opening journal, 01/10; not an entry). Entries: 10/10 the head lease payment out −300.000 (description its PO number); 12/10 transfers from Cash on hand "DEP-1" +200.000 and "DEP-2" +300.000; 17/10 manual journal "Office chairs CHQ 7781" −40.000 (Dr 5100); 19/10 transfer "DEP-3" +150.000. Ledger balance at 20/10: 1000 − 300 + 200 + 300 − 40 + 150 = **1310.000**.
- **Statement 1** (opening 1000.000): 11/10 "TRANSFER <PO number>" −300.000; 13/10 "CASH DEPOSIT DEP-1 DEP-2" +500.000; 15/10 "BANK CHARGES OCT" (ref CHG-OCT) −2.500; 20/10 "CREDIT INTEREST" +1.200. Closing **1198.700**.
- **Reconciliation 1**, end 20/10, closing 1198.700, opening 1000.000. Before matching: in transit 200 + 300 + 150 = **650.000**; outstanding 300 + 40 = **340.000**; adjusted 1198.700 + 650 − 340 = **1508.700**; ledger **1310.000**; difference **198.700**; 4 lines unmatched; opening + matched lines 1000.000 ≠ 1198.700. Finish refused with three reasons.
- **Matching:** the head lease is the first suggestion for the −300 line (its PO number is in the description); the +500 line takes DEP-1 + DEP-2; the −2.500 line becomes bank charge "CHG-OCT" (5180 Dr 2.500 / 1010 Cr 2.500); the +1.200 line becomes bank interest "CREDIT INTEREST" (1010 Dr 1.200 / 4300 Cr 1.200).
- **After:** ledger 1310.000 − 2.500 + 1.200 = **1308.700**; in transit DEP-3 **150.000**; outstanding the manual journal **40.000**; adjusted 1198.700 + 150 − 40 = **1308.700**; difference **0.000**; 0 lines unmatched; opening + matched lines 1000 − 300 + 500 − 2.5 + 1.2 = **1198.700** = closing. Finished. Five match rows.
- **Protection:** reversing DEP-1 dated 20/10 is refused ("…up to 20/10/2026…"). A rebuild of October changes journal ids and nothing else: same five matches, same figures.
- **Statement 2** (at 25/10): 20/10 "CREDIT INTEREST" +1.200 (already there: skipped), 21/10 "CASH DEPOSIT DEP-3" +150.000, 22/10 "CHQ 7781" −40.000 → imported 2, skipped 1.
- **Reconciliation 2**, end 25/10, closing 1308.700; opening taken from reconciliation 1: **1198.700**. Match DEP-3 and the manual journal: in transit 0, outstanding 0, adjusted 1308.700, ledger 1308.700, difference 0.000; opening + matched lines 1198.700 + 150 − 40 = 1308.700. Finished. Reconciliation 1 still reads in transit 150.000, outstanding 40.000, difference 0.000.
- **Reopen:** Admin cannot reopen reconciliation 1 (not the latest); Finance cannot reopen at all; Admin reopens reconciliation 2: open again, its two matches kept.

## File Structure

```
database/migrations/2026_12_29_000100_create_bank_reconciliation_tables.php  NEW  5 tables, checks, generated one-open key, 4 triggers (Task 1)
app/Models/BankMapping.php, BankUpload.php, BankStatementLine.php,             NEW  (Task 1)
  BankReconciliation.php, BankMatch.php
app/Integrity/IntegrityCheck.php                                               MOD  EXPECTED_TRIGGERS 77 (Task 1)
tests/Feature/Ledger/LedgerStorageTest.php, MoneyScreensStorageTest.php,       MOD  73 → 77 (Task 1)
  tests/Concurrency/VendorsMigrationTest.php
tests/Feature/Ledger/BankStorageTest.php                                       NEW  (Task 1)
app/Actions/Ledger/ImportBankStatement.php, DeleteBankUpload.php               NEW  (Task 2)
tests/Pest.php                                                                 MOD  bankMapping, bankCsv, bankStatement, bankLine (Task 2); bankRecFixture, bankRec (Task 3)
tests/Feature/Ledger/BankStatementUploadTest.php                               NEW  (Task 2)
app/Ledger/BankEntries.php                                                     NEW  entries, suggestions (Task 3); figures (Task 4); assertReversible (Task 5)
app/Actions/Ledger/StartBankReconciliation.php, MatchBankLine.php,             NEW  (Task 3)
  UnmatchBankLine.php, DiscardBankReconciliation.php
tests/Feature/Ledger/BankMatchingTest.php                                      NEW  (Task 3)
app/Actions/Ledger/RecordBankOnlyLine.php, FinishBankReconciliation.php,       NEW  (Task 4)
  ReopenBankReconciliation.php
tests/Feature/Ledger/BankFinishTest.php                                        NEW  (Task 4)
app/Actions/Payments/RequestPaymentReversal.php, app/Approvals/PaymentReversal.php,         MOD  the guard (Task 5)
  app/Actions/Disbursements/RequestDisbursementReversal.php, app/Approvals/PaymentOutReversal.php,
  app/Actions/Expenses/ReverseExpense.php, app/Actions/Ledger/ReverseMoneyEntry.php, ReverseManualJournal.php
tests/Feature/Ledger/BankReversalGuardTest.php                                 NEW  (Task 5)
app/Livewire/Ledger/BankReconciliations.php + resources/views/livewire/ledger/bank-reconciliations.blade.php         NEW  (Task 6)
app/Livewire/Ledger/BankReconciliationShow.php + resources/views/livewire/ledger/bank-reconciliation-show.blade.php  NEW  (Task 6)
routes/ledger.php, resources/views/layouts/app/sidebar.blade.php               MOD  (Task 6)
tests/Feature/Ledger/BankReconciliationPagesTest.php                           NEW  (Task 6)
tests/Feature/Ledger/BankReconciliationWorkedExampleTest.php                   NEW  (Task 7)
lang/ar.json                                                                   MOD  (Tasks 1–6)
```

The Arabic sweep in `tests/Feature/Ledger/LedgerAccessTest.php` already globs `app/Ledger`, `app/Actions/Ledger`, `app/Livewire/Ledger` and `resources/views/livewire/ledger`, so every new string is checked there without editing it.

---

### Task 1: Storage: mappings, uploads, statement lines, reconciliations, matches and four triggers

**Files:**
- Create: `database/migrations/2026_12_29_000100_create_bank_reconciliation_tables.php`
- Create: `app/Models/BankMapping.php`, `app/Models/BankUpload.php`, `app/Models/BankStatementLine.php`, `app/Models/BankReconciliation.php`, `app/Models/BankMatch.php`
- Modify: `app/Integrity/IntegrityCheck.php`, `tests/Feature/Ledger/LedgerStorageTest.php`, `tests/Feature/Ledger/MoneyScreensStorageTest.php`, `tests/Concurrency/VendorsMigrationTest.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/BankStorageTest.php`

**Interfaces:**
- Consumes: `IntegrityCheck::EXPECTED_TRIGGERS` (73), `rms_trigger_count()`, `ledger_accounts` (1010 Bank is a money account), `User::factory()`.
- Produces:
  - Tables `bank_mappings`, `bank_uploads`, `bank_statement_lines`, `bank_reconciliations`, `bank_matches` (ruling 11), constraint names `bank_reconciliations_one_open`, `bank_reconciliations_finished_chk`, `bank_statement_lines_duplicate_unique`, `bank_statement_lines_amount_chk`, `bank_matches_entry_unique`.
  - Models: `BankMapping`; `BankUpload` (`lines(): HasMany`, `uploader(): BelongsTo`); `BankStatementLine` (`matches(): HasMany`); `BankMatch`; `BankReconciliation` (`account(): BelongsTo`, `finisher(): BelongsTo`, `matches(): HasMany`, `static lockOpen(int $id, string $field): self`, `unmatchedLine(mixed $lineId): BankStatementLine`). Status values are the strings `open` and `finished`.
  - `lockOpen` locks the account row (ruling 10) and throws on `$field` "This reconciliation is finished." `unmatchedLine` throws on `line`: "Choose a statement line of this account.", "This statement line is after the end date.", "This statement line is already matched."
  - `IntegrityCheck::EXPECTED_TRIGGERS = 77`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Ledger/BankStorageTest.php`:

```php
<?php

use App\Integrity\IntegrityCheck;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->bank = (int) LedgerAccount::query()->where('code', '1010')->value('id');
    $this->upload = DB::table('bank_uploads')->insertGetId(['ledger_account_id' => $this->bank, 'file_name' => 's.csv', 'imported' => 2, 'skipped' => 0, 'failed' => 0,
        'uploaded_by' => $this->user->id]);
    $this->line = fn (string $description, array $extra = []) => DB::table('bank_statement_lines')->insertGetId(['ledger_account_id' => $this->bank,
        'bank_upload_id' => $this->upload, 'row_number' => 2, 'date' => '2026-10-10', 'description' => $description, 'amount' => '5.000',
        'description_hash' => hash('sha256', mb_strtolower($description)), 'occurrence' => 1, ...$extra]);
    $this->rec = fn (string $end, string $status) => DB::table('bank_reconciliations')->insertGetId(['ledger_account_id' => $this->bank, 'end_date' => $end,
        'opening_balance' => '0.000', 'closing_balance' => '10.000', 'status' => $status, 'created_by' => $this->user->id,
        'finished_by' => $status === 'finished' ? $this->user->id : null, 'finished_at' => $status === 'finished' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
});

test('statement lines are never updated; a finished reconciliation takes, changes and loses no match', function () {
    $a = ($this->line)('A');
    $b = ($this->line)('B');
    $rec = ($this->rec)('2026-10-20', 'open');
    $match = fn (int $line, int $entry) => DB::table('bank_matches')->insert(['bank_reconciliation_id' => $rec, 'bank_statement_line_id' => $line,
        'ledger_account_id' => $this->bank, 'entry_type' => 'manual', 'entry_id' => $entry, 'amount' => '5.000', 'created_by' => $this->user->id]);
    $match($a, 1);
    DB::table('bank_reconciliations')->where('id', $rec)->update(['status' => 'finished', 'finished_by' => $this->user->id, 'finished_at' => now()]);

    expect(fn () => DB::table('bank_statement_lines')->where('id', $a)->update(['description' => 'X']))->toThrow(QueryException::class, 'write-once')
        ->and(fn () => DB::table('bank_matches')->update(['amount' => '6.000']))->toThrow(QueryException::class, 'write-once')
        ->and(fn () => $match($b, 2))->toThrow(QueryException::class, 'the reconciliation is finished')
        ->and(fn () => DB::table('bank_matches')->delete())->toThrow(QueryException::class, 'the reconciliation is finished');

    DB::table('bank_reconciliations')->where('id', $rec)->update(['status' => 'open', 'finished_by' => null, 'finished_at' => null]);
    expect(DB::table('bank_matches')->delete())->toBe(1)
        ->and(DB::table('bank_statement_lines')->where('id', $b)->delete())->toBe(1);
});

test('one open reconciliation per account, one per end date, a finished one says who and when, and no duplicate statement line', function () {
    ($this->rec)('2026-09-30', 'finished');
    ($this->rec)('2026-10-20', 'open');
    ($this->line)('A');

    expect(fn () => ($this->rec)('2026-10-25', 'open'))->toThrow(QueryException::class, 'bank_reconciliations_one_open')
        ->and(fn () => ($this->rec)('2026-10-20', 'finished'))->toThrow(QueryException::class, 'Duplicate entry')
        ->and(fn () => DB::table('bank_reconciliations')->insert(['ledger_account_id' => $this->bank, 'end_date' => '2026-08-31', 'opening_balance' => '0.000',
            'closing_balance' => '0.000', 'status' => 'finished', 'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class, 'bank_reconciliations_finished_chk')
        ->and(fn () => ($this->line)('a'))->toThrow(QueryException::class, 'bank_statement_lines_duplicate_unique')
        ->and(fn () => ($this->line)('C', ['amount' => '0.000']))->toThrow(QueryException::class, 'bank_statement_lines_amount_chk')
        ->and(($this->line)('A', ['occurrence' => 2]))->toBeInt();
});

test('the trigger count is 77 and the migration will not roll back over rows', function () {
    $migration = require database_path('migrations/2026_12_29_000100_create_bank_reconciliation_tables.php');

    expect(IntegrityCheck::EXPECTED_TRIGGERS)->toBe(77)
        ->and((int) DB::selectOne('SELECT rms_trigger_count() AS n')->n)->toBe(77)
        ->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'Nothing was changed')
        ->and(Schema::hasTable('bank_uploads') && Schema::hasTable('bank_matches'))->toBeTrue();
});
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php artisan test tests/Feature/Ledger/BankStorageTest.php`
Expected: FAIL. Table `bank_uploads` does not exist.

- [ ] **Step 3: The migration** `database/migrations/2026_12_29_000100_create_bank_reconciliation_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank reconciliation spec §2–§5 (plan rulings 3, 4, 7 and 11): a column mapping per money account, statement uploads and
 * their lines, reconciliations and matches. Four triggers: statement lines are never updated; matches are never updated,
 * and a finished reconciliation's matches are neither added nor removed (EXPECTED_TRIGGERS 73 → 77).
 */
return new class extends Migration
{
    /** Children first: the order down() drops them in. */
    private const array TABLES = ['bank_matches', 'bank_reconciliations', 'bank_statement_lines', 'bank_uploads', 'bank_mappings'];

    private const array TRIGGERS = ['bank_statement_lines_no_update', 'bank_matches_no_update', 'bank_matches_insert_guard', 'bank_matches_delete_guard'];

    public function up(): void
    {
        // Plan ruling 7: typed columns, 1-based (1 = A); header_row 0 means none.
        Schema::create('bank_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_account_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('header_row');
            $table->unsignedTinyInteger('date_column');
            $table->string('date_format', 10);
            $table->unsignedTinyInteger('description_column');
            $table->unsignedTinyInteger('reference_column')->nullable();
            $table->string('amount_mode', 10);
            $table->unsignedTinyInteger('amount_column')->nullable();
            $table->unsignedTinyInteger('debit_column')->nullable();
            $table->unsignedTinyInteger('credit_column')->nullable();
            $table->unsignedTinyInteger('balance_column')->nullable();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE bank_mappings
                ADD CONSTRAINT bank_mappings_format_chk CHECK (date_format IN ('d/m/Y', 'Y-m-d', 'd-M-Y')),
                ADD CONSTRAINT bank_mappings_mode_chk CHECK (
                    (amount_mode = 'signed' AND amount_column IS NOT NULL AND debit_column IS NULL AND credit_column IS NULL)
                    OR (amount_mode = 'split' AND amount_column IS NULL AND debit_column IS NOT NULL AND credit_column IS NOT NULL))
            SQL);

        Schema::create('bank_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->string('file_name', 255);
            $table->unsignedInteger('imported');
            $table->unsignedInteger('skipped');
            $table->unsignedInteger('failed');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ledger_account_id', 'id']);
        });

        // Plan ruling 7: a duplicate is the same account, date, amount and description at the same occurrence within its file.
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_upload_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->date('date');
            $table->string('description', 500);
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 13, 3);
            $table->decimal('balance', 15, 3)->nullable();
            $table->char('description_hash', 64);
            $table->unsignedSmallInteger('occurrence');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['ledger_account_id', 'date', 'amount', 'description_hash', 'occurrence'], 'bank_statement_lines_duplicate_unique');
        });
        DB::statement('ALTER TABLE bank_statement_lines ADD CONSTRAINT bank_statement_lines_amount_chk CHECK (amount <> 0)');
        DB::unprepared("CREATE TRIGGER bank_statement_lines_no_update BEFORE UPDATE ON bank_statement_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bank_statement_lines are write-once'");

        // Plan ruling 4: one open reconciliation per account (open_account_id is NULL once finished), end dates unique per account.
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->date('end_date');
            $table->decimal('opening_balance', 15, 3);
            $table->decimal('closing_balance', 15, 3);
            $table->string('status', 10);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('finished_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();
            $table->unique(['ledger_account_id', 'end_date']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE bank_reconciliations
                ADD COLUMN open_account_id BIGINT UNSIGNED AS (IF(status = 'open', ledger_account_id, NULL)) STORED,
                ADD UNIQUE KEY bank_reconciliations_one_open (open_account_id),
                ADD CONSTRAINT bank_reconciliations_status_chk CHECK (status IN ('open', 'finished')),
                ADD CONSTRAINT bank_reconciliations_finished_chk CHECK ((status = 'finished') = (finished_at IS NOT NULL AND finished_by IS NOT NULL))
            SQL);

        // Plan rulings 1 and 3: an entry is its source's key; a row exists only while the match does.
        Schema::create('bank_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_reconciliation_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_statement_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->string('entry_type', 30);
            $table->unsignedBigInteger('entry_id');
            $table->decimal('amount', 13, 3);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['ledger_account_id', 'entry_type', 'entry_id'], 'bank_matches_entry_unique');
            $table->index(['entry_type', 'entry_id']);
        });
        DB::statement('ALTER TABLE bank_matches ADD CONSTRAINT bank_matches_amount_chk CHECK (amount <> 0)');
        DB::unprepared("CREATE TRIGGER bank_matches_no_update BEFORE UPDATE ON bank_matches FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bank_matches are write-once'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bank_matches_insert_guard BEFORE INSERT ON bank_matches FOR EACH ROW
            BEGIN
                IF (SELECT status FROM bank_reconciliations WHERE id = NEW.bank_reconciliation_id) <> 'open' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bank_matches: the reconciliation is finished';
                END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bank_matches_delete_guard BEFORE DELETE ON bank_matches FOR EACH ROW
            BEGIN
                IF (SELECT status FROM bank_reconciliations WHERE id = OLD.bank_reconciliation_id) <> 'open' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bank_matches: the reconciliation is finished';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Cannot roll back: {$table} has rows. Nothing was changed.");
            }
        }
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
```

- [ ] **Step 4: The models.** `app/Models/BankMapping.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bank reconciliation spec §2 (plan ruling 7): how one money account's statement files are laid out. Columns are 1-based
 * (1 = A); header_row 0 means the file has no header. Written only by ImportBankStatement.
 *
 * @property int $id
 * @property int $ledger_account_id
 * @property int $header_row
 * @property int $date_column
 * @property string $date_format d/m/Y | Y-m-d | d-M-Y
 * @property int $description_column
 * @property int|null $reference_column
 * @property string $amount_mode signed | split
 * @property int|null $amount_column
 * @property int|null $debit_column
 * @property int|null $credit_column
 * @property int|null $balance_column
 * @property int $updated_by
 */
class BankMapping extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['header_row' => 'integer', 'date_column' => 'integer', 'description_column' => 'integer', 'reference_column' => 'integer',
            'amount_column' => 'integer', 'debit_column' => 'integer', 'credit_column' => 'integer', 'balance_column' => 'integer'];
    }
}
```

`app/Models/BankUpload.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bank reconciliation spec §2: one uploaded statement file and its counts. Written by ImportBankStatement; deleted with its
 * lines by DeleteBankUpload while none of them is matched.
 *
 * @property int $id
 * @property int $ledger_account_id
 * @property string $file_name
 * @property int $imported
 * @property int $skipped
 * @property int $failed
 * @property int $uploaded_by
 * @property CarbonImmutable $created_at
 * @property-read User $uploader
 */
class BankUpload extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['imported' => 'integer', 'skipped' => 'integer', 'failed' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<BankStatementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
```

`app/Models/BankStatementLine.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bank reconciliation spec §2: one statement row. Amount + is money in, − money out. Never updated
 * (bank_statement_lines_no_update); deleted only with its upload.
 *
 * @property int $id
 * @property int $ledger_account_id
 * @property int $bank_upload_id
 * @property int $row_number
 * @property CarbonImmutable $date
 * @property string $description
 * @property string|null $reference
 * @property string $amount
 * @property string|null $balance
 * @property string $description_hash
 * @property int $occurrence
 */
class BankStatementLine extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'amount' => 'decimal:3', 'balance' => 'decimal:3', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<BankMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(BankMatch::class);
    }
}
```

`app/Models/BankMatch.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bank reconciliation plan rulings 1 and 3: a statement line matched to one ledger entry, named by its key (entry_type,
 * entry_id); amount is the entry's net on the account when matched. Never updated; not added or removed while its
 * reconciliation is finished (two guard triggers).
 *
 * @property int $id
 * @property int $bank_reconciliation_id
 * @property int $bank_statement_line_id
 * @property int $ledger_account_id
 * @property string $entry_type a journal source type, or "manual"
 * @property int $entry_id
 * @property string $amount
 * @property int $created_by
 */
class BankMatch extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entry_id' => 'integer', 'amount' => 'decimal:3'];
    }
}
```

`app/Models/BankReconciliation.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation spec §3–§4 (plan rulings 4 and 6). Status "open" or "finished"; open_account_id is a generated column
 * the Actions never write.
 *
 * @property int $id
 * @property int $ledger_account_id
 * @property CarbonImmutable $end_date
 * @property string $opening_balance
 * @property string $closing_balance
 * @property string $status
 * @property int $created_by
 * @property int|null $finished_by
 * @property CarbonImmutable|null $finished_at
 * @property int|null $reopened_by
 * @property CarbonImmutable|null $reopened_at
 * @property-read LedgerAccount $account
 * @property-read User|null $finisher
 */
class BankReconciliation extends Model
{
    protected $guarded = ['id', 'open_account_id'];

    protected function casts(): array
    {
        return ['end_date' => 'immutable_date', 'opening_balance' => 'decimal:3', 'closing_balance' => 'decimal:3', 'finished_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function finisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finished_by');
    }

    /** @return HasMany<BankMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(BankMatch::class);
    }

    /**
     * Plan ruling 10: locks the account's ledger_accounts row (every bank reconciliation writer does), then returns the
     * reconciliation re-read under the lock, refusing on $field one that is not open. Call inside a transaction.
     */
    public static function lockOpen(int $id, string $field): self
    {
        LedgerAccount::query()->lockForUpdate()->findOrFail(self::query()->findOrFail($id)->ledger_account_id);
        $rec = self::query()->findOrFail($id);
        if ($rec->status !== 'open') {
            throw ValidationException::withMessages([$field => __('This reconciliation is finished.')]);
        }

        return $rec;
    }

    /** The statement line $lineId on this account, dated by the end date and not matched yet; a message on "line" otherwise. */
    public function unmatchedLine(mixed $lineId): BankStatementLine
    {
        $id = is_int($lineId) || (is_string($lineId) && ctype_digit($lineId) && strlen($lineId) < 19) ? (int) $lineId : 0;
        $line = BankStatementLine::query()->where('ledger_account_id', $this->ledger_account_id)->find($id)
            ?? throw ValidationException::withMessages(['line' => __('Choose a statement line of this account.')]);
        if ($line->date->toDateString() > $this->end_date->toDateString()) {
            throw ValidationException::withMessages(['line' => __('This statement line is after the end date.')]);
        }
        if (BankMatch::query()->where('bank_statement_line_id', $line->id)->exists()) {
            throw ValidationException::withMessages(['line' => __('This statement line is already matched.')]);
        }

        return $line;
    }
}
```

- [ ] **Step 5: The trigger count.**
  - In `app/Integrity/IntegrityCheck.php`, replace the `EXPECTED_TRIGGERS` line with:

```php
    public const int EXPECTED_TRIGGERS = 77; // 38 at the end of M3a + 2 (disbursements) + 8 (deposit settlements) + 1 (invoice line charge) + 3 (amendments; the two insert triggers are replaced, not added) + 1 (credit source) + 2 (owner payables) + 2 (owner charges) + 2 (owner statements) + 2 (payment tenders) + 6 (ledger journals and journal lines) + 6 (disbursement expenses, cash movements, VAT returns; cheques_guard replaced, not added) + 4 (bank statement lines, bank matches)
```

  - In `tests/Feature/Ledger/LedgerStorageTest.php`, replace `->and(IntegrityCheck::EXPECTED_TRIGGERS)->toBe(73)` with `->and(IntegrityCheck::EXPECTED_TRIGGERS)->toBe(77)`, and `->and((int) DB::selectOne('SELECT rms_trigger_count() AS n')->n)->toBe(73);` with `->and((int) DB::selectOne('SELECT rms_trigger_count() AS n')->n)->toBe(77);`.
  - In `tests/Feature/Ledger/MoneyScreensStorageTest.php`, replace `and the trigger count is 73', function () {` with `and the trigger count is 77', function () {`, and make the same two replacements as in LedgerStorageTest.
  - In `tests/Concurrency/VendorsMigrationTest.php`, replace `$unchanged = fn () => expect((int) DB::selectOne('SELECT rms_trigger_count() AS n')->n)->toBe(73)` with `$unchanged = fn () => expect((int) DB::selectOne('SELECT rms_trigger_count() AS n')->n)->toBe(77)`.

- [ ] **Step 6: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "This reconciliation is finished.": "هذه التسوية منتهية.",
    "Choose a statement line of this account.": "اختر سطراً من كشف هذا الحساب.",
    "This statement line is after the end date.": "سطر الكشف هذا بعد تاريخ النهاية.",
    "This statement line is already matched.": "سطر الكشف هذا مطابَق بالفعل.",
```

- [ ] **Step 7: Migrate the dev DB.**

Run: `php artisan migrate --database=migrator --force`
Expected: `2026_12_29_000100_create_bank_reconciliation_tables ... DONE`.

- [ ] **Step 8: Run the tests.**

Run: `php artisan test tests/Feature/Ledger/BankStorageTest.php tests/Feature/Ledger/LedgerStorageTest.php tests/Feature/Ledger/MoneyScreensStorageTest.php tests/Feature/Integrity/IntegrityCheckTest.php`
Expected: PASS. IntegrityCheckTest sees 77.

Then, on its own: `php artisan test tests/Concurrency/VendorsMigrationTest.php`
Expected: PASS (the money migration's `down()` still refuses, now with 77 triggers in place).

- [ ] **Step 9: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add database/migrations/2026_12_29_000100_create_bank_reconciliation_tables.php app/Models/BankMapping.php app/Models/BankUpload.php app/Models/BankStatementLine.php app/Models/BankReconciliation.php app/Models/BankMatch.php app/Integrity/IntegrityCheck.php tests/Feature/Ledger/BankStorageTest.php tests/Feature/Ledger/LedgerStorageTest.php tests/Feature/Ledger/MoneyScreensStorageTest.php tests/Concurrency/VendorsMigrationTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
Bank reconciliation storage: mappings, uploads, statement lines, reconciliations, matches, four triggers (77)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Statement upload: the mapping, parsing, duplicates, bad rows, limits and deleting an upload

**Files:**
- Create: `app/Actions/Ledger/ImportBankStatement.php`, `app/Actions/Ledger/DeleteBankUpload.php`
- Modify: `tests/Pest.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/BankStatementUploadTest.php`

**Interfaces:**
- Consumes: Task 1's tables and models; `PermissionName::LedgerManage`; `Fils::fromDecimal/toDecimal`; `SimpleExcelReader`; `matrixUser()`.
- Produces:
  - `ImportBankStatement::handle(User $actor, mixed $accountId, string $path, string $type, string $fileName, mixed $mapping): array{upload: BankUpload, failures: list<array{row: int, reason: string}>}`. `$type` is `csv` or `xlsx`. `$mapping` keys: `header_row, date_column, date_format, description_column, reference_column, amount_mode, amount_column, debit_column, credit_column, balance_column`. Error keys: `account`, `file`, the mapping keys.
  - `ImportBankStatement::MAX_ROWS = 5000`; `ImportBankStatement::DATE_FORMATS = ['d/m/Y' => 'dd/mm/yyyy', 'Y-m-d' => 'yyyy-mm-dd', 'd-M-Y' => 'dd-Mon-yyyy']`.
  - `DeleteBankUpload::handle(User $actor, BankUpload $upload): void`; error key `upload`.
  - Pest: `bankMapping(array $overrides = []): array`; `bankCsv(array $rows, array $header = [...]): string`; `bankStatement(User $by, string $code, array $rows, array $mapping = []): array`; `bankLine(string $description): BankStatementLine`.

- [ ] **Step 1: The test helpers.** In `tests/Pest.php` add `use App\Actions\Ledger\ImportBankStatement;` and `use App\Models\BankStatementLine;` to the imports (keep them sorted; `LedgerAccount`, `User` and `Str` are already imported), and append:

```php
/** The statement layout the bank reconciliation tests upload: Date (dd/mm/yyyy), Description, Reference, signed Amount; header on row 1. */
function bankMapping(array $overrides = []): array
{
    return ['header_row' => 1, 'date_column' => 1, 'date_format' => 'd/m/Y', 'description_column' => 2, 'reference_column' => 3, 'amount_mode' => 'signed',
        'amount_column' => 4, 'debit_column' => null, 'credit_column' => null, 'balance_column' => null, ...$overrides];
}

/** A temporary CSV of $header and $rows. */
function bankCsv(array $rows, array $header = ['Date', 'Description', 'Reference', 'Amount']): string
{
    $path = sys_get_temp_dir().'/bank-'.Str::uuid().'.csv';
    $handle = fopen($path, 'w');
    foreach ([$header, ...$rows] as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }
    fclose($handle);

    return $path;
}

/** Uploads $rows ([dd/mm/yyyy, description, reference, signed amount]) for the account with code $code through ImportBankStatement. */
function bankStatement(User $by, string $code, array $rows, array $mapping = []): array
{
    $path = bankCsv($rows);
    try {
        return app(ImportBankStatement::class)->handle($by, (string) LedgerAccount::query()->where('code', $code)->value('id'), $path, 'csv', 'statement.csv',
            bankMapping($mapping));
    } finally {
        unlink($path);
    }
}

/** The one statement line with this description (bank reconciliation tests). */
function bankLine(string $description): BankStatementLine
{
    return BankStatementLine::query()->where('description', $description)->sole();
}
```

- [ ] **Step 2: Write the failing test** `tests/Feature/Ledger/BankStatementUploadTest.php`:

```php
<?php

use App\Actions\Ledger\DeleteBankUpload;
use App\Actions\Ledger\ImportBankStatement;
use App\Enums\RoleName;
use App\Models\BankMapping;
use App\Models\BankStatementLine;
use App\Models\BankUpload;
use App\Models\Building;
use App\Models\LedgerAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelWriter;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $building);
    $this->admin = matrixUser(RoleName::Admin, $building);
    $this->bank = (string) LedgerAccount::query()->where('code', '1010')->value('id');
    $this->lines = fn () => BankStatementLine::query()->orderBy('id')->get()
        ->map(fn (BankStatementLine $l) => [$l->row_number, $l->date->toDateString(), $l->description, $l->reference, $l->amount])->all();
});

test('a signed CSV becomes statement lines, and the mapping is saved for the account', function () {
    $out = bankStatement($this->finance, '1010', [
        ['05/10/2026', 'CASH DEPOSIT', 'D-1', '1,250.500'],
        ['6/10/2026', '  TRANSFER   OUT ', '', '(300)'],
        ['07/10/2026', 'CHARGES', '', '-2.5'],
    ]);

    expect([$out['upload']->imported, $out['upload']->skipped, $out['upload']->failed, $out['failures']])->toBe([3, 0, 0, []])
        ->and(($this->lines)())->toBe([
            [2, '2026-10-05', 'CASH DEPOSIT', 'D-1', '1250.500'],
            [3, '2026-10-06', 'TRANSFER OUT', null, '-300.000'],
            [4, '2026-10-07', 'CHARGES', null, '-2.500'],
        ])
        ->and(BankMapping::query()->sole()->only(['ledger_account_id', 'header_row', 'date_column', 'date_format', 'description_column', 'reference_column',
            'amount_mode', 'amount_column', 'debit_column', 'credit_column', 'balance_column']))
        ->toBe(['ledger_account_id' => (int) $this->bank, 'header_row' => 1, 'date_column' => 1, 'date_format' => 'd/m/Y', 'description_column' => 2,
            'reference_column' => 3, 'amount_mode' => 'signed', 'amount_column' => 4, 'debit_column' => null, 'credit_column' => null, 'balance_column' => null]);
});

test('debit and credit columns, the other date formats, and an XLSX with a real date and numbers', function () {
    $import = fn (string $path, string $type, array $mapping) => app(ImportBankStatement::class)->handle($this->finance, $this->bank, $path, $type, "s.{$type}", $mapping);
    $import(bankCsv([['2026-10-05', 'IN', '', '', '100.000'], ['2026-10-06', 'OUT', '', '40', '']], ['Date', 'Description', 'Ref', 'Debit', 'Credit']), 'csv',
        bankMapping(['date_format' => 'Y-m-d', 'amount_mode' => 'split', 'amount_column' => null, 'debit_column' => 4, 'credit_column' => 5]));
    $import(bankCsv([['07-Oct-2026', 'MON', '', '5']]), 'csv', bankMapping(['date_format' => 'd-M-Y']));
    $xlsx = sys_get_temp_dir().'/bank-'.Str::uuid().'.xlsx';
    $writer = SimpleExcelWriter::create($xlsx);
    $writer->addRow(new Row([Cell::fromValue('Statement of account')]));
    $writer->addRow(new Row([Cell::fromValue('Date'), Cell::fromValue('Description'), Cell::fromValue('Reference'), Cell::fromValue('Amount')]));
    $writer->addRow(new Row([Cell::fromValue(new DateTimeImmutable('2026-10-08'), (new Style)->setFormat('dd/mm/yyyy')), Cell::fromValue('XLSX IN'),
        Cell::fromValue(12345), Cell::fromValue(99.5)]));
    $writer->close();
    $import($xlsx, 'xlsx', bankMapping(['header_row' => 2]));

    expect(($this->lines)())->toBe([
        [2, '2026-10-05', 'IN', null, '100.000'],
        [3, '2026-10-06', 'OUT', null, '-40.000'],
        [2, '2026-10-07', 'MON', null, '5.000'],
        [3, '2026-10-08', 'XLSX IN', '12345', '99.500'],
    ])->and(BankMapping::query()->sole()->amount_mode)->toBe('signed');
});

test('a line already on the account is skipped; identical lines in one file are kept as separate occurrences', function () {
    $rows = [['05/10/2026', 'SMS FEE', '', '-0.500'], ['05/10/2026', 'SMS FEE', '', '-0.500'], ['06/10/2026', 'DEPOSIT', '', '20']];
    $first = bankStatement($this->finance, '1010', $rows);
    $again = bankStatement($this->finance, '1010', $rows);
    $overlap = bankStatement($this->finance, '1010', [...$rows, ['07/10/2026', 'DEPOSIT', '', '20'], ['05/10/2026', 'sms  fee', '', '-0.500']]);
    $cash = bankStatement($this->finance, '1000', $rows);

    expect([$first['upload']->imported, $first['upload']->skipped])->toBe([3, 0])
        ->and([$again['upload']->imported, $again['upload']->skipped])->toBe([0, 3])
        ->and([$overlap['upload']->imported, $overlap['upload']->skipped])->toBe([2, 3])
        ->and([$cash['upload']->imported, $cash['upload']->skipped])->toBe([3, 0])
        ->and(BankStatementLine::query()->where('ledger_account_id', (int) $this->bank)->count())->toBe(5);
});

test('bad rows are reported with their reason and the rest import', function () {
    $out = bankStatement($this->finance, '1010', [
        ['31/02/2026', 'BAD DATE', '', '5'],
        ['2026-10-05', 'WRONG FORMAT', '', '5'],
        ['05/10/2026', 'BAD AMOUNT', '', 'abc'],
        ['05/10/2026', 'FOUR DECIMALS', '', '1.2345'],
        ['05/10/2026', 'ZERO', '', '0.000'],
        ['', '', '', ''],
        ['05/10/2026', 'GOOD', '', '7'],
    ]);
    $split = bankStatement($this->finance, '1000', [['05/10/2026', 'BOTH', '3', '4']],
        ['amount_mode' => 'split', 'amount_column' => null, 'reference_column' => null, 'debit_column' => 3, 'credit_column' => 4]);

    expect($out['failures'])->toBe([
        ['row' => 2, 'reason' => 'The date is not a dd/mm/yyyy date.'],
        ['row' => 3, 'reason' => 'The date is not a dd/mm/yyyy date.'],
        ['row' => 4, 'reason' => 'The amount is not a number with up to 3 decimals.'],
        ['row' => 5, 'reason' => 'The amount is not a number with up to 3 decimals.'],
        ['row' => 6, 'reason' => 'The amount is empty or zero.'],
    ])->and([$out['upload']->imported, $out['upload']->failed])->toBe([1, 5])
        ->and(bankLine('GOOD')->row_number)->toBe(8)
        ->and($split['failures'])->toBe([['row' => 2, 'reason' => 'Fill either the debit or the credit, not both.']]);
});

test('over 5000 rows, an unreadable file, another type, a column used twice and a non-money account are refused, saving nothing', function () {
    $junk = sys_get_temp_dir().'/bank-'.Str::uuid().'.xlsx';
    file_put_contents($junk, 'not a spreadsheet');
    $import = fn (string $type) => app(ImportBankStatement::class)->handle($this->finance, $this->bank, $junk, $type, "junk.{$type}", bankMapping());

    expect(fn () => bankStatement($this->finance, '1010', array_fill(0, 5001, ['05/10/2026', 'X', '', '1'])))
        ->toThrow(ValidationException::class, 'The statement has more than 5000 rows. Split it into smaller files.')
        ->and(fn () => $import('xlsx'))->toThrow(ValidationException::class, 'This file cannot be read as .xlsx or .csv.')
        ->and(fn () => $import('pdf'))->toThrow(ValidationException::class, 'Upload the statement as a .csv or .xlsx file.')
        ->and(fn () => bankStatement($this->finance, '1010', [], ['description_column' => 1]))->toThrow(ValidationException::class, 'Use each column for one thing only.')
        ->and(fn () => bankStatement($this->finance, '1010', [], ['date_column' => ['x'], 'amount_mode' => 'other']))->toThrow(ValidationException::class)
        ->and(fn () => bankStatement($this->finance, '4000', []))->toThrow(ValidationException::class, 'Choose an active money account.')
        ->and(BankUpload::query()->count() + BankStatementLine::query()->count() + BankMapping::query()->count())->toBe(0);
    unlink($junk);
});

test('cells that look like formulas are stored as plain text', function () {
    bankStatement($this->finance, '1010', [['05/10/2026', '=HYPERLINK("http://x.test","pay")', '+973', '5'], ['05/10/2026', '@SUM(A1)', '-1', '6']]);

    expect(BankStatementLine::query()->orderBy('id')->get(['description', 'reference'])->toArray())->toBe([
        ['description' => '=HYPERLINK("http://x.test","pay")', 'reference' => '+973'],
        ['description' => '@SUM(A1)', 'reference' => '-1'],
    ]);
});

test('an upload is deleted with its lines only while none is matched; only Finance uploads and deletes', function () {
    $first = bankStatement($this->finance, '1010', [['05/10/2026', 'A', '', '5']]);
    $second = bankStatement($this->finance, '1010', [['06/10/2026', 'B', '', '6']]);
    $rec = DB::table('bank_reconciliations')->insertGetId(['ledger_account_id' => (int) $this->bank, 'end_date' => '2026-10-20', 'opening_balance' => '0.000',
        'closing_balance' => '5.000', 'status' => 'open', 'created_by' => $this->finance->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('bank_matches')->insert(['bank_reconciliation_id' => $rec, 'bank_statement_line_id' => bankLine('A')->id, 'ledger_account_id' => (int) $this->bank,
        'entry_type' => 'manual', 'entry_id' => 1, 'amount' => '5.000', 'created_by' => $this->finance->id]);

    expect(fn () => app(DeleteBankUpload::class)->handle($this->finance, $first['upload']))
        ->toThrow(ValidationException::class, 'Lines of this upload are matched. Remove those matches first.')
        ->and(fn () => app(DeleteBankUpload::class)->handle($this->admin, $second['upload']))->toThrow(AuthorizationException::class)
        ->and(fn () => bankStatement($this->admin, '1010', []))->toThrow(AuthorizationException::class);

    app(DeleteBankUpload::class)->handle($this->finance, $second['upload']);
    expect(BankUpload::query()->pluck('id')->all())->toBe([$first['upload']->id])
        ->and(BankStatementLine::query()->pluck('description')->all())->toBe(['A']);
});
```

- [ ] **Step 3: Run it to see it fail.**

Run: `php artisan test tests/Feature/Ledger/BankStatementUploadTest.php`
Expected: FAIL. Class `App\Actions\Ledger\ImportBankStatement` not found.

- [ ] **Step 4: The upload Action** `app/Actions/Ledger/ImportBankStatement.php`:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\BankMapping;
use App\Models\BankUpload;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Fils;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

/**
 * Bank reconciliation spec §2 (plan ruling 7): Finance uploads a CSV or XLSX statement for a money account. The column
 * mapping is saved for the account. Each row becomes an immutable statement line; a row already on the account (same date,
 * amount, description and occurrence within its file) is skipped; a bad row is reported with its reason and the rest
 * import. Cells are read as values, never formulas, and stored as plain text.
 *
 * @phpstan-type Mapping array{header_row: int, date_column: int, date_format: string, description_column: int, reference_column: int|null,
 *     amount_mode: string, amount_column: int|null, debit_column: int|null, credit_column: int|null, balance_column: int|null}
 */
final class ImportBankStatement
{
    public const int MAX_ROWS = 5000;

    /** PHP format => what the page shows. "d/m/Y" reads 05/10/2026 and 5/10/2026 alike (plan ruling 7). */
    public const array DATE_FORMATS = ['d/m/Y' => 'dd/mm/yyyy', 'Y-m-d' => 'yyyy-mm-dd', 'd-M-Y' => 'dd-Mon-yyyy'];

    /** The largest |amount| decimal(13,3) holds, in fils. */
    private const int MAX_FILS = 9_999_999_999_999;

    /**
     * @param  mixed  $mapping  header_row, date_column, date_format, description_column, reference_column, amount_mode, amount_column, debit_column, credit_column, balance_column
     * @return array{upload: BankUpload, failures: list<array{row: int, reason: string}>}
     */
    public function handle(User $actor, mixed $accountId, string $path, string $type, string $fileName, mixed $mapping): array
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        $id = is_int($accountId) || (is_string($accountId) && ctype_digit($accountId) && strlen($accountId) < 19) ? (int) $accountId : 0;
        $account = LedgerAccount::query()->whereKey($id)->where('is_money', true)->where('active', true)->first()
            ?? throw ValidationException::withMessages(['account' => __('Choose an active money account.')]);
        $map = self::mapping($mapping);
        if (! in_array($type, ['csv', 'xlsx'], true)) {
            throw ValidationException::withMessages(['file' => __('Upload the statement as a .csv or .xlsx file.')]);
        }

        $parsed = [];
        $failures = [];
        foreach (self::read($path, $type, $map['header_row']) as $row => $cells) {
            try {
                $parsed[] = ['row' => $row, ...self::parse($cells, $map)];
            } catch (InvalidArgumentException $e) {
                $failures[] = ['row' => $row, 'reason' => $e->getMessage()];
            }
        }

        return DB::transaction(function () use ($actor, $account, $map, $fileName, $parsed, $failures) {
            LedgerAccount::query()->lockForUpdate()->findOrFail($account->id); // plan ruling 10: duplicate checks of two uploads see each other
            BankMapping::query()->firstOrNew(['ledger_account_id' => $account->id])->forceFill([...$map, 'updated_by' => $actor->id])->save();
            $upload = (new BankUpload)->forceFill(['ledger_account_id' => $account->id, 'file_name' => mb_substr($fileName, 0, 255), 'imported' => 0, 'skipped' => 0,
                'failed' => count($failures), 'uploaded_by' => $actor->id]);
            $upload->save();

            $seen = [];
            $imported = 0;
            foreach ($parsed as $p) {
                $amount = Fils::toDecimal($p['amount']);
                $hash = hash('sha256', mb_strtolower($p['description']));
                $same = $p['date'].'|'.$amount.'|'.$hash;
                $seen[$same] = ($seen[$same] ?? 0) + 1;
                $key = ['ledger_account_id' => $account->id, 'date' => $p['date'], 'amount' => $amount, 'description_hash' => $hash, 'occurrence' => $seen[$same]];
                if (DB::table('bank_statement_lines')->where($key)->exists()) {
                    continue;
                }
                DB::table('bank_statement_lines')->insert([...$key, 'bank_upload_id' => $upload->id, 'row_number' => $p['row'], 'description' => $p['description'],
                    'reference' => $p['reference'], 'balance' => $p['balance'] === null ? null : Fils::toDecimal($p['balance']), 'created_at' => now()]);
                $imported++;
            }
            $upload->forceFill(['imported' => $imported, 'skipped' => count($parsed) - $imported])->save();
            Audit::log('bank.statement_uploaded', $upload, new: ['account' => $account->code, 'file' => $upload->file_name, 'imported' => $imported,
                'skipped' => count($parsed) - $imported, 'failed' => count($failures)], causer: $actor);

            return ['upload' => $upload, 'failures' => $failures];
        });
    }

    /** @return Mapping */
    private static function mapping(mixed $mapping): array
    {
        $m = is_array($mapping) ? $mapping : [];
        $split = ($m['amount_mode'] ?? null) === 'split';
        $column = ['nullable', 'integer', 'between:1,26'];
        $v = Validator::make($m, [
            'header_row' => ['required', 'integer', 'between:0,50'],
            'date_column' => ['required', 'integer', 'between:1,26'],
            'date_format' => ['required', 'string', Rule::in(array_keys(self::DATE_FORMATS))],
            'description_column' => ['required', 'integer', 'between:1,26'],
            'reference_column' => $column,
            'amount_mode' => ['required', 'string', Rule::in(['signed', 'split'])],
            'amount_column' => [Rule::requiredIf(! $split), ...$column],
            'debit_column' => [Rule::requiredIf($split), ...$column],
            'credit_column' => [Rule::requiredIf($split), ...$column],
            'balance_column' => $column,
        ])->validate();
        $int = fn (string $key): ?int => isset($v[$key]) && $v[$key] !== '' ? (int) $v[$key] : null;
        $map = [
            'header_row' => (int) $v['header_row'],
            'date_column' => (int) $v['date_column'],
            'date_format' => (string) $v['date_format'],
            'description_column' => (int) $v['description_column'],
            'reference_column' => $int('reference_column'),
            'amount_mode' => $split ? 'split' : 'signed',
            'amount_column' => $split ? null : $int('amount_column'),
            'debit_column' => $split ? $int('debit_column') : null,
            'credit_column' => $split ? $int('credit_column') : null,
            'balance_column' => $int('balance_column'),
        ];
        $used = array_values(array_filter([$map['date_column'], $map['description_column'], $map['reference_column'], $map['amount_column'],
            $map['debit_column'], $map['credit_column'], $map['balance_column']]));
        if (count($used) !== count(array_unique($used))) {
            throw ValidationException::withMessages(['date_column' => __('Use each column for one thing only.')]);
        }

        return $map;
    }

    /** @return array<int, list<mixed>> line number in the file => cells, after the header row; blank rows left out */
    private static function read(string $path, string $type, int $headerRow): array
    {
        $rows = [];
        $tooMany = false;
        try {
            foreach (SimpleExcelReader::create($path, $type)->noHeaderRow()->preserveEmptyRows()->getRows() as $index => $cells) {
                $line = (int) $index + 1;
                $filled = array_filter($cells, fn (mixed $c) => ! ($c === null || (is_string($c) && trim($c) === '')));
                if ($line <= $headerRow || $filled === []) {
                    continue;
                }
                if (count($rows) === self::MAX_ROWS) {
                    $tooMany = true;
                    break;
                }
                $rows[$line] = array_values($cells);
            }
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => __('This file cannot be read as .xlsx or .csv.')]);
        }
        if ($tooMany) {
            throw ValidationException::withMessages(['file' => __('The statement has more than :n rows. Split it into smaller files.', ['n' => self::MAX_ROWS])]);
        }

        return $rows;
    }

    /**
     * @param  list<mixed>  $cells
     * @param  Mapping  $map
     * @return array{date: string, description: string, reference: string|null, amount: int, balance: int|null}
     *
     * @throws InvalidArgumentException with the reason shown for the row
     */
    private static function parse(array $cells, array $map): array
    {
        $cell = fn (?int $column): mixed => $column === null ? null : ($cells[$column - 1] ?? null);
        $date = self::date($cell($map['date_column']), $map['date_format'])
            ?? throw new InvalidArgumentException(__('The date is not a :format date.', ['format' => self::DATE_FORMATS[$map['date_format']]]));
        if ($map['amount_mode'] === 'split') {
            $debit = self::fils($cell($map['debit_column'])) ?? 0;
            $credit = self::fils($cell($map['credit_column'])) ?? 0;
            if ($debit !== 0 && $credit !== 0) {
                throw new InvalidArgumentException(__('Fill either the debit or the credit, not both.'));
            }
            $amount = abs($credit) - abs($debit);
        } else {
            $amount = self::fils($cell($map['amount_column'])) ?? 0;
        }
        if ($amount === 0) {
            throw new InvalidArgumentException(__('The amount is empty or zero.'));
        }

        return [
            'date' => $date,
            'description' => self::text($cell($map['description_column']), 500),
            'reference' => self::text($cell($map['reference_column']), 100) ?: null,
            'amount' => $amount,
            'balance' => self::fils($cell($map['balance_column']), __('The balance is not a number with up to 3 decimals.')),
        ];
    }

    /** Y-m-d, or null. An XLSX date cell is used as it is; text must match $format exactly (31/02 fails), year 2000–2099. */
    private static function date(mixed $value, string $format): ?string
    {
        if ($value instanceof DateTimeInterface) {
            $date = DateTimeImmutable::createFromInterface($value);
        } elseif (is_string($value) && trim($value) !== '') {
            $date = DateTimeImmutable::createFromFormat('!'.$format, trim($value));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                return null;
            }
        } else {
            return null;
        }
        $year = (int) $date->format('Y');

        return $year >= 2000 && $year <= 2099 ? $date->format('Y-m-d') : null;
    }

    /**
     * Fils from a cell, or null when it is empty. Takes 1,250.500, (300.000), +5, -2.5 and XLSX numbers.
     *
     * @throws InvalidArgumentException when the cell is not an amount with at most 3 decimals
     */
    private static function fils(mixed $value, ?string $reason = null): ?int
    {
        $reason ??= __('The amount is not a number with up to 3 decimals.');
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            $scaled = round($value * 1000);
            if (abs($value * 1000 - $scaled) > 0.000001 || abs($scaled) > self::MAX_FILS) {
                throw new InvalidArgumentException($reason);
            }

            return (int) $scaled;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException($reason);
        }
        $text = str_replace([',', ' ', "\u{00A0}"], '', trim($value));
        $negative = false;
        if (preg_match('/^\((.+)\)$/', $text, $m) === 1) {
            $negative = true;
            $text = $m[1];
        }
        try {
            $fils = Fils::fromDecimal(ltrim($text, '+'));
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException($reason);
        }
        if (abs($fils) > self::MAX_FILS) {
            throw new InvalidArgumentException($reason);
        }

        return $negative ? -$fils : $fils;
    }

    /** A cell as plain text, whitespace collapsed, at most $max characters. Never a formula: it is only ever stored and shown as text. */
    private static function text(mixed $value, int $max): string
    {
        $text = match (true) {
            $value instanceof DateTimeInterface => $value->format('d/m/Y'),
            is_scalar($value) => (string) $value,
            default => '',
        };

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, $max);
    }
}
```

- [ ] **Step 5: The delete Action** `app/Actions/Ledger/DeleteBankUpload.php`:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\BankMatch;
use App\Models\BankStatementLine;
use App\Models\BankUpload;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Bank reconciliation spec §2 (plan ruling 7): an upload is deleted, with its lines, only while none of its lines is matched. */
final class DeleteBankUpload
{
    public function handle(User $actor, BankUpload $upload): void
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $upload) {
            LedgerAccount::query()->lockForUpdate()->findOrFail($upload->ledger_account_id); // plan ruling 10: no match lands while we look
            $upload = BankUpload::query()->findOrFail($upload->id);
            $lines = BankStatementLine::query()->where('bank_upload_id', $upload->id)->select('id');
            if (BankMatch::query()->whereIn('bank_statement_line_id', $lines)->exists()) {
                throw ValidationException::withMessages(['upload' => __('Lines of this upload are matched. Remove those matches first.')]);
            }
            $deleted = BankStatementLine::query()->where('bank_upload_id', $upload->id)->delete();
            Audit::log('bank.statement_deleted', $upload, old: ['file' => $upload->file_name, 'lines' => $deleted], causer: $actor);
            $upload->delete();
        });
    }
}
```

- [ ] **Step 6: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Upload the statement as a .csv or .xlsx file.": "ارفع الكشف كملف .csv أو .xlsx.",
    "Use each column for one thing only.": "استخدم كل عمود لغرض واحد فقط.",
    "This file cannot be read as .xlsx or .csv.": "لا يمكن قراءة هذا الملف كملف .xlsx أو .csv.",
    "The statement has more than :n rows. Split it into smaller files.": "يحتوي الكشف على أكثر من :n صف. قسّمه إلى ملفات أصغر.",
    "The date is not a :format date.": "التاريخ ليس بصيغة :format.",
    "Fill either the debit or the credit, not both.": "املأ المدين أو الدائن، وليس كليهما.",
    "The amount is empty or zero.": "المبلغ فارغ أو صفر.",
    "The amount is not a number with up to 3 decimals.": "المبلغ ليس رقماً بثلاث خانات عشرية كحد أقصى.",
    "The balance is not a number with up to 3 decimals.": "الرصيد ليس رقماً بثلاث خانات عشرية كحد أقصى.",
    "Lines of this upload are matched. Remove those matches first.": "بعض أسطر هذا الملف مطابَقة. أزل تلك المطابقات أولاً.",
```

- [ ] **Step 7: Run the tests.**

Run: `php artisan test tests/Feature/Ledger/BankStatementUploadTest.php`
Expected: PASS.

- [ ] **Step 8: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Actions/Ledger/ImportBankStatement.php app/Actions/Ledger/DeleteBankUpload.php tests/Pest.php tests/Feature/Ledger/BankStatementUploadTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
Bank statement upload: saved column mapping, signed or debit/credit amounts, three date formats, duplicates skipped by occurrence, bad rows reported, 5000-row limit, delete while unmatched

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Ledger entries, suggestions and matching: start, match, unmatch, discard

**Files:**
- Create: `app/Ledger/BankEntries.php`
- Create: `app/Actions/Ledger/StartBankReconciliation.php`, `app/Actions/Ledger/MatchBankLine.php`, `app/Actions/Ledger/UnmatchBankLine.php`, `app/Actions/Ledger/DiscardBankReconciliation.php`
- Modify: `tests/Pest.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/BankMatchingTest.php`

**Interfaces:**
- Consumes: Task 1's models, `BankReconciliation::lockOpen()`, `BankReconciliation::unmatchedLine()`; Task 2's `bankStatement()`, `bankLine()`; `ledgerFixture()`; `SaveOpeningBalances`, `RecordCashMovement`, `PostManualJournal`, `RecordPayment`.
- Produces:
  - `BankEntries::TYPE`, `BankEntries::ID` (SQL); `key(string $type, int $id): string` ("type:id"); `parseKeys(mixed $keys): ?list<array{0: string, 1: int}>`; `query(int $account, string $end): Builder` (selects `journal, kind, source_type, source_id, date, description, entry_type, entry_id, amount`); `unmatched(int $account, string $end): Builder`; `find(int $account, string $end, list<array{0: string, 1: int}> $keys): array<string, stdClass>`; `suggestions(BankReconciliation $rec, BankStatementLine $line): list<stdClass>` (also selects `hit`).
  - `StartBankReconciliation::handle(User, mixed $data{ledger_account_id, end_date, closing_balance, opening_balance}): BankReconciliation`; error keys `ledger_account_id`, `end_date`, `closing_balance`, `opening_balance`.
  - `MatchBankLine::handle(User, BankReconciliation, mixed $lineId, mixed $entries): void`; error keys `picked`, `line`.
  - `UnmatchBankLine::handle(User, BankReconciliation, mixed $lineId): void`; error key `line`.
  - `DiscardBankReconciliation::handle(User, BankReconciliation): void`; error key `discard`.
  - Pest `bankRecFixture(): array` (`ledgerFixture()` keys plus `bank`, `cash` (account ids), `dep1`, `dep2`, `dep3` (CashMovement), `manual` (Journal)); `bankRec(User $by, string $end, string $closing, ?string $opening = null): BankReconciliation` (Bank 1010).

- [ ] **Step 1: The fixture.** In `tests/Pest.php` add the imports that are missing of `App\Actions\Ledger\PostManualJournal`, `App\Actions\Ledger\RecordCashMovement`, `App\Actions\Ledger\SaveOpeningBalances`, `App\Actions\Ledger\StartBankReconciliation` and `App\Models\BankReconciliation`, and append:

```php
/**
 * The bank reconciliation worked example (plan, "Worked example"): ledgerFixture() plus, on Bank (1010), Admin's opening
 * balance 1000.000; transfers from Cash on hand DEP-1 200.000 and DEP-2 300.000 (12/10) and DEP-3 150.000 (19/10); the manual
 * journal "Office chairs CHQ 7781" Dr 5100 / Cr 1010 40.000 (17/10); and statement 1 uploaded: 11/10 "TRANSFER <PO>"
 * −300.000, 13/10 "CASH DEPOSIT DEP-1 DEP-2" +500.000, 15/10 "BANK CHARGES OCT" (CHG-OCT) −2.500, 20/10 "CREDIT INTEREST"
 * +1.200. Seed RolesAndPermissionsSeeder and fake the local disk first. Ends on 20/10/2026 10:00.
 *
 * @return array<string, mixed>
 */
function bankRecFixture(): array
{
    $f = ledgerFixture();
    $id = fn (string $code) => (int) LedgerAccount::query()->where('code', $code)->value('id');
    app(SaveOpeningBalances::class)->handle($f['admin'], [$id('1010') => ['debit' => '1000']]);
    $move = fn (string $reference, string $amount, string $date) => app(RecordCashMovement::class)->handle($f['finance'], ['kind' => 'transfer',
        'from_account_id' => $id('1000'), 'to_account_id' => $id('1010'), 'amount' => $amount, 'date' => $date, 'reference' => $reference]);
    $dep1 = $move('DEP-1', '200', '2026-10-12');
    $dep2 = $move('DEP-2', '300', '2026-10-12');
    $dep3 = $move('DEP-3', '150', '2026-10-19');
    $manual = app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-17', 'description' => 'Office chairs CHQ 7781',
        'lines' => [['account_id' => $id('5100'), 'debit' => '40'], ['account_id' => $id('1010'), 'credit' => '40']]]);
    bankStatement($f['finance'], '1010', [
        ['11/10/2026', 'TRANSFER '.$f['headLease']->number, '', '-300.000'],
        ['13/10/2026', 'CASH DEPOSIT DEP-1 DEP-2', '', '500.000'],
        ['15/10/2026', 'BANK CHARGES OCT', 'CHG-OCT', '-2.500'],
        ['20/10/2026', 'CREDIT INTEREST', '', '1.200'],
    ]);

    return [...$f, 'bank' => $id('1010'), 'cash' => $id('1000'), 'dep1' => $dep1, 'dep2' => $dep2, 'dep3' => $dep3, 'manual' => $manual];
}

/** Starts a reconciliation of Bank (1010) through StartBankReconciliation (bank reconciliation tests). */
function bankRec(User $by, string $end, string $closing, ?string $opening = null): BankReconciliation
{
    return app(StartBankReconciliation::class)->handle($by, ['ledger_account_id' => (string) LedgerAccount::query()->where('code', '1010')->value('id'),
        'end_date' => $end, 'closing_balance' => $closing, 'opening_balance' => $opening]);
}
```

- [ ] **Step 2: Write the failing test** `tests/Feature/Ledger/BankMatchingTest.php`:

```php
<?php

use App\Actions\Ledger\DiscardBankReconciliation;
use App\Actions\Ledger\MatchBankLine;
use App\Actions\Ledger\PostManualJournal;
use App\Actions\Ledger\StartBankReconciliation;
use App\Actions\Ledger\UnmatchBankLine;
use App\Actions\Payments\RecordPayment;
use App\Ledger\BankEntries;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\LedgerAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = bankRecFixture();
    $this->match = fn (BankReconciliation $rec, string $line, array $keys) => app(MatchBankLine::class)->handle($this->f['finance'], $rec, (string) bankLine($line)->id, $keys);
    $this->pay = fn (string $on, string $amount, string $reference) => app(RecordPayment::class)->handle($this->f['finance'], $this->f['omar'],
        ['received_on' => $on, 'method' => 'bank_transfer', 'amount' => $amount, 'reference' => $reference]);
});

test('an entry is one journal\'s net on the money account, keyed by its source and dated by its journal; the opening journal is not one', function () {
    $f = $this->f;
    $split = app(RecordPayment::class)->handle($f['finance'], $f['omar'], ['received_on' => '2026-10-18', 'tenders' => [['method' => 'bank_transfer', 'amount' => '100'],
        ['method' => 'cash', 'amount' => '50']]]);
    $rows = fn (int $account, string $end) => BankEntries::query($account, $end)->orderBy('j.date')->orderBy('j.id')->get()
        ->map(fn (stdClass $e) => [$e->entry_type, (int) $e->entry_id, $e->date, $e->amount])->all();

    expect($rows($f['bank'], '2026-10-20'))->toBe([
        ['disbursement', $f['headLease']->id, '2026-10-10', '-300.000'],
        ['cash_movement', $f['dep1']->id, '2026-10-12', '200.000'],
        ['cash_movement', $f['dep2']->id, '2026-10-12', '300.000'],
        ['manual', $f['manual']->id, '2026-10-17', '-40.000'],
        ['payment', $split->id, '2026-10-18', '100.000'],
        ['cash_movement', $f['dep3']->id, '2026-10-19', '150.000'],
    ])->and(BankEntries::query($f['cash'], '2026-10-20')->where('j.source_type', 'payment')->where('j.source_id', $split->id)->get()->pluck('amount')->all())->toBe(['50.000'])
        ->and($rows($f['bank'], '2026-10-11'))->toBe([['disbursement', $f['headLease']->id, '2026-10-10', '-300.000']]);
});

test('suggestions: same direction and amount, within 7 days, by the end date, unmatched; a reference first, then the nearest date', function () {
    $f = $this->f;
    ($this->pay)('2026-10-05', '100', 'ZZZ-1');                  // 8 days before the line: outside the window
    $near = ($this->pay)('2026-10-12', '100', 'ZZZ-2');          // 1 day
    $named = ($this->pay)('2026-10-15', '100', 'OMAR-OCT');      // 2 days; its reference is in the line
    $last = ($this->pay)('2026-10-20', '100', 'ZZZ-3');          // 7 days
    app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-13', 'description' => 'Refund out', 'lines' => [
        ['account_id' => (int) LedgerAccount::query()->where('code', '5100')->value('id'), 'debit' => '100'], ['account_id' => $f['bank'], 'credit' => '100']]]); // money out
    bankStatement($f['finance'], '1010', [['13/10/2026', 'TRANSFER FROM OMAR OMAR-OCT', '', '100']]);
    $keys = fn (BankReconciliation $rec) => array_map(fn (stdClass $e) => $e->entry_type.':'.$e->entry_id, BankEntries::suggestions($rec, bankLine('TRANSFER FROM OMAR OMAR-OCT')));

    $rec = bankRec($f['finance'], '2026-10-20', '0', '0');
    expect($keys($rec))->toBe(["payment:{$named->id}", "payment:{$near->id}", "payment:{$last->id}"])
        ->and(array_map(fn (stdClass $e) => $e->entry_type, BankEntries::suggestions($rec, bankLine('TRANSFER '.$f['headLease']->number))))->toBe(['disbursement']);

    ($this->match)($rec, 'TRANSFER FROM OMAR OMAR-OCT', ["payment:{$named->id}"]);
    expect($keys($rec))->toBe(["payment:{$near->id}", "payment:{$last->id}"]);

    app(DiscardBankReconciliation::class)->handle($f['finance'], $rec);
    expect($keys(bankRec($f['finance'], '2026-10-14', '0', '0')))->toBe(["payment:{$near->id}"]);
});

test('a statement line matches several entries whose total equals it; an entry matches one line; matches come off while open', function () {
    $f = $this->f;
    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    $dep1 = 'cash_movement:'.$f['dep1']->id;
    $dep2 = 'cash_movement:'.$f['dep2']->id;
    $deposit = 'CASH DEPOSIT DEP-1 DEP-2';

    expect(fn () => ($this->match)($rec, $deposit, [$dep1]))->toThrow(ValidationException::class, 'The chosen entries total 200.000; the statement line is 500.000.');
    ($this->match)($rec, $deposit, [$dep2, $dep1]);
    expect(BankMatch::query()->orderBy('id')->get()->map(fn (BankMatch $m) => [$m->entry_type, $m->entry_id, $m->amount])->all())
        ->toBe([['cash_movement', $f['dep1']->id, '200.000'], ['cash_movement', $f['dep2']->id, '300.000']])
        ->and(fn () => ($this->match)($rec, $deposit, [$dep1, $dep2]))->toThrow(ValidationException::class, 'This statement line is already matched.')
        ->and(fn () => ($this->match)($rec, 'CREDIT INTEREST', [$dep1]))->toThrow(ValidationException::class, 'A chosen entry is already matched to another statement line.');

    app(UnmatchBankLine::class)->handle($f['finance'], $rec, (string) bankLine($deposit)->id);
    expect(BankMatch::query()->count())->toBe(0)
        ->and(fn () => app(UnmatchBankLine::class)->handle($f['finance'], $rec, (string) bankLine($deposit)->id))
        ->toThrow(ValidationException::class, 'This statement line has no match in this reconciliation.');

    ($this->match)($rec, $deposit, [$dep1, $dep2]);
    app(DiscardBankReconciliation::class)->handle($f['finance'], $rec);
    expect(BankMatch::query()->count() + BankReconciliation::query()->count())->toBe(0);
});

test('the first reconciliation asks for the opening balance; one is open per account at a time', function () {
    $f = $this->f;
    $start = fn (string $code, array $data) => app(StartBankReconciliation::class)->handle($f['finance'],
        ['ledger_account_id' => (string) LedgerAccount::query()->where('code', $code)->value('id'), ...$data]);

    expect(fn () => bankRec($f['finance'], '2026-10-20', '1198.700'))
        ->toThrow(ValidationException::class, 'Enter the statement opening balance: this is the first reconciliation of this account.')
        ->and(fn () => bankRec($f['finance'], '2026-10-21', '1', '0'))->toThrow(ValidationException::class)
        ->and(fn () => bankRec($f['finance'], '2026-10-20', '1,198.700', '0'))
        ->toThrow(ValidationException::class, 'Enter an amount with up to 3 decimals, with a minus sign if it is overdrawn.')
        ->and(fn () => $start('4000', ['end_date' => '2026-10-20', 'closing_balance' => '0', 'opening_balance' => '0']))
        ->toThrow(ValidationException::class, 'Choose an active money account.');

    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    expect([$rec->status, $rec->opening_balance, $rec->closing_balance, $rec->end_date->toDateString()])->toBe(['open', '1000.000', '1198.700', '2026-10-20'])
        ->and(fn () => bankRec($f['finance'], '2026-10-19', '1', '0'))
        ->toThrow(ValidationException::class, 'This account already has an open reconciliation. Finish or discard it first.')
        ->and($start('1000', ['end_date' => '2026-10-20', 'closing_balance' => '-5', 'opening_balance' => '0'])->closing_balance)->toBe('-5.000');
});

test('tampered or stale input gets a message, never a 500', function () {
    $f = $this->f;
    $rec = bankRec($f['finance'], '2026-10-18', '0', '0');
    $line = (string) bankLine('CASH DEPOSIT DEP-1 DEP-2')->id;
    $dep1 = 'cash_movement:'.$f['dep1']->id;
    $match = fn (mixed $line, mixed $keys) => app(MatchBankLine::class)->handle($f['finance'], $rec, $line, $keys);
    $pick = 'Choose the ledger entries that make up this line.';
    $gone = 'A chosen entry is not on this account by the end date.';

    expect(fn () => $match($line, 'x'))->toThrow(ValidationException::class, $pick)
        ->and(fn () => $match($line, [['a']]))->toThrow(ValidationException::class, $pick)
        ->and(fn () => $match($line, ['payment:1e3']))->toThrow(ValidationException::class, $pick)
        ->and(fn () => $match('abc', [$dep1]))->toThrow(ValidationException::class, 'Choose a statement line of this account.')
        ->and(fn () => $match((string) bankLine('CREDIT INTEREST')->id, [$dep1]))->toThrow(ValidationException::class, 'This statement line is after the end date.')
        ->and(fn () => $match($line, ['payment:'.$f['saraPayment']->id]))->toThrow(ValidationException::class, $gone) // cash: not on Bank
        ->and(fn () => $match($line, ['cash_movement:'.$f['dep3']->id]))->toThrow(ValidationException::class, $gone) // 19/10: after the end date
        ->and(fn () => app(UnmatchBankLine::class)->handle($f['finance'], $rec, ['x']))
        ->toThrow(ValidationException::class, 'This statement line has no match in this reconciliation.');
});

test('only Finance starts, matches, unmatches and discards', function () {
    $f = $this->f;
    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    $line = (string) bankLine('TRANSFER '.$f['headLease']->number)->id;

    expect(fn () => app(StartBankReconciliation::class)->handle($f['admin'], []))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MatchBankLine::class)->handle($f['admin'], $rec, $line, ['disbursement:'.$f['headLease']->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UnmatchBankLine::class)->handle($f['admin'], $rec, $line))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DiscardBankReconciliation::class)->handle($f['admin'], $rec))->toThrow(AuthorizationException::class);
});
```

- [ ] **Step 3: Run it to see it fail.**

Run: `php artisan test tests/Feature/Ledger/BankMatchingTest.php`
Expected: FAIL. Class `App\Actions\Ledger\StartBankReconciliation` not found (from `bankRecFixture()`'s imports in Pest.php, or the first `bankRec()` call).

- [ ] **Step 4: The entries** `app/Ledger/BankEntries.php`:

```php
<?php

namespace App\Ledger;

use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Bank reconciliation plan rulings 1–2: a ledger entry on a money account is one journal's net on that account, keyed by its
 * source (source type and id; "manual" and the journal id for a manual journal) and dated by its journal. The opening
 * journal is not an entry. Keys survive a rebuild, which re-posts the same sources.
 */
final class BankEntries
{
    /** The entry key in SQL, over journals as j. */
    public const string TYPE = "COALESCE(j.source_type, 'manual')";

    public const string ID = 'COALESCE(j.source_id, j.id)';

    public static function key(string $type, int $id): string
    {
        return $type.':'.$id;
    }

    /** @return list<array{0: string, 1: int}>|null the distinct keys of 1–50 "type:id" strings; null for anything else */
    public static function parseKeys(mixed $keys): ?array
    {
        if (! is_array($keys) || $keys === [] || count($keys) > 50) {
            return null;
        }
        $out = [];
        foreach ($keys as $key) {
            if (! is_string($key) || preg_match('/^([a-z_]{1,30}):(\d{1,18})$/', $key, $m) !== 1) {
                return null;
            }
            $out[$key] = [$m[1], (int) $m[2]];
        }

        return array_values($out);
    }

    /** Entries on $account dated up to $end. Selects journal, kind, source_type, source_id, date, description, entry_type, entry_id, amount. */
    public static function query(int $account, string $end): Builder
    {
        return DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.ledger_account_id', $account)->where('j.date', '<=', $end)->where('j.kind', '<>', 'opening')
            ->groupBy('j.id')->havingRaw('SUM(l.debit) <> SUM(l.credit)')
            ->selectRaw('j.id AS journal, j.kind, j.source_type, j.source_id, j.date, j.description, '.self::TYPE.' AS entry_type, '
                .self::ID.' AS entry_id, SUM(l.debit) - SUM(l.credit) AS amount');
    }

    /** Entries no reconciliation of the account has matched (only its open one, always the latest, still takes matches). */
    public static function unmatched(int $account, string $end): Builder
    {
        return self::query($account, $end)->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('bank_matches as m')
            ->where('m.ledger_account_id', $account)->whereRaw('m.entry_type = '.self::TYPE)->whereRaw('m.entry_id = '.self::ID));
    }

    /**
     * The entries named by $keys, as they stand now (a key with no line on $account by $end is missing from the result).
     *
     * @param  list<array{0: string, 1: int}>  $keys
     * @return array<string, stdClass> key => entry, oldest journal first
     */
    public static function find(int $account, string $end, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return self::query($account, $end)->where(function (Builder $q) use ($keys) {
            foreach ($keys as [$type, $id]) {
                $q->orWhere(fn (Builder $w) => $type === 'manual'
                    ? $w->where('j.id', $id)->where('j.kind', 'manual')
                    : $w->where('j.source_type', $type)->where('j.source_id', $id));
            }
        })->orderBy('j.id')->get()->keyBy(fn (stdClass $e) => self::key((string) $e->entry_type, (int) $e->entry_id))->all();
    }

    /**
     * Plan ruling 2: up to 20 unmatched entries with the line's direction and amount, dated within 7 days of it and by the end
     * date; a reference or number found in the line's text first, then the nearest date.
     *
     * @return list<stdClass>
     */
    public static function suggestions(BankReconciliation $rec, BankStatementLine $line): array
    {
        $text = trim($line->description.' '.($line->reference ?? ''));
        $hit = fn (string $column) => "(CHAR_LENGTH({$column}) >= 3 AND INSTR(?, {$column}) > 0)";

        return array_values(self::unmatched($rec->ledger_account_id, $rec->end_date->toDateString())
            ->leftJoin('payments as p', fn (JoinClause $j) => $j->on('p.id', '=', 'j.source_id')->whereIn('j.source_type', ['payment', 'payment_reversal']))
            ->leftJoin('disbursements as d', fn (JoinClause $j) => $j->on('d.id', '=', 'j.source_id')->whereIn('j.source_type', ['disbursement', 'disbursement_reversal']))
            ->leftJoin('cheques as c', 'c.id', '=', DB::raw('COALESCE(p.cheque_id, d.cheque_id)'))
            ->whereBetween('j.date', [$line->date->subDays(7)->toDateString(), $line->date->addDays(7)->toDateString()])
            ->havingRaw('SUM(l.debit) - SUM(l.credit) = ?', [$line->amount])
            ->selectRaw('MAX('.$hit('j.description').' OR '.$hit('p.reference').' OR '.$hit('d.reference').' OR '.$hit('c.cheque_no').') AS hit',
                [$text, $text, $text, $text])
            ->orderByDesc('hit')->orderByRaw('ABS(DATEDIFF(j.date, ?))', [$line->date->toDateString()])->orderBy('j.id')
            ->limit(20)->get()->all());
    }
}
```

- [ ] **Step 5: Starting** `app/Actions/Ledger/StartBankReconciliation.php`:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\BankReconciliation;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation spec §3 (plan ruling 4): Finance starts a reconciliation of a money account to a statement end date and
 * closing balance. The opening balance is the latest reconciliation's closing balance; the first one is typed. One open per
 * account; end dates move forward only.
 */
final class StartBankReconciliation
{
    /** A signed amount with up to 3 decimals (an overdrawn account's balance is negative). */
    private const string MONEY = 'regex:/^-?\d{1,12}(\.\d{1,3})?$/';

    /** @param  mixed  $data  ledger_account_id, end_date, closing_balance, opening_balance (used only for the account's first reconciliation) */
    public function handle(User $actor, mixed $data): BankReconciliation
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        $data = is_array($data) ? $data : [];
        $amount = __('Enter an amount with up to 3 decimals, with a minus sign if it is overdrawn.');
        $v = Validator::make($data, [
            'ledger_account_id' => ['required', 'integer'],
            'end_date' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'closing_balance' => ['required', 'string', self::MONEY],
            'opening_balance' => ['nullable', 'string', self::MONEY],
        ], ['closing_balance.regex' => $amount, 'opening_balance.regex' => $amount])->validate();

        return DB::transaction(function () use ($actor, $v) {
            $account = LedgerAccount::query()->whereKey((int) $v['ledger_account_id'])->where('is_money', true)->where('active', true)->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['ledger_account_id' => __('Choose an active money account.')]);
            $last = BankReconciliation::query()->where('ledger_account_id', $account->id)->orderByDesc('end_date')->first();
            if ($last?->status === 'open') {
                throw ValidationException::withMessages(['ledger_account_id' => __('This account already has an open reconciliation. Finish or discard it first.')]);
            }
            if ($last !== null && (string) $v['end_date'] <= $last->end_date->toDateString()) {
                throw ValidationException::withMessages(['end_date' => __('Choose an end date after :date, the end date of the last reconciliation.',
                    ['date' => $last->end_date->format('d/m/Y')])]);
            }
            if ($last === null && blank($v['opening_balance'] ?? null)) {
                throw ValidationException::withMessages(['opening_balance' => __('Enter the statement opening balance: this is the first reconciliation of this account.')]);
            }

            $rec = (new BankReconciliation)->forceFill([
                'ledger_account_id' => $account->id,
                'end_date' => (string) $v['end_date'],
                'opening_balance' => $last?->closing_balance ?? Fils::toDecimal(Fils::fromDecimal((string) $v['opening_balance'])),
                'closing_balance' => Fils::toDecimal(Fils::fromDecimal((string) $v['closing_balance'])),
                'status' => 'open',
                'created_by' => $actor->id,
            ]);
            $rec->save();
            Audit::log('bank.reconciliation_started', $rec, new: ['account' => $account->code, 'end_date' => (string) $v['end_date'],
                'opening' => $rec->opening_balance, 'closing' => $rec->closing_balance], causer: $actor);

            return $rec;
        });
    }
}
```

- [ ] **Step 6: Matching** `app/Actions/Ledger/MatchBankLine.php`:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\BankEntries;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Bank reconciliation spec §3 (plan ruling 3): Finance matches one statement line of the open reconciliation to one or more
 * unmatched ledger entries on the account, dated by the end date, whose live amounts total the line exactly.
 */
final class MatchBankLine
{
    /** @param  mixed  $entries  entry keys ("type:id", BankEntries::key) */
    public function handle(User $actor, BankReconciliation $reconciliation, mixed $lineId, mixed $entries): void
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        $keys = BankEntries::parseKeys($entries)
            ?? throw ValidationException::withMessages(['picked' => __('Choose the ledger entries that make up this line.')]);

        DB::transaction(function () use ($actor, $reconciliation, $lineId, $keys) {
            $rec = BankReconciliation::lockOpen($reconciliation->id, 'picked');
            $line = $rec->unmatchedLine($lineId);
            $found = BankEntries::find($rec->ledger_account_id, $rec->end_date->toDateString(), $keys);
            if (count($found) !== count($keys)) {
                throw ValidationException::withMessages(['picked' => __('A chosen entry is not on this account by the end date.')]);
            }
            $taken = BankMatch::query()->where('ledger_account_id', $rec->ledger_account_id)->where(function (Builder $q) use ($keys) {
                foreach ($keys as [$type, $id]) {
                    $q->orWhere(fn (Builder $w) => $w->where('entry_type', $type)->where('entry_id', $id));
                }
            })->exists();
            if ($taken) {
                throw ValidationException::withMessages(['picked' => __('A chosen entry is already matched to another statement line.')]);
            }
            $total = array_sum(array_map(fn (stdClass $e) => Fils::fromDecimal((string) $e->amount), $found));
            if ($total !== Fils::fromDecimal($line->amount)) {
                throw ValidationException::withMessages(['picked' => __('The chosen entries total :total; the statement line is :amount.',
                    ['total' => Fils::toDecimal($total), 'amount' => $line->amount])]);
            }

            foreach ($found as $e) {
                (new BankMatch)->forceFill(['bank_reconciliation_id' => $rec->id, 'bank_statement_line_id' => $line->id, 'ledger_account_id' => $rec->ledger_account_id,
                    'entry_type' => (string) $e->entry_type, 'entry_id' => (int) $e->entry_id, 'amount' => (string) $e->amount, 'created_by' => $actor->id])->save();
            }
            Audit::log('bank.line_matched', $rec, properties: ['line' => $line->id, 'entries' => array_keys($found)], causer: $actor);
        });
    }
}
```

- [ ] **Step 7: Unmatching and discarding.** `app/Actions/Ledger/UnmatchBankLine.php`:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\BankEntries;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Bank reconciliation spec §3 (plan ruling 3): while the reconciliation is open, Finance removes all of a line's matches. */
final class UnmatchBankLine
{
    public function handle(User $actor, BankReconciliation $reconciliation, mixed $lineId): void
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $reconciliation, $lineId) {
            $rec = BankReconciliation::lockOpen($reconciliation->id, 'line');
            $id = is_int($lineId) || (is_string($lineId) && ctype_digit($lineId) && strlen($lineId) < 19) ? (int) $lineId : 0;
            $matches = BankMatch::query()->where('bank_reconciliation_id', $rec->id)->where('bank_statement_line_id', $id)->get();
            if ($matches->isEmpty()) {
                throw ValidationException::withMessages(['line' => __('This statement line has no match in this reconciliation.')]);
            }
            BankMatch::query()->whereKey($matches->modelKeys())->delete();
            Audit::log('bank.line_unmatched', $rec, properties: ['line' => $id,
                'entries' => $matches->map(fn (BankMatch $m) => BankEntries::key($m->entry_type, $m->entry_id))->all()], causer: $actor);
        });
    }
}
```

`app/Actions/Ledger/DiscardBankReconciliation.php`:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Plan ruling 6: an open reconciliation (a mistyped end date or closing balance) is deleted with its matches. */
final class DiscardBankReconciliation
{
    public function handle(User $actor, BankReconciliation $reconciliation): void
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $reconciliation) {
            $rec = BankReconciliation::lockOpen($reconciliation->id, 'discard');
            $matches = BankMatch::query()->where('bank_reconciliation_id', $rec->id)->delete();
            Audit::log('bank.reconciliation_discarded', $rec, old: ['end_date' => $rec->end_date->toDateString(), 'closing' => $rec->closing_balance, 'matches' => $matches],
                causer: $actor);
            $rec->delete();
        });
    }
}
```

- [ ] **Step 8: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Choose the ledger entries that make up this line.": "اختر قيود الدفتر التي يتكون منها هذا السطر.",
    "A chosen entry is not on this account by the end date.": "أحد القيود المختارة ليس على هذا الحساب حتى تاريخ النهاية.",
    "A chosen entry is already matched to another statement line.": "أحد القيود المختارة مطابَق بالفعل لسطر آخر في الكشف.",
    "The chosen entries total :total; the statement line is :amount.": "مجموع القيود المختارة :total، ومبلغ سطر الكشف :amount.",
    "Enter an amount with up to 3 decimals, with a minus sign if it is overdrawn.": "أدخل مبلغاً بثلاث خانات عشرية كحد أقصى، مع إشارة سالب إذا كان الحساب مكشوفاً.",
    "This account already has an open reconciliation. Finish or discard it first.": "لهذا الحساب تسوية مفتوحة بالفعل. أنهِها أو تجاهلها أولاً.",
    "Choose an end date after :date, the end date of the last reconciliation.": "اختر تاريخ نهاية بعد :date، تاريخ نهاية آخر تسوية.",
    "Enter the statement opening balance: this is the first reconciliation of this account.": "أدخل الرصيد الافتتاحي للكشف: هذه أول تسوية لهذا الحساب.",
    "This statement line has no match in this reconciliation.": "لا توجد مطابقة لسطر الكشف هذا في هذه التسوية.",
```

- [ ] **Step 9: Run the tests.**

Run: `php artisan test tests/Feature/Ledger/BankMatchingTest.php tests/Feature/Ledger/BankStatementUploadTest.php`
Expected: PASS.

- [ ] **Step 10: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Ledger/BankEntries.php app/Actions/Ledger/StartBankReconciliation.php app/Actions/Ledger/MatchBankLine.php app/Actions/Ledger/UnmatchBankLine.php app/Actions/Ledger/DiscardBankReconciliation.php tests/Pest.php tests/Feature/Ledger/BankMatchingTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
Bank reconciliation matching: entries keyed by source, suggestions by amount, direction, 7 days and reference, many-to-one matches, one entry once, start with the opening chain, unmatch, discard

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Bank-only lines, the finish figures, finishing and reopening

**Files:**
- Create: `app/Actions/Ledger/RecordBankOnlyLine.php`, `app/Actions/Ledger/FinishBankReconciliation.php`, `app/Actions/Ledger/ReopenBankReconciliation.php`
- Modify: `app/Ledger/BankEntries.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/BankFinishTest.php`

**Interfaces:**
- Consumes: Task 3's `BankEntries::query()`, `bankRecFixture()`, `bankRec()`, `MatchBankLine`, `UnmatchBankLine`, `DiscardBankReconciliation`; `RecordCashMovement::handle(User, mixed)`; `LedgerSetting::rebuilding()`; `RebuildLedger::handle(User, mixed, mixed)`.
- Produces:
  - phpstan type `Figures` on `BankEntries`: `array{closing: int, in_transit: int, outstanding: int, adjusted: int, ledger: int, difference: int, opening: int, movement: int, covered: bool, unmatched_lines: int, changed: int, in: list<stdClass>, out: list<stdClass>}` (money in fils; `in`/`out` rows as `BankEntries::query()` selects them), in that key order.
  - `BankEntries::figures(BankReconciliation $rec): Figures`.
  - `RecordBankOnlyLine::handle(User, BankReconciliation, mixed $lineId, mixed $kind, mixed $otherAccountId): CashMovement`; error keys `kind`, `line`, and `RecordCashMovement`'s (`from_account_id`, `to_account_id`, `date`, `amount`, `reference`).
  - `FinishBankReconciliation::handle(User, BankReconciliation): BankReconciliation`; error key `finish` (one message per reason).
  - `ReopenBankReconciliation::handle(User, BankReconciliation): BankReconciliation`; error key `reopen`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Ledger/BankFinishTest.php`:

```php
<?php

use App\Actions\Ledger\DiscardBankReconciliation;
use App\Actions\Ledger\FinishBankReconciliation;
use App\Actions\Ledger\MatchBankLine;
use App\Actions\Ledger\RebuildLedger;
use App\Actions\Ledger\RecordBankOnlyLine;
use App\Actions\Ledger\ReopenBankReconciliation;
use App\Actions\Ledger\UnmatchBankLine;
use App\Ledger\BankEntries;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\LedgerSetting;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = bankRecFixture();
    $this->rec = bankRec($this->f['finance'], '2026-10-20', '1198.700', '1000');
    $this->match = fn (string $line, array $keys, ?BankReconciliation $rec = null) => app(MatchBankLine::class)->handle($this->f['finance'], $rec ?? $this->rec,
        (string) bankLine($line)->id, $keys);
    $this->only = fn (string $line, mixed $kind, mixed $other = null) => app(RecordBankOnlyLine::class)->handle($this->f['finance'], $this->rec,
        (string) bankLine($line)->id, $kind, $other);
    $this->matchAll = function () {
        ($this->match)('TRANSFER '.$this->f['headLease']->number, ['disbursement:'.$this->f['headLease']->id]);
        ($this->match)('CASH DEPOSIT DEP-1 DEP-2', ['cash_movement:'.$this->f['dep1']->id, 'cash_movement:'.$this->f['dep2']->id]);
        ($this->only)('BANK CHARGES OCT', 'bank_charge');
        ($this->only)('CREDIT INTEREST', 'bank_interest');
    };
    $this->figures = fn (?BankReconciliation $rec = null) => array_intersect_key(BankEntries::figures(($rec ?? $this->rec)->fresh()),
        array_flip(['in_transit', 'outstanding', 'adjusted', 'ledger', 'difference', 'covered', 'unmatched_lines', 'changed']));
    $this->done = ['in_transit' => 150000, 'outstanding' => 40000, 'adjusted' => 1308700, 'ledger' => 1308700, 'difference' => 0, 'covered' => true,
        'unmatched_lines' => 0, 'changed' => 0];
});

afterEach(function () {
    LedgerSetting::query()->update(['start_date' => null, 'locked_until' => null, 'health' => null, 'health_checked_at' => null, 'rebuild_status' => null, 'rebuild_from' => null,
        'rebuild_to' => null, 'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null, 'rebuild_run' => null]);
});

test('a bank-only line becomes a cash and bank movement through the existing action, matched with it', function () {
    $f = $this->f;
    expect(fn () => ($this->only)('BANK CHARGES OCT', 'bank_interest'))->toThrow(ValidationException::class, 'A bank charge is money out; bank interest is money in.')
        ->and(fn () => ($this->only)('BANK CHARGES OCT', ['x']))->toThrow(ValidationException::class, 'Choose what this line is.');
    $charge = ($this->only)('BANK CHARGES OCT', 'bank_charge');
    bankStatement($f['finance'], '1010', [['18/10/2026', 'TRANSFER TO PETTY CASH', 'TR-9', '-25']]);
    expect(fn () => ($this->only)('TRANSFER TO PETTY CASH', 'transfer'))->toThrow(ValidationException::class)
        ->and(fn () => ($this->only)('TRANSFER TO PETTY CASH', 'transfer', (string) $f['bank']))->toThrow(ValidationException::class, 'Choose two different money accounts.');
    $transfer = ($this->only)('TRANSFER TO PETTY CASH', 'transfer', (string) $f['cash']);

    expect([$charge->kind->value, $charge->from_account_id, $charge->to_account_id, $charge->amount, $charge->date->toDateString(), $charge->reference])
        ->toBe(['bank_charge', $f['bank'], null, '2.500', '2026-10-15', 'CHG-OCT'])
        ->and(postedLines('cash_movement', $charge->id))->toBe(['5180 2.500 0.000', '1010 0.000 2.500'])
        ->and([$transfer->from_account_id, $transfer->to_account_id, $transfer->amount, $transfer->reference])->toBe([$f['bank'], $f['cash'], '25.000', 'TR-9'])
        ->and(BankMatch::query()->orderBy('id')->get()->map(fn (BankMatch $m) => [$m->entry_type, $m->entry_id, $m->amount])->all())
        ->toBe([['cash_movement', $charge->id, '-2.500'], ['cash_movement', $transfer->id, '-25.000']]);
});

test('finishing waits for every statement line to the end date and a difference of 0.000', function () {
    expect(($this->figures)())->toBe(['in_transit' => 650000, 'outstanding' => 340000, 'adjusted' => 1508700, 'ledger' => 1310000, 'difference' => 198700,
        'covered' => false, 'unmatched_lines' => 4, 'changed' => 0]);
    try {
        app(FinishBankReconciliation::class)->handle($this->f['finance'], $this->rec);
        $this->fail('Finishing should be refused.');
    } catch (ValidationException $e) {
        expect($e->errors()['finish'])->toBe([
            'Statement lines up to the end date not matched: 4.',
            'The opening balance plus the matched statement lines is 1000.000, not the closing balance 1198.700.',
            'The difference is 198.700; it must be 0.000.',
        ]);
    }

    ($this->matchAll)();
    expect(($this->figures)())->toBe($this->done);
    app(FinishBankReconciliation::class)->handle($this->f['finance'], $this->rec);
    $rec = $this->rec->fresh();

    expect([$rec->status, $rec->finished_by])->toBe(['finished', $this->f['finance']->id])
        ->and(Activity::query()->where('event', 'bank.reconciliation_finished')->exists())->toBeTrue()
        ->and(fn () => app(DiscardBankReconciliation::class)->handle($this->f['finance'], $rec))->toThrow(ValidationException::class, 'This reconciliation is finished.')
        ->and(fn () => app(FinishBankReconciliation::class)->handle($this->f['finance'], $rec))->toThrow(ValidationException::class, 'This reconciliation is finished.');
});

test('the next reconciliation starts from the last closing balance with a later end date; a changed entry blocks finishing', function () {
    $f = $this->f;
    ($this->matchAll)();
    app(FinishBankReconciliation::class)->handle($f['finance'], $this->rec);
    expect(fn () => bankRec($f['finance'], '2026-10-20', '1308.700'))
        ->toThrow(ValidationException::class, 'Choose an end date after 20/10/2026, the end date of the last reconciliation.');

    $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', 'Asia/Bahrain'));
    bankStatement($f['finance'], '1010', [['21/10/2026', 'CASH DEPOSIT DEP-3', '', '150'], ['22/10/2026', 'CHQ 7781', '', '-40']]);
    $next = bankRec($f['finance'], '2026-10-25', '1308.700', '5');
    expect($next->opening_balance)->toBe('1198.700');
    ($this->match)('CASH DEPOSIT DEP-3', ['cash_movement:'.$f['dep3']->id], $next);
    ($this->match)('CHQ 7781', ['manual:'.$f['manual']->id], $next);

    // As if a posting rule had moved the entry since it was matched: the stored amount no longer agrees with the ledger.
    $m = BankMatch::query()->where('entry_type', 'manual')->sole();
    DB::table('bank_matches')->where('id', $m->id)->delete();
    DB::table('bank_matches')->insert([...$m->only(['bank_reconciliation_id', 'bank_statement_line_id', 'ledger_account_id', 'entry_type', 'entry_id', 'created_by']),
        'amount' => '-41.000']);
    expect(fn () => app(FinishBankReconciliation::class)->handle($f['finance'], $next))
        ->toThrow(ValidationException::class, 'Matched entries that changed in the ledger since they were matched: 1. Remove those matches and match again.');

    app(UnmatchBankLine::class)->handle($f['finance'], $next, (string) bankLine('CHQ 7781')->id);
    ($this->match)('CHQ 7781', ['manual:'.$f['manual']->id], $next);
    app(FinishBankReconciliation::class)->handle($f['finance'], $next);

    expect(($this->figures)($next))->toBe(['in_transit' => 0, 'outstanding' => 0, 'adjusted' => 1308700, 'ledger' => 1308700, 'difference' => 0, 'covered' => true,
        'unmatched_lines' => 0, 'changed' => 0])
        ->and(($this->figures)())->toBe($this->done); // the first one reads the same after the second matched its in-transit items
});

test('Admin reopens only the latest finished reconciliation of the account; its matches stay', function () {
    $f = $this->f;
    ($this->matchAll)();
    app(FinishBankReconciliation::class)->handle($f['finance'], $this->rec);
    expect(fn () => app(ReopenBankReconciliation::class)->handle($f['finance'], $this->rec))->toThrow(AuthorizationException::class);

    $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', 'Asia/Bahrain'));
    $next = bankRec($f['finance'], '2026-10-25', '1198.700');
    expect(fn () => app(ReopenBankReconciliation::class)->handle($f['admin'], $this->rec))
        ->toThrow(ValidationException::class, 'Only the latest reconciliation of an account can be reopened.')
        ->and(fn () => app(ReopenBankReconciliation::class)->handle($f['admin'], $next))->toThrow(ValidationException::class, 'Only a finished reconciliation can be reopened.');

    app(DiscardBankReconciliation::class)->handle($f['finance'], $next);
    app(ReopenBankReconciliation::class)->handle($f['admin'], $this->rec);
    $rec = $this->rec->fresh();

    expect([$rec->status, $rec->finished_by, $rec->finished_at, $rec->reopened_by])->toBe(['open', null, null, $f['admin']->id])
        ->and(BankMatch::query()->where('bank_reconciliation_id', $rec->id)->count())->toBe(5)
        ->and(Activity::query()->where('event', 'bank.reconciliation_reopened')->sole()->causer_id)->toBe($f['admin']->id);
});

test('matches survive a ledger rebuild; finishing waits while a rebuild runs', function () {
    $f = $this->f;
    ($this->matchAll)();
    $before = journalFor('cash_movement', $f['dep1']->id)?->id;
    LedgerSetting::query()->update(['rebuild_status' => 'running']);
    expect(fn () => app(FinishBankReconciliation::class)->handle($f['finance'], $this->rec))->toThrow(ValidationException::class, 'Wait for the rebuild to finish.');
    LedgerSetting::query()->update(['rebuild_status' => null]);
    app(FinishBankReconciliation::class)->handle($f['finance'], $this->rec);

    app(RebuildLedger::class)->handle($f['finance'], '2026-10', '2026-10');

    expect(journalFor('cash_movement', $f['dep1']->id)?->id)->not->toBe($before)
        ->and(BankMatch::query()->count())->toBe(5)
        ->and(($this->figures)())->toBe($this->done);
});
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php artisan test tests/Feature/Ledger/BankFinishTest.php`
Expected: FAIL. Class `App\Actions\Ledger\RecordBankOnlyLine` not found / method `BankEntries::figures` does not exist.

- [ ] **Step 3: The figures.** In `app/Ledger/BankEntries.php`:
  - add `use App\Support\Fils;` to the imports;
  - in the class docblock, after the paragraph, add:

```php
 *
 * @phpstan-type Figures array{closing: int, in_transit: int, outstanding: int, adjusted: int, ledger: int, difference: int, opening: int, movement: int,
 *     covered: bool, unmatched_lines: int, changed: int, in: list<stdClass>, out: list<stdClass>}
```

  - after `suggestions()` add:

```php
    /**
     * Plan ruling 4 (spec §4), in fils. An entry counts as matched when a reconciliation of the account ending on or before
     * this one's end date matched it, so a finished reconciliation reads the same after later ones.
     *
     * @return Figures
     */
    public static function figures(BankReconciliation $rec): array
    {
        $account = $rec->ledger_account_id;
        $end = $rec->end_date->toDateString();
        $matches = DB::table('bank_matches as m')->join('bank_reconciliations as r', 'r.id', '=', 'm.bank_reconciliation_id')
            ->where('m.ledger_account_id', $account)->where('r.end_date', '<=', $end)
            ->get(['m.bank_reconciliation_id', 'm.entry_type', 'm.entry_id', 'm.amount']);
        $matched = [];
        foreach ($matches as $m) {
            $matched[self::key((string) $m->entry_type, (int) $m->entry_id)] = true;
        }

        $live = [];
        $in = [];
        $out = [];
        $inTransit = 0;
        $outstanding = 0;
        foreach (self::query($account, $end)->orderBy('j.date')->orderBy('j.id')->get() as $e) {
            $key = self::key((string) $e->entry_type, (int) $e->entry_id);
            $amount = Fils::fromDecimal((string) $e->amount);
            $live[$key] = $amount;
            if (isset($matched[$key])) {
                continue;
            }
            if ($amount > 0) {
                $in[] = $e;
                $inTransit += $amount;
            } else {
                $out[] = $e;
                $outstanding -= $amount;
            }
        }
        $changed = $matches->filter(fn (stdClass $m) => (int) $m->bank_reconciliation_id === $rec->id
            && ($live[self::key((string) $m->entry_type, (int) $m->entry_id)] ?? null) !== Fils::fromDecimal((string) $m->amount))->count();

        $closing = Fils::fromDecimal($rec->closing_balance);
        $opening = Fils::fromDecimal($rec->opening_balance);
        $ledger = self::sum(DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.ledger_account_id', $account)->where('j.date', '<=', $end)->sum(DB::raw('l.debit - l.credit')));
        $movement = self::sum(DB::table('bank_statement_lines')
            ->whereIn('id', DB::table('bank_matches')->where('bank_reconciliation_id', $rec->id)->select('bank_statement_line_id'))->sum('amount'));
        $adjusted = $closing + $inTransit - $outstanding;

        return [
            'closing' => $closing,
            'in_transit' => $inTransit,
            'outstanding' => $outstanding,
            'adjusted' => $adjusted,
            'ledger' => $ledger,
            'difference' => $adjusted - $ledger,
            'opening' => $opening,
            'movement' => $movement,
            'covered' => $opening + $movement === $closing,
            'unmatched_lines' => DB::table('bank_statement_lines as s')->where('s.ledger_account_id', $account)->where('s.date', '<=', $end)
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('bank_matches as m')->whereColumn('m.bank_statement_line_id', 's.id'))->count(),
            'changed' => $changed,
            'in' => $in,
            'out' => $out,
        ];
    }

    /** A SUM() result in fils (null or 0 when there are no rows). */
    private static function sum(mixed $sum): int
    {
        return Fils::fromDecimal((string) ($sum ?: '0'));
    }
```

- [ ] **Step 4: Bank-only lines** `app/Actions/Ledger/RecordBankOnlyLine.php`:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\CashMovementKind;
use App\Enums\PermissionName;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\CashMovement;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation spec §3 (plan ruling 8): a statement line with nothing in the ledger (a bank charge, bank interest or a
 * transfer) is recorded through the existing RecordCashMovement on the line's date, and matched to it, in one transaction.
 */
final class RecordBankOnlyLine
{
    public function __construct(private RecordCashMovement $record) {}

    /** @param  mixed  $otherAccountId  the other money account of a transfer */
    public function handle(User $actor, BankReconciliation $reconciliation, mixed $lineId, mixed $kind, mixed $otherAccountId): CashMovement
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        $kind = is_string($kind) ? CashMovementKind::tryFrom($kind) : null;
        if ($kind === null) {
            throw ValidationException::withMessages(['kind' => __('Choose what this line is.')]);
        }

        return DB::transaction(function () use ($actor, $reconciliation, $lineId, $kind, $otherAccountId) {
            LedgerSetting::query()->lockForUpdate()->firstOrFail(); // plan ruling 10: the settings row before the account row, the order Poster takes
            $rec = BankReconciliation::lockOpen($reconciliation->id, 'line');
            $line = $rec->unmatchedLine($lineId);
            $fils = Fils::fromDecimal($line->amount);
            if (($kind === CashMovementKind::BankCharge && $fils > 0) || ($kind === CashMovementKind::BankInterest && $fils < 0)) {
                throw ValidationException::withMessages(['kind' => __('A bank charge is money out; bank interest is money in.')]);
            }
            $account = $rec->ledger_account_id;
            $movement = $this->record->handle($actor, [
                'kind' => $kind->value,
                'from_account_id' => match ($kind) {
                    CashMovementKind::BankCharge => $account,
                    CashMovementKind::BankInterest => null,
                    CashMovementKind::Transfer => $fils < 0 ? $account : $otherAccountId,
                },
                'to_account_id' => match ($kind) {
                    CashMovementKind::BankCharge => null,
                    CashMovementKind::BankInterest => $account,
                    CashMovementKind::Transfer => $fils > 0 ? $account : $otherAccountId,
                },
                'amount' => Fils::toDecimal(abs($fils)),
                'date' => $line->date->toDateString(),
                'reference' => mb_substr(filled($line->reference) ? (string) $line->reference : $line->description, 0, 100) ?: 'BANK-'.$line->id,
            ]);
            (new BankMatch)->forceFill(['bank_reconciliation_id' => $rec->id, 'bank_statement_line_id' => $line->id, 'ledger_account_id' => $account,
                'entry_type' => 'cash_movement', 'entry_id' => $movement->id, 'amount' => $line->amount, 'created_by' => $actor->id])->save();
            Audit::log('bank.line_movement_recorded', $rec, properties: ['line' => $line->id, 'movement' => $movement->id, 'kind' => $kind->value], causer: $actor);

            return $movement;
        });
    }
}
```

- [ ] **Step 5: Finishing** `app/Actions/Ledger/FinishBankReconciliation.php`:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\BankEntries;
use App\Models\BankReconciliation;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation spec §4 (plan rulings 4 and 9): Finance finishes only when every statement line to the end date is
 * matched, no matched entry changed, the matched lines explain the closing balance and the difference is 0.000. Not while a
 * ledger rebuild is queued or running. The period need not be locked.
 */
final class FinishBankReconciliation
{
    public function handle(User $actor, BankReconciliation $reconciliation): BankReconciliation
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $reconciliation) {
            $rec = BankReconciliation::lockOpen($reconciliation->id, 'finish');
            if (LedgerSetting::current()->rebuilding()) {
                throw ValidationException::withMessages(['finish' => __('Wait for the rebuild to finish.')]);
            }
            $f = BankEntries::figures($rec);
            $reasons = array_values(array_filter([
                $f['unmatched_lines'] > 0 ? __('Statement lines up to the end date not matched: :n.', ['n' => $f['unmatched_lines']]) : null,
                $f['changed'] > 0 ? __('Matched entries that changed in the ledger since they were matched: :n. Remove those matches and match again.', ['n' => $f['changed']]) : null,
                $f['covered'] ? null : __('The opening balance plus the matched statement lines is :sum, not the closing balance :closing.',
                    ['sum' => Fils::toDecimal($f['opening'] + $f['movement']), 'closing' => Fils::toDecimal($f['closing'])]),
                $f['difference'] === 0 ? null : __('The difference is :difference; it must be 0.000.', ['difference' => Fils::toDecimal($f['difference'])]),
            ]));
            if ($reasons !== []) {
                throw ValidationException::withMessages(['finish' => $reasons]);
            }

            $rec->forceFill(['status' => 'finished', 'finished_by' => $actor->id, 'finished_at' => now()])->save();
            Audit::log('bank.reconciliation_finished', $rec, new: ['in_transit' => Fils::toDecimal($f['in_transit']), 'outstanding' => Fils::toDecimal($f['outstanding']),
                'ledger' => Fils::toDecimal($f['ledger']), 'closing' => $rec->closing_balance], causer: $actor);

            return $rec;
        });
    }
}
```

- [ ] **Step 6: Reopening** `app/Actions/Ledger/ReopenBankReconciliation.php`:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\BankReconciliation;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation spec §4 (plan ruling 6): Admin reopens the latest finished reconciliation of an account. Its matches
 * stay; its entries are no longer reconciled (the reversal guard counts finished reconciliations only).
 */
final class ReopenBankReconciliation
{
    public function handle(User $actor, BankReconciliation $reconciliation): BankReconciliation
    {
        if (! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $reconciliation) {
            LedgerAccount::query()->lockForUpdate()->findOrFail(BankReconciliation::query()->findOrFail($reconciliation->id)->ledger_account_id);
            $rec = BankReconciliation::query()->findOrFail($reconciliation->id);
            if ($rec->status !== 'finished') {
                throw ValidationException::withMessages(['reopen' => __('Only a finished reconciliation can be reopened.')]);
            }
            if (BankReconciliation::query()->where('ledger_account_id', $rec->ledger_account_id)->where('end_date', '>', $rec->end_date->toDateString())->exists()) {
                throw ValidationException::withMessages(['reopen' => __('Only the latest reconciliation of an account can be reopened.')]);
            }
            $old = ['finished_by' => $rec->finished_by, 'finished_at' => $rec->finished_at?->toIso8601String()];
            $rec->forceFill(['status' => 'open', 'finished_by' => null, 'finished_at' => null, 'reopened_by' => $actor->id, 'reopened_at' => now()])->save();
            Audit::log('bank.reconciliation_reopened', $rec, old: $old, causer: $actor);

            return $rec;
        });
    }
}
```

- [ ] **Step 7: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert (`"Wait for the rebuild to finish."` is already there):

```json
    "Choose what this line is.": "اختر نوع هذا السطر.",
    "A bank charge is money out; bank interest is money in.": "الرسوم المصرفية مبلغ خارج، والفوائد البنكية مبلغ داخل.",
    "Statement lines up to the end date not matched: :n.": "أسطر الكشف غير المطابَقة حتى تاريخ النهاية: :n.",
    "Matched entries that changed in the ledger since they were matched: :n. Remove those matches and match again.": "قيود مطابَقة تغيّرت في الدفتر بعد مطابقتها: :n. أزل تلك المطابقات وطابق من جديد.",
    "The opening balance plus the matched statement lines is :sum, not the closing balance :closing.": "الرصيد الافتتاحي مع أسطر الكشف المطابَقة يساوي :sum، وليس الرصيد الختامي :closing.",
    "The difference is :difference; it must be 0.000.": "الفرق :difference، ويجب أن يكون 0.000.",
    "Only a finished reconciliation can be reopened.": "لا يُعاد فتح إلا التسوية المنتهية.",
    "Only the latest reconciliation of an account can be reopened.": "لا يُعاد فتح إلا آخر تسوية للحساب.",
```

- [ ] **Step 8: Run the tests.**

Run: `php artisan test tests/Feature/Ledger/BankFinishTest.php tests/Feature/Ledger/BankMatchingTest.php`
Expected: PASS.

- [ ] **Step 9: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Ledger/BankEntries.php app/Actions/Ledger/RecordBankOnlyLine.php app/Actions/Ledger/FinishBankReconciliation.php app/Actions/Ledger/ReopenBankReconciliation.php tests/Feature/Ledger/BankFinishTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
Bank reconciliation finish: bank-only lines recorded as cash movements and matched, in-transit and outstanding as of the end date, difference 0 and every line matched to finish, Admin reopens the latest, matches survive a rebuild

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: The reversal guard on every reversal path

**Files:**
- Modify: `app/Ledger/BankEntries.php`
- Modify: `app/Actions/Payments/RequestPaymentReversal.php`, `app/Approvals/PaymentReversal.php`, `app/Actions/Disbursements/RequestDisbursementReversal.php`, `app/Approvals/PaymentOutReversal.php`, `app/Actions/Expenses/ReverseExpense.php`, `app/Actions/Ledger/ReverseMoneyEntry.php`, `app/Actions/Ledger/ReverseManualJournal.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/BankReversalGuardTest.php`

**Interfaces:**
- Consumes: Tasks 3–4 (`bankRecFixture()`, `bankRec()`, `MatchBankLine`, `RecordBankOnlyLine`, `FinishBankReconciliation`, `StartBankReconciliation`, `BankEntries::figures()`); `RequestPaymentReversal::handle(User, Payment, string $reason, array $payload = [])`; `RequestDisbursementReversal::handle(User, Disbursement, string)`; `DecideApproval::handle(User, Approval, bool, ?string)`; `ReverseExpense::handle(User, Expense, string)`; `ReverseMoneyEntry::handle(User, CashMovement|VatReturn, mixed $date, mixed $reason)`; `ReverseManualJournal::handle(User, Journal, mixed $date)`; `RecordVatReturn::handle(User, mixed)`; `SaveCreditNote::handle(User, Invoice $target, ?Invoice $draft, array)`; `SubmitCreditNote::handle(User, Invoice): Approval`.
- Produces: `BankEntries::assertReversible(string $type, int $id, string $date, string $field): void` (ruling 5). Message: "This entry is reconciled with the bank statement up to :date, so it cannot be reversed on or before that day."

- [ ] **Step 1: Write the failing test** `tests/Feature/Ledger/BankReversalGuardTest.php`:

```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\Expenses\ReverseExpense;
use App\Actions\Ledger\FinishBankReconciliation;
use App\Actions\Ledger\MatchBankLine;
use App\Actions\Ledger\RecordBankOnlyLine;
use App\Actions\Ledger\RecordVatReturn;
use App\Actions\Ledger\ReverseManualJournal;
use App\Actions\Ledger\ReverseMoneyEntry;
use App\Actions\Ledger\StartBankReconciliation;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RequestPaymentReversal;
use App\Enums\PaymentStatus;
use App\Ledger\BankEntries;
use App\Models\Approval;
use App\Models\BankReconciliation;
use App\Models\LedgerAccount;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = bankRecFixture();
});

/** Matches statement 1 of bankRecFixture() plus $extra (description => entry keys) and finishes Bank's reconciliation to 20/10. */
function finishedBankRec(array $f, string $closing, array $extra): BankReconciliation
{
    $rec = bankRec($f['finance'], '2026-10-20', $closing, '1000');
    $match = fn (string $line, array $keys) => app(MatchBankLine::class)->handle($f['finance'], $rec, (string) bankLine($line)->id, $keys);
    $match('TRANSFER '.$f['headLease']->number, ['disbursement:'.$f['headLease']->id]);
    $match('CASH DEPOSIT DEP-1 DEP-2', ['cash_movement:'.$f['dep1']->id, 'cash_movement:'.$f['dep2']->id]);
    app(RecordBankOnlyLine::class)->handle($f['finance'], $rec, (string) bankLine('BANK CHARGES OCT')->id, 'bank_charge', null);
    app(RecordBankOnlyLine::class)->handle($f['finance'], $rec, (string) bankLine('CREDIT INTEREST')->id, 'bank_interest', null);
    foreach ($extra as $line => $keys) {
        $match($line, $keys);
    }

    return app(FinishBankReconciliation::class)->handle($f['finance'], $rec);
}

test('a reconciled entry is not reversed on or before the end date; a later reversal and an unreconciled entry are fine', function () {
    $f = $this->f;
    $pay = fn (string $amount, string $reference) => app(RecordPayment::class)->handle($f['finance'], $f['omar'], ['received_on' => '2026-10-19',
        'method' => 'bank_transfer', 'amount' => $amount, 'reference' => $reference]);
    $early = $pay('50', 'OM-50'); // its reversal is asked for before the reconciliation is finished
    $late = $pay('30', 'OM-30');  // and this one's after
    $vat = app(RecordVatReturn::class)->handle($f['finance'], ['period_from' => '2026-10-01', 'period_to' => '2026-10-20', 'date' => '2026-10-20',
        'method' => 'bank_transfer', 'reference' => 'NBR-Q4', 'amount' => '42']);
    bankStatement($f['finance'], '1010', [['17/10/2026', 'CHQ 7781', '', '-40'], ['19/10/2026', 'OMAR OM-50', '', '50'], ['19/10/2026', 'OMAR OM-30', '', '30'],
        ['20/10/2026', 'NBR VAT', '', '-42']]);
    $askedEarly = app(RequestPaymentReversal::class)->handle($f['finance'], $early, 'Sent back by the bank');
    $askedOut = app(RequestDisbursementReversal::class)->handle($f['finance'], $f['headLease'], 'Paid twice');
    finishedBankRec($f, '1196.700', ['CHQ 7781' => ['manual:'.$f['manual']->id], 'OMAR OM-50' => ['payment:'.$early->id],
        'OMAR OM-30' => ['payment:'.$late->id], 'NBR VAT' => ['vat_return:'.$vat->id]]);
    $refused = 'This entry is reconciled with the bank statement up to 20/10/2026, so it cannot be reversed on or before that day.';
    $decide = fn (Approval $approval) => app(DecideApproval::class)->handle($f['management'], $approval, true);

    expect(fn () => $decide($askedEarly))->toThrow(ValidationException::class, $refused)
        ->and(fn () => $decide($askedOut))->toThrow(ValidationException::class, $refused)
        ->and(fn () => app(RequestPaymentReversal::class)->handle($f['finance'], $late, 'Wrong tenant'))->toThrow(ValidationException::class, $refused)
        ->and(fn () => app(RequestDisbursementReversal::class)->handle($f['finance'], $f['headLease']->fresh(), 'Paid twice'))->toThrow(ValidationException::class, $refused)
        ->and(fn () => app(ReverseMoneyEntry::class)->handle($f['finance'], $f['dep1'], '2026-10-20', 'Typo'))->toThrow(ValidationException::class, $refused)
        ->and(fn () => app(ReverseMoneyEntry::class)->handle($f['finance'], $vat, '2026-10-20', 'Typo'))->toThrow(ValidationException::class, $refused)
        ->and(fn () => app(ReverseManualJournal::class)->handle($f['finance'], $f['manual'], '2026-10-20'))->toThrow(ValidationException::class, $refused)
        ->and($early->fresh()->status)->not->toBe(PaymentStatus::Reversed);

    // Not reconciled: DEP-3 is still in transit, and an expense never touches a money account.
    app(ReverseMoneyEntry::class)->handle($f['finance'], $f['dep3'], '2026-10-20', 'Never banked');
    app(ReverseExpense::class)->handle($f['finance'], $f['companyExpense'], 'Entered twice');

    // The next day is after the end date.
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    $decide($askedEarly);
    app(ReverseMoneyEntry::class)->handle($f['finance'], $f['dep1'], '2026-10-21', 'Typo');
    app(ReverseManualJournal::class)->handle($f['finance'], $f['manual'], '2026-10-21');

    expect($early->fresh()->status)->toBe(PaymentStatus::Reversed)
        ->and(journalFor('payment_reversal', $early->id)?->date->toDateString())->toBe('2026-10-21')
        ->and(journalFor('cash_movement_reversal', $f['dep1']->reversal()->sole()->id)?->date->toDateString())->toBe('2026-10-21');
});

test('a credit note does not reverse a reconciled receipt: no money-account entry changes, so it is allowed', function () {
    $f = $this->f;
    $card = (string) LedgerAccount::query()->where('code', '1020')->value('id');
    $omarCard = Payment::query()->where('customer_id', $f['omar']->id)->sole(); // 300.000 by card on 06/10
    bankStatement($f['finance'], '1020', [['07/10/2026', 'CARD SETTLEMENT', '', '300']]);
    $rec = app(StartBankReconciliation::class)->handle($f['finance'], ['ledger_account_id' => $card, 'end_date' => '2026-10-20', 'closing_balance' => '300',
        'opening_balance' => '0']);
    app(MatchBankLine::class)->handle($f['finance'], $rec, (string) bankLine('CARD SETTLEMENT')->id, ['payment:'.$omarCard->id]);
    app(FinishBankReconciliation::class)->handle($f['finance'], $rec);
    $before = BankEntries::figures($rec->fresh());

    $cn = app(SaveCreditNote::class)->handle($f['finance'], $f['omarRent'], null, ['reason' => 'Goodwill',
        'lines' => [['credited_line_id' => $f['omarRent']->lines->sole()->id, 'amount' => '100.000']]]);
    app(DecideApproval::class)->handle($f['management'], app(SubmitCreditNote::class)->handle($f['finance'], $cn), true);

    expect($cn->fresh()->status->value)->toBe('issued')
        ->and($omarCard->fresh()->status)->not->toBe(PaymentStatus::Reversed)
        ->and(BankEntries::figures($rec->fresh()))->toEqual($before)
        ->and($before['difference'])->toBe(0);
});
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php artisan test tests/Feature/Ledger/BankReversalGuardTest.php`
Expected: FAIL. The first test's `$decide($askedEarly)` succeeds instead of throwing (no guard yet).

- [ ] **Step 3: The guard.** In `app/Ledger/BankEntries.php` add `use Carbon\CarbonImmutable;` and `use Illuminate\Validation\ValidationException;` to the imports, and after `figures()` add:

```php
    /**
     * Plan ruling 5 (spec §4 "Protection"): refuses, on $field, a reversal dated $date of the entry ($type, $id) when a finished
     * reconciliation ending on or after $date has matched it. A reversal dated after every such end date is fine. Called by
     * every reversal path with the date its reversal posts on.
     */
    public static function assertReversible(string $type, int $id, string $date, string $field): void
    {
        $end = DB::table('bank_matches as m')->join('bank_reconciliations as r', 'r.id', '=', 'm.bank_reconciliation_id')
            ->where('m.entry_type', $type)->where('m.entry_id', $id)->where('r.status', 'finished')->where('r.end_date', '>=', $date)->max('r.end_date');
        if ($end !== null) {
            throw ValidationException::withMessages([$field => __('This entry is reconciled with the bank statement up to :date, so it cannot be reversed on or before that day.',
                ['date' => CarbonImmutable::parse((string) $end)->format('d/m/Y')])]);
        }
    }
```

- [ ] **Step 4: Call it from every reversal path.** Add `use App\Ledger\BankEntries;` to the imports of each file below (keep them sorted).
  - `app/Actions/Payments/RequestPaymentReversal.php`: replace

```php
            return $this->request->handle($actor, $payment, ApprovalAction::PaymentReversal, trim($reason), $payload);
```

    with

```php
            // Bank reconciliation plan ruling 5: payment_reversal posts on the day Management approves; today is the earliest.
            BankEntries::assertReversible('payment', $payment->id, now('Asia/Bahrain')->toDateString(), 'reversalReason');

            return $this->request->handle($actor, $payment, ApprovalAction::PaymentReversal, trim($reason), $payload);
```

  - `app/Approvals/PaymentReversal.php`: replace

```php
        // Spec §7.2: a payment whose credit was refunded cannot be reversed while the refund stands.
```

    with

```php
        // Bank reconciliation plan ruling 5: the reversal posts today (DATE(reversed_at)).
        BankEntries::assertReversible('payment', $payment->id, now('Asia/Bahrain')->toDateString(), 'approval');

        // Spec §7.2: a payment whose credit was refunded cannot be reversed while the refund stands.
```

  - `app/Actions/Disbursements/RequestDisbursementReversal.php`: replace

```php
            return $this->request->handle($actor, $locked, ApprovalAction::PaymentOutReversal, trim($reason));
```

    with

```php
            // Bank reconciliation plan ruling 5: disbursement_reversal posts on the day Management approves; today is the earliest.
            BankEntries::assertReversible('disbursement', $locked->id, now('Asia/Bahrain')->toDateString(), 'reversalReason');

            return $this->request->handle($actor, $locked, ApprovalAction::PaymentOutReversal, trim($reason));
```

  - `app/Approvals/PaymentOutReversal.php`: in `approve()`, replace

```php
        $out = Disbursement::query()->findOrFail($approval->approvable_id);
        if ($out->payee_type === PayeeType::Customer) {
```

    with

```php
        $out = Disbursement::query()->findOrFail($approval->approvable_id);
        BankEntries::assertReversible('disbursement', $out->id, now('Asia/Bahrain')->toDateString(), 'approval'); // bank reconciliation plan ruling 5
        if ($out->payee_type === PayeeType::Customer) {
```

  - `app/Actions/Expenses/ReverseExpense.php`: replace

```php
                throw ValidationException::withMessages(['reason' => __('This expense is already reversed.')]);
            }
```

    with

```php
                throw ValidationException::withMessages(['reason' => __('This expense is already reversed.')]);
            }
            // Bank reconciliation plan ruling 5: an expense never posts to a money account, so this never refuses; it keeps the rule one rule.
            BankEntries::assertReversible('expense', $expense->id, now('Asia/Bahrain')->toDateString(), 'reason');
```

  - `app/Actions/Ledger/ReverseMoneyEntry.php`: replace

```php
            PostManualJournal::openDate($settings, $date, 'reverseDate');
```

    with

```php
            PostManualJournal::openDate($settings, $date, 'reverseDate');
            BankEntries::assertReversible($original instanceof CashMovement ? 'cash_movement' : 'vat_return', $original->id, $date, 'reverseDate'); // bank reconciliation plan ruling 5
```

  - `app/Actions/Ledger/ReverseManualJournal.php`: replace

```php
            PostManualJournal::openDate($settings, $date, 'reverseDate');
```

    with

```php
            PostManualJournal::openDate($settings, $date, 'reverseDate');
            BankEntries::assertReversible('manual', $journal->id, $date, 'reverseDate'); // bank reconciliation plan ruling 5
```

  - Credit notes get no call (ruling 5): they write no money-account line; the second test proves it.

- [ ] **Step 5: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "This entry is reconciled with the bank statement up to :date, so it cannot be reversed on or before that day.": "هذا القيد مسوّى مع كشف البنك حتى :date، لذا لا يمكن عكسه في ذلك اليوم أو قبله.",
```

- [ ] **Step 6: Run the tests.**

Run: `php artisan test tests/Feature/Ledger/BankReversalGuardTest.php tests/Feature/Ledger/CashMovementTest.php tests/Feature/Ledger/VatReturnTest.php tests/Feature/Ledger/ManualJournalTest.php tests/Feature/Payments tests/Feature/Disbursements tests/Feature/Expenses tests/Feature/Cheques`
Expected: PASS. With no finished reconciliation the guard never refuses, so the existing reversal tests are unchanged.

- [ ] **Step 7: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Ledger/BankEntries.php app/Actions/Payments/RequestPaymentReversal.php app/Approvals/PaymentReversal.php app/Actions/Disbursements/RequestDisbursementReversal.php app/Approvals/PaymentOutReversal.php app/Actions/Expenses/ReverseExpense.php app/Actions/Ledger/ReverseMoneyEntry.php app/Actions/Ledger/ReverseManualJournal.php tests/Feature/Ledger/BankReversalGuardTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
Bank reconciliation protection: one guard refuses reversing a reconciled entry on or before the end date, called from payment, payment out, expense, cash movement, VAT return and manual journal reversals

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: The pages: statements and reconciliations, one reconciliation, the report, routes and sidebar

**Files:**
- Create: `app/Livewire/Ledger/BankReconciliations.php`, `resources/views/livewire/ledger/bank-reconciliations.blade.php`
- Create: `app/Livewire/Ledger/BankReconciliationShow.php`, `resources/views/livewire/ledger/bank-reconciliation-show.blade.php`
- Modify: `routes/ledger.php`, `resources/views/layouts/app/sidebar.blade.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/BankReconciliationPagesTest.php`

**Interfaces:**
- Consumes: every Action of Tasks 2–4; `BankEntries::{key, parseKeys, unmatched, find, suggestions, figures}` and its `Figures` type; `Sources::label()`, `Sources::url()`; `SafeCells::row()`; `WithActor`; `<x-date-input>`, `<x-file-button>`.
- Produces:
  - Routes `ledger.bank-reconciliation` (`/ledger/bank-reconciliation?account=<id>`) and `ledger.bank-reconciliation.show` (`/ledger/bank-reconciliation/{reconciliation}`).
  - `BankReconciliations` public props: `account`, `file`, the ten mapping props (`header_row` … `balance_column`, all strings), `result` (locked), `end_date`, `closing_balance`, `opening_balance`; methods `upload()`, `deleteUpload(mixed $id)`, `start()`.
  - `BankReconciliationShow` public props: `reconciliationId` (locked), `lineId` (locked), `picked`, `search`, `kind`, `otherAccount`; methods `pick(mixed $id)`, `cancelPick()`, `match()`, `bankOnly()`, `unmatch(mixed $lineId)`, `finish()`, `reopen()`, `discard()`, `download(): BinaryFileResponse`; statics `figureRows(Figures $f): list<array{0: string, 1: string}>`, `reportRows(BankReconciliation): list<list<string>>`, `reportFile(BankReconciliation): string` (a temporary .xlsx path).
  - Download file name: `bank-reconciliation-<account code>-<end date Y-m-d>.xlsx`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Ledger/BankReconciliationPagesTest.php`:

```php
<?php

use App\Actions\Ledger\MatchBankLine;
use App\Actions\Ledger\PostManualJournal;
use App\Enums\RoleName;
use App\Livewire\Ledger\BankReconciliations;
use App\Livewire\Ledger\BankReconciliationShow;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\BankUpload;
use App\Models\CashMovement;
use App\Models\LedgerAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = bankRecFixture();
});

test('Finance uploads a statement from the page; the saved mapping comes back with the account', function () {
    $f = $this->f;
    $csv = "Posted,Narrative,Out,In\n2026-10-16,ATM CASH,25.000,\n2026-10-16,BAD,x,\n";

    Livewire::actingAs($f['finance'])->test(BankReconciliations::class)
        ->set('account', (string) $f['bank'])
        ->assertSet('amount_column', '4') // bankRecFixture()'s upload saved the CSV layout
        ->set('date_format', 'Y-m-d')->set('reference_column', '')->set('amount_mode', 'split')->set('amount_column', '')->set('debit_column', '3')->set('credit_column', '4')
        ->set('file', UploadedFile::fake()->createWithContent('october.csv', $csv))
        ->call('upload')->assertHasNoErrors()
        ->assertSee('Imported 1, skipped as duplicates 0, failed 1.')
        ->assertSee('Row 3: The amount is not a number with up to 3 decimals.');

    expect(bankLine('ATM CASH')->amount)->toBe('-25.000');
    Livewire::actingAs($f['finance'])->test(BankReconciliations::class)
        ->set('account', (string) $f['cash'])->assertSet('amount_mode', 'signed')->assertSet('amount_column', '3') // no mapping for Cash yet: the defaults
        ->set('account', (string) $f['bank'])->assertSet('amount_mode', 'split')->assertSet('debit_column', '3')->assertSet('reference_column', '');
});

test('the page refuses a file over 5 MB or of another type', function () {
    Livewire::actingAs($this->f['finance'])->test(BankReconciliations::class)->set('account', (string) $this->f['bank'])
        ->set('file', UploadedFile::fake()->create('big.csv', 5121))->call('upload')->assertHasErrors('file')
        ->set('file', UploadedFile::fake()->createWithContent('statement.pdf', 'x'))->call('upload')->assertHasErrors('file');

    expect(BankUpload::query()->count())->toBe(1); // the fixture's
});

test('an unmatched upload is deleted from the page; a matched one shows no delete button', function () {
    $f = $this->f;
    $extra = bankStatement($f['finance'], '1010', [['18/10/2026', 'EXTRA', '', '9']]);
    $first = BankUpload::query()->orderBy('id')->value('id');
    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    app(MatchBankLine::class)->handle($f['finance'], $rec, (string) bankLine('TRANSFER '.$f['headLease']->number)->id, ['disbursement:'.$f['headLease']->id]);

    Livewire::actingAs($f['finance'])->test(BankReconciliations::class)->set('account', (string) $f['bank'])
        ->assertSeeHtml('wire:click="deleteUpload('.$extra['upload']->id.')"')
        ->assertDontSeeHtml('wire:click="deleteUpload('.$first.')"')
        ->call('deleteUpload', (string) $extra['upload']->id)->assertHasNoErrors()
        ->call('deleteUpload', 'x')->assertNotFound();

    expect(BankUpload::query()->pluck('id')->all())->toBe([$first]);
});

test('Finance reconciles on the page: start, suggestions, a many-to-one match, bank-only lines, finish and the report', function () {
    $f = $this->f;
    Livewire::actingAs($f['finance'])->test(BankReconciliations::class)->set('account', (string) $f['bank'])
        ->assertSee('Statement opening balance') // the first reconciliation of Bank asks for it
        ->set('end_date', '2026-10-20')->set('closing_balance', '1198.700')->set('opening_balance', '1000')
        ->call('start')->assertHasNoErrors()->assertRedirect(route('ledger.bank-reconciliation.show', BankReconciliation::query()->sole()->id));
    $rec = BankReconciliation::query()->sole();

    Livewire::actingAs($f['finance'])->test(BankReconciliationShow::class, ['reconciliation' => (string) $rec->id])
        ->assertSee('198.700') // the difference before matching
        ->call('pick', bankLine('TRANSFER '.$f['headLease']->number)->id)
        ->assertSeeHtml('value="disbursement:'.$f['headLease']->id.'"')
        ->set('picked', ['disbursement:'.$f['headLease']->id])->call('match')->assertHasNoErrors()
        ->call('pick', bankLine('CASH DEPOSIT DEP-1 DEP-2')->id)
        ->set('picked', ['cash_movement:'.$f['dep1']->id])->call('match')->assertHasErrors('picked')
        ->set('picked', ['cash_movement:'.$f['dep1']->id, 'cash_movement:'.$f['dep2']->id])->call('match')->assertHasNoErrors()
        ->call('pick', bankLine('BANK CHARGES OCT')->id)->set('kind', 'bank_charge')->call('bankOnly')->assertHasNoErrors()
        ->call('pick', bankLine('CREDIT INTEREST')->id)->set('kind', 'bank_interest')->call('bankOnly')->assertHasNoErrors()
        ->call('finish')->assertHasNoErrors()
        ->assertSee('Finished')->assertDontSeeHtml('wire:click="finish"')
        ->call('download')->assertFileDownloaded('bank-reconciliation-1010-2026-10-20.xlsx');

    expect($rec->fresh()->status)->toBe('finished')
        ->and(BankMatch::query()->count())->toBe(5)
        ->and(CashMovement::query()->where('reference', 'CHG-OCT')->sole()->kind->value)->toBe('bank_charge');
});

test('matches come off from the page; Admin reopens a finished reconciliation and never sees Finance\'s buttons', function () {
    $f = $this->f;
    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    $line = bankLine('TRANSFER '.$f['headLease']->number)->id;
    app(MatchBankLine::class)->handle($f['finance'], $rec, (string) $line, ['disbursement:'.$f['headLease']->id]);

    Livewire::actingAs($f['finance'])->test(BankReconciliationShow::class, ['reconciliation' => (string) $rec->id])
        ->assertSeeHtml('wire:click="unmatch('.$line.')"')->call('unmatch', (string) $line)->assertHasNoErrors();
    expect(BankMatch::query()->count())->toBe(0);

    Livewire::actingAs($f['admin'])->test(BankReconciliationShow::class, ['reconciliation' => (string) $rec->id])
        ->assertDontSeeHtml('wire:click="finish"')->assertDontSeeHtml('wire:click="pick(')->assertDontSeeHtml('wire:click="reopen"')
        ->call('pick', (string) $line)->assertForbidden();

    $rec->forceFill(['status' => 'finished', 'finished_by' => $f['finance']->id, 'finished_at' => now()])->save();
    Livewire::actingAs($f['admin'])->test(BankReconciliationShow::class, ['reconciliation' => (string) $rec->id])
        ->call('reopen')->assertHasNoErrors();
    expect($rec->fresh()->status)->toBe('open');
});

test('tampered page input gets a message or a 404, never a 500', function () {
    $f = $this->f;
    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');

    Livewire::actingAs($f['finance'])->test(BankReconciliationShow::class, ['reconciliation' => (string) $rec->id])
        ->call('pick', 'abc')->assertNotFound();
    Livewire::actingAs($f['finance'])->test(BankReconciliationShow::class, ['reconciliation' => (string) $rec->id])
        ->call('pick', (string) bankLine('CREDIT INTEREST')->id)
        ->set('picked', 'x')->call('match')->assertHasErrors('picked')
        ->set('picked', ['x' => ['y']])->set('search', ['z'])->call('match')->assertHasErrors('picked')
        ->set('kind', ['x'])->call('bankOnly')->assertHasErrors('kind')
        ->set('kind', 'transfer')->set('otherAccount', ['x'])->call('bankOnly')->assertHasErrors('from_account_id')
        ->call('unmatch', 'abc')->assertHasErrors('line');
    Livewire::actingAs($f['finance'])->test(BankReconciliationShow::class, ['reconciliation' => 'abc'])->assertNotFound();
    Livewire::actingAs($f['finance'])->test(BankReconciliations::class)
        ->set('account', 'abc')->assertOk()->assertDontSee('Upload a statement')
        ->set('account', (string) $f['bank'])->set('header_row', ['x'])->set('file', UploadedFile::fake()->createWithContent('s.csv', "a\n"))
        ->call('upload')->assertHasErrors('header_row')
        ->set('header_row', '1')->set('closing_balance', ['x'])->call('start')->assertHasErrors('closing_balance');
});

test('the report: the figures and both lists, every cell text, never a formula', function () {
    $f = $this->f;
    app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-18', 'description' => '=1+2', 'lines' => [
        ['account_id' => (int) LedgerAccount::query()->where('code', '5100')->value('id'), 'debit' => '1'], ['account_id' => $f['bank'], 'credit' => '1']]]);
    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    $path = BankReconciliationShow::reportFile($rec);
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_starts_with((string) $zip->getNameIndex($i), 'xl/worksheets/')) {
            $xml .= $zip->getFromIndex($i);
        }
    }
    $zip->close();
    preg_match_all('#<t[^>]*>(.*?)</t>#', $xml, $texts);

    expect(preg_match_all('/<f[ >]/', $xml))->toBe(0)
        ->and(array_map(fn (string $s) => html_entity_decode($s, ENT_QUOTES), $texts[1]))->toContain('=1+2')
        ->and(BankReconciliationShow::reportRows($rec)[0])->toBe(['Bank reconciliation', '1010 Bank', '20/10/2026', 'Open'])
        ->and(collect(BankReconciliationShow::reportRows($rec))->firstWhere(0, '18/10/2026'))->toBe(['18/10/2026', 'Manual journal', '=1+2', '-1.000']);
    unlink($path);
});

test('Finance and Admin reach the bank reconciliation pages; nobody else; a bad id is a 404', function () {
    $f = $this->f;
    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    $leasing = matrixUser(RoleName::Leasing, $f['tower']);

    foreach ([route('ledger.bank-reconciliation'), route('ledger.bank-reconciliation.show', $rec->id)] as $url) {
        $this->actingAs($f['management'])->get($url)->assertForbidden();
        $this->actingAs($leasing)->get($url)->assertForbidden();
        $this->actingAs($f['finance'])->get($url)->assertOk();
        $this->actingAs($f['admin'])->get($url)->assertOk();
    }
    $this->actingAs($f['finance'])->get('/ledger/bank-reconciliation/abc')->assertNotFound();
    $this->actingAs($f['finance'])->get('/ledger/bank-reconciliation/999999')->assertNotFound();
    $this->actingAs($f['admin'])->get(route('ledger.bank-reconciliation', ['account' => (string) $f['bank']]))->assertOk()->assertDontSee('Upload a statement');
    $this->actingAs($f['finance'])->get(route('ledger.index'))->assertSee('Bank reconciliation');
});
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php artisan test tests/Feature/Ledger/BankReconciliationPagesTest.php`
Expected: FAIL. Class `App\Livewire\Ledger\BankReconciliations` not found.

- [ ] **Step 3: The statements and reconciliations page** `app/Livewire/Ledger/BankReconciliations.php`:

```php
<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\DeleteBankUpload;
use App\Actions\Ledger\ImportBankStatement;
use App\Actions\Ledger\StartBankReconciliation;
use App\Enums\PermissionName;
use App\Livewire\Concerns\WithActor;
use App\Models\BankMapping;
use App\Models\BankReconciliation;
use App\Models\BankUpload;
use App\Models\LedgerAccount;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Bank reconciliation spec §2–§3: per money account, Finance uploads statements (with the saved column mapping) and starts a reconciliation; Finance and Admin view. */
class BankReconciliations extends Component
{
    use WithActor, WithFileUploads;

    /** The mapping fields, as ImportBankStatement takes them. */
    private const array MAPPING = ['header_row', 'date_column', 'date_format', 'description_column', 'reference_column', 'amount_mode', 'amount_column',
        'debit_column', 'credit_column', 'balance_column'];

    // Untyped on purpose: a tampered payload reaches the action's validator, never a TypeError (a 500).
    #[Url]
    public mixed $account = '';

    public mixed $file = null;

    public mixed $header_row = '1';

    public mixed $date_column = '1';

    public mixed $date_format = 'd/m/Y';

    public mixed $description_column = '2';

    public mixed $reference_column = '';

    public mixed $amount_mode = 'signed';

    public mixed $amount_column = '3';

    public mixed $debit_column = '';

    public mixed $credit_column = '';

    public mixed $balance_column = '';

    /** @var array{imported: int, skipped: int, failures: list<array{row: int, reason: string}>}|null */
    #[Locked]
    public ?array $result = null;

    public mixed $end_date = '';

    public mixed $closing_balance = '';

    public mixed $opening_balance = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerView), 403);
        $this->end_date = now('Asia/Bahrain')->toDateString();
        $this->loadMapping();
    }

    public function updatedAccount(): void
    {
        $this->result = null;
        $this->resetErrorBag();
        $this->loadMapping();
    }

    public function upload(ImportBankStatement $import): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerManage), 403);
        $this->validate(['file' => ['required', 'file', 'extensions:csv,xlsx', 'max:5120']], ['file.max' => __('The file must be 5 MB or smaller.')]);
        $file = $this->file;
        if (! $file instanceof UploadedFile) {
            return;
        }
        $type = strtolower($file->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx';
        $stored = $file->storeAs('bank-statements', Str::uuid().'.'.$type, 'local') ?: abort(500);
        try {
            $out = $import->handle($this->actor(), $this->account, Storage::disk('local')->path($stored), $type, $file->getClientOriginalName(),
                array_combine(self::MAPPING, array_map(fn (string $field) => $this->{$field}, self::MAPPING)));
        } finally {
            Storage::disk('local')->delete($stored);
        }
        $this->result = ['imported' => $out['upload']->imported, 'skipped' => $out['upload']->skipped, 'failures' => $out['failures']];
        $this->reset('file');
        Flux::toast(variant: 'success', text: __('Statement uploaded.'));
    }

    public function deleteUpload(mixed $id): void
    {
        abort_unless(is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) < 19), 404);
        app(DeleteBankUpload::class)->handle($this->actor(), BankUpload::query()->findOrFail((int) $id));
        Flux::toast(variant: 'success', text: __('Upload deleted.'));
    }

    public function start(): void
    {
        $rec = app(StartBankReconciliation::class)->handle($this->actor(), ['ledger_account_id' => $this->account, 'end_date' => $this->end_date,
            'closing_balance' => $this->closing_balance, 'opening_balance' => $this->opening_balance]);
        Flux::toast(variant: 'success', text: __('Reconciliation started.'));
        $this->redirectRoute('ledger.bank-reconciliation.show', ['reconciliation' => $rec->id], navigate: true);
    }

    /** The chosen account's id when it is a money account; null otherwise (a tampered URL just shows the picker). */
    private function accountId(): ?int
    {
        $id = is_string($this->account) && ctype_digit($this->account) && strlen($this->account) < 19 ? (int) $this->account : 0;
        $found = LedgerAccount::query()->whereKey($id)->where('is_money', true)->value('id');

        return $found === null ? null : (int) $found;
    }

    /** The account's saved mapping (plan ruling 7), or the defaults when it has none. */
    private function loadMapping(): void
    {
        $id = $this->accountId();
        $mapping = $id === null ? null : BankMapping::query()->where('ledger_account_id', $id)->first();
        if ($mapping === null) {
            $this->reset(...self::MAPPING);

            return;
        }
        foreach (self::MAPPING as $field) {
            $value = $mapping->{$field};
            $this->{$field} = $value === null ? '' : (string) $value;
        }
    }

    public function render(): View
    {
        $id = $this->accountId();

        return view('livewire.ledger.bank-reconciliations', [
            'accounts' => LedgerAccount::query()->where('is_money', true)->orderBy('code')->get(),
            'selected' => $id,
            'columns' => array_combine(range(1, 26), range('A', 'Z')),
            'formats' => ImportBankStatement::DATE_FORMATS,
            'uploads' => $id === null ? collect() : BankUpload::query()->with('uploader')->where('ledger_account_id', $id)
                ->withExists(['lines as matched' => fn (Builder $q) => $q->has('matches')])->latest('id')->limit(50)->get(),
            'reconciliations' => $id === null ? collect() : BankReconciliation::query()->where('ledger_account_id', $id)->orderByDesc('end_date')->get(),
            'hasOpen' => $id !== null && BankReconciliation::query()->where('ledger_account_id', $id)->where('status', 'open')->exists(),
            'first' => $id !== null && ! BankReconciliation::query()->where('ledger_account_id', $id)->exists(),
            'canManage' => $this->actor()->can(PermissionName::LedgerManage),
        ])->title(__('Bank reconciliation'));
    }
}
```

- [ ] **Step 4: Its view** `resources/views/livewire/ledger/bank-reconciliations.blade.php`:

```blade
<section class="w-full max-w-6xl space-y-8">
    <flux:heading size="xl" level="1">{{ __('Bank reconciliation') }}</flux:heading>

    <div class="max-w-sm">
        <flux:select wire:model.live="account" :label="__('Money account')">
            <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
            @foreach ($accounts as $a)
                <flux:select.option :value="(string) $a->id">{{ $a->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:error name="account" />
        <flux:error name="ledger_account_id" />
    </div>

    @if ($selected !== null)
        @if ($canManage)
            <form wire:submit="upload" class="space-y-4">
                <flux:heading size="lg">{{ __('Upload a statement') }}</flux:heading>
                <div class="grid gap-3 sm:grid-cols-4">
                    <flux:input wire:model="header_row" inputmode="numeric" :label="__('Header row (0 for none)')" />
                    <flux:select wire:model="date_column" :label="__('Date column')">
                        @foreach ($columns as $n => $letter)
                            <flux:select.option :value="(string) $n">{{ $letter }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="date_format" :label="__('Date format')">
                        @foreach ($formats as $format => $shown)
                            <flux:select.option :value="$format">{{ $shown }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="description_column" :label="__('Description column')">
                        @foreach ($columns as $n => $letter)
                            <flux:select.option :value="(string) $n">{{ $letter }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="reference_column" :label="__('Reference column')">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach ($columns as $n => $letter)
                            <flux:select.option :value="(string) $n">{{ $letter }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="amount_mode" :label="__('Amounts')">
                        <flux:select.option value="signed">{{ __('One signed column (− is money out)') }}</flux:select.option>
                        <flux:select.option value="split">{{ __('Debit and credit columns') }}</flux:select.option>
                    </flux:select>
                    @if ($amount_mode === 'split')
                        <flux:select wire:model="debit_column" :label="__('Debit column (money out)')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach ($columns as $n => $letter)
                                <flux:select.option :value="(string) $n">{{ $letter }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="credit_column" :label="__('Credit column (money in)')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach ($columns as $n => $letter)
                                <flux:select.option :value="(string) $n">{{ $letter }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        <flux:select wire:model="amount_column" :label="__('Amount column')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach ($columns as $n => $letter)
                                <flux:select.option :value="(string) $n">{{ $letter }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                    <flux:select wire:model="balance_column" :label="__('Balance column')">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach ($columns as $n => $letter)
                            <flux:select.option :value="(string) $n">{{ $letter }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <x-file-button wire:model="file" accept=".csv,.xlsx" :label="__('Choose statement file')" />
                @foreach (['header_row', 'date_column', 'date_format', 'description_column', 'reference_column', 'amount_mode', 'amount_column', 'debit_column', 'credit_column', 'balance_column', 'file'] as $field)
                    <flux:error name="{{ $field }}" />
                @endforeach
                <flux:button type="submit" variant="primary">{{ __('Upload') }}</flux:button>
            </form>
        @endif

        @if ($result !== null)
            <div class="space-y-1 text-sm">
                <p>{{ __('Imported :imported, skipped as duplicates :skipped, failed :failed.', ['imported' => $result['imported'], 'skipped' => $result['skipped'], 'failed' => count($result['failures'])]) }}</p>
                @foreach ($result['failures'] as $failure)
                    <p>{{ __('Row :row: :reason', ['row' => $failure['row'], 'reason' => $failure['reason']]) }}</p>
                @endforeach
            </div>
        @endif

        <div class="space-y-2">
            <flux:heading size="lg">{{ __('Uploads') }}</flux:heading>
            <flux:error name="upload" />
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('File') }}</flux:table.column>
                    <flux:table.column>{{ __('Uploaded') }}</flux:table.column>
                    <flux:table.column>{{ __('Uploaded by') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Imported') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Skipped') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Failed') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($uploads as $upload)
                        <flux:table.row :key="'up-'.$upload->id">
                            <flux:table.cell>{{ $upload->file_name }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">{{ $upload->created_at->timezone('Asia/Bahrain')->format('d/m/Y H:i') }}</flux:table.cell>
                            <flux:table.cell>{{ $upload->uploader->name }}</flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $upload->imported }}</flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $upload->skipped }}</flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $upload->failed }}</flux:table.cell>
                            <flux:table.cell class="text-end">
                                @if ($canManage && ! $upload->matched)
                                    <flux:button size="sm" variant="danger" wire:click="deleteUpload({{ $upload->id }})" wire:confirm="{{ __('Delete this upload and its lines?') }}">{{ __('Delete') }}</flux:button>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        <div class="space-y-2">
            <flux:heading size="lg">{{ __('Reconciliations') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('End date') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Opening balance') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Closing balance') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($reconciliations as $r)
                        <flux:table.row :key="'rec-'.$r->id">
                            <flux:table.cell class="whitespace-nowrap">
                                <a href="{{ route('ledger.bank-reconciliation.show', $r->id) }}" wire:navigate class="underline">{{ $r->end_date->format('d/m/Y') }}</a>
                            </flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $r->opening_balance }}</flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $r->closing_balance }}</flux:table.cell>
                            <flux:table.cell>{{ $r->status === 'open' ? __('Open') : __('Finished') }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        @if ($canManage && ! $hasOpen)
            <form wire:submit="start" class="space-y-3">
                <flux:heading size="lg">{{ __('Start a reconciliation') }}</flux:heading>
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-date-input wire:model="end_date" :label="__('Statement end date')" />
                    <flux:input wire:model="closing_balance" inputmode="decimal" :label="__('Statement closing balance')" />
                    @if ($first)
                        <flux:input wire:model="opening_balance" inputmode="decimal" :label="__('Statement opening balance')" />
                    @endif
                </div>
                @foreach (['end_date', 'closing_balance', 'opening_balance'] as $field)
                    <flux:error name="{{ $field }}" />
                @endforeach
                <flux:button type="submit" variant="primary">{{ __('Start') }}</flux:button>
            </form>
        @endif
    @endif
</section>
```

- [ ] **Step 5: The reconciliation page** `app/Livewire/Ledger/BankReconciliationShow.php`:

```php
<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\DiscardBankReconciliation;
use App\Actions\Ledger\FinishBankReconciliation;
use App\Actions\Ledger\MatchBankLine;
use App\Actions\Ledger\RecordBankOnlyLine;
use App\Actions\Ledger\ReopenBankReconciliation;
use App\Actions\Ledger\UnmatchBankLine;
use App\Audit\Audit;
use App\Enums\CashMovementKind;
use App\Enums\PermissionName;
use App\Ledger\BankEntries;
use App\Ledger\Sources;
use App\Livewire\Concerns\WithActor;
use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Models\LedgerAccount;
use App\Support\Fils;
use App\Support\SafeCells;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\SimpleExcel\SimpleExcelWriter;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bank reconciliation spec §3–§4: one reconciliation. Finance matches statement lines to ledger entries (suggestions first),
 * records bank-only lines, finishes or discards; Admin reopens; Finance and Admin view and download the report.
 *
 * @phpstan-import-type Figures from BankEntries
 */
class BankReconciliationShow extends Component
{
    use WithActor;

    #[Locked]
    public int $reconciliationId;

    #[Locked]
    public ?int $lineId = null;

    // Untyped on purpose: a tampered payload reaches the action's validator, never a TypeError (a 500).
    public mixed $picked = [];

    public mixed $search = '';

    public mixed $kind = 'bank_charge';

    public mixed $otherAccount = '';

    public function mount(string $reconciliation): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerView), 403);
        $this->reconciliationId = BankReconciliation::query()->findOrFail(ctype_digit($reconciliation) && strlen($reconciliation) < 19 ? (int) $reconciliation : 0)->id;
    }

    public function pick(mixed $id): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerManage), 403);
        abort_unless(is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) < 19), 404);
        $this->lineId = BankStatementLine::query()->where('ledger_account_id', $this->rec()->ledger_account_id)->findOrFail((int) $id)->id;
        $this->picked = [];
        $this->search = '';
        $this->resetErrorBag();
    }

    public function cancelPick(): void
    {
        $this->lineId = null;
        $this->picked = [];
        $this->resetErrorBag();
    }

    public function match(): void
    {
        abort_if($this->lineId === null, 404);
        app(MatchBankLine::class)->handle($this->actor(), $this->rec(), (string) $this->lineId, $this->picked);
        $this->cancelPick();
        Flux::toast(variant: 'success', text: __('Matched.'));
    }

    public function bankOnly(): void
    {
        abort_if($this->lineId === null, 404);
        app(RecordBankOnlyLine::class)->handle($this->actor(), $this->rec(), (string) $this->lineId, $this->kind, $this->otherAccount);
        $this->cancelPick();
        Flux::toast(variant: 'success', text: __('Recorded and matched.'));
    }

    public function unmatch(mixed $lineId): void
    {
        app(UnmatchBankLine::class)->handle($this->actor(), $this->rec(), $lineId);
        Flux::toast(variant: 'success', text: __('Match removed.'));
    }

    public function finish(): void
    {
        app(FinishBankReconciliation::class)->handle($this->actor(), $this->rec());
        $this->cancelPick();
        Flux::toast(variant: 'success', text: __('Reconciliation finished.'));
    }

    public function reopen(): void
    {
        app(ReopenBankReconciliation::class)->handle($this->actor(), $this->rec());
        Flux::toast(variant: 'success', text: __('Reconciliation reopened.'));
    }

    public function discard(): void
    {
        $rec = $this->rec();
        app(DiscardBankReconciliation::class)->handle($this->actor(), $rec);
        Flux::toast(variant: 'success', text: __('Reconciliation discarded.'));
        $this->skipRender(); // the reconciliation is gone
        $this->redirectRoute('ledger.bank-reconciliation', ['account' => (string) $rec->ledger_account_id], navigate: true);
    }

    public function download(): BinaryFileResponse
    {
        abort_unless($this->actor()->can(PermissionName::LedgerView), 403);
        $rec = $this->rec();
        Audit::log('report.exported', properties: ['report' => 'bank_reconciliation', 'reconciliation' => $rec->id], causer: $this->actor());

        return response()->download(self::reportFile($rec), 'bank-reconciliation-'.$rec->account->code.'-'.$rec->end_date->toDateString().'.xlsx')->deleteFileAfterSend();
    }

    /** Spec §4: the reconciliation report as a temporary .xlsx, every row through SafeCells. Returns its path. */
    public static function reportFile(BankReconciliation $rec): string
    {
        $path = sys_get_temp_dir().'/rms-bank-rec-'.Str::uuid().'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        foreach (self::reportRows($rec) as $row) {
            $writer->addRow(SafeCells::row($row));
        }
        $writer->close();

        return $path;
    }

    /** @return list<list<string>> the heading, the nine figures, then the in-transit and outstanding lists */
    public static function reportRows(BankReconciliation $rec): array
    {
        $f = BankEntries::figures($rec);
        $rows = [[__('Bank reconciliation'), $rec->account->label(), $rec->end_date->format('d/m/Y'), $rec->status === 'open' ? __('Open') : __('Finished')]];
        foreach (self::figureRows($f) as $row) {
            $rows[] = $row;
        }
        foreach ([[__('Deposits in transit'), $f['in']], [__('Outstanding payments'), $f['out']]] as [$title, $entries]) {
            $rows[] = [$title];
            $rows[] = [__('Date'), __('Source'), __('Description'), __('Amount')];
            foreach (self::rows($entries) as $e) {
                $rows[] = [$e['date'], $e['source'], $e['description'], $e['amount']];
            }
        }

        return $rows;
    }

    /**
     * Plan ruling 4's figures, as the page and the report show them.
     *
     * @param  Figures  $f
     * @return list<array{0: string, 1: string}>
     */
    public static function figureRows(array $f): array
    {
        return [
            [__('Statement opening balance'), Fils::toDecimal($f['opening'])],
            [__('Statement closing balance'), Fils::toDecimal($f['closing'])],
            [__('Deposits in transit'), Fils::toDecimal($f['in_transit'])],
            [__('Outstanding payments'), Fils::toDecimal($f['outstanding'])],
            [__('Adjusted balance'), Fils::toDecimal($f['adjusted'])],
            [__('Ledger balance at the end date'), Fils::toDecimal($f['ledger'])],
            [__('Difference'), Fils::toDecimal($f['difference'])],
            [__('Statement lines not matched'), (string) $f['unmatched_lines']],
            [__('Opening balance plus matched statement lines'), Fils::toDecimal($f['opening'] + $f['movement'])],
        ];
    }

    /**
     * @param  list<stdClass>  $entries  rows as BankEntries::query() selects them
     * @return list<array{key: string, date: string, source: string, description: string, amount: string, url: string}>
     */
    private static function rows(array $entries): array
    {
        return array_map(fn (stdClass $e) => [
            'key' => BankEntries::key((string) $e->entry_type, (int) $e->entry_id),
            'date' => CarbonImmutable::parse((string) $e->date)->format('d/m/Y'),
            'source' => Sources::label((string) $e->entry_type),
            'description' => (string) $e->description,
            'amount' => Fils::toDecimal(Fils::fromDecimal((string) $e->amount)),
            'url' => Sources::url((string) $e->kind, $e->source_type === null ? null : (string) $e->source_type, $e->source_id === null ? null : (int) $e->source_id,
                null, (int) $e->journal),
        ], $entries);
    }

    private function rec(): BankReconciliation
    {
        return BankReconciliation::query()->with(['account', 'finisher'])->findOrFail($this->reconciliationId);
    }

    /** @return list<stdClass> up to 100 other unmatched entries by the end date, nearest the line's date first; the search is a description or an amount */
    private function others(BankReconciliation $rec, BankStatementLine $line): array
    {
        $search = is_string($this->search) ? mb_substr(trim($this->search), 0, 100) : '';
        $query = BankEntries::unmatched($rec->ledger_account_id, $rec->end_date->toDateString());
        if (preg_match('/^-?\d{1,12}(\.\d{1,3})?$/', $search) === 1) {
            $query->havingRaw('ABS(SUM(l.debit) - SUM(l.credit)) = ?', [Fils::toDecimal(abs(Fils::fromDecimal($search)))]);
        } elseif ($search !== '') {
            $query->where('j.description', 'like', '%'.addcslashes($search, '%_\\').'%');
        }

        return array_values($query->orderByRaw('ABS(DATEDIFF(j.date, ?))', [$line->date->toDateString()])->orderBy('j.id')->limit(100)->get()->all());
    }

    public function render(): View
    {
        $rec = $this->rec();
        $end = $rec->end_date->toDateString();
        $open = $rec->status === 'open';
        $f = BankEntries::figures($rec);
        $line = $this->lineId === null || ! $open ? null : BankStatementLine::query()->find($this->lineId);

        return view('livewire.ledger.bank-reconciliation-show', [
            'rec' => $rec,
            'open' => $open,
            'figures' => self::figureRows($f),
            'unmatched' => BankStatementLine::query()->where('ledger_account_id', $rec->ledger_account_id)->where('date', '<=', $end)->whereDoesntHave('matches')
                ->orderBy('date')->orderBy('id')->get(),
            'matched' => BankStatementLine::query()->whereHas('matches', fn (EloquentBuilder $q) => $q->where('bank_reconciliation_id', $rec->id))
                ->with('matches')->orderBy('date')->orderBy('id')->get(),
            'line' => $line,
            'suggestions' => $line === null ? [] : self::rows(BankEntries::suggestions($rec, $line)),
            'others' => $line === null ? [] : self::rows($this->others($rec, $line)),
            'chosen' => Fils::toDecimal(array_sum(array_map(fn (stdClass $e) => Fils::fromDecimal((string) $e->amount),
                BankEntries::find($rec->ledger_account_id, $end, BankEntries::parseKeys($this->picked) ?? [])))),
            'inTransit' => self::rows($f['in']),
            'outstanding' => self::rows($f['out']),
            'kinds' => CashMovementKind::cases(),
            'money' => LedgerAccount::query()->where('is_money', true)->where('active', true)->whereKeyNot($rec->ledger_account_id)->orderBy('code')->get(),
            'canManage' => $this->actor()->can(PermissionName::LedgerManage),
            'canAdmin' => $this->actor()->can(PermissionName::LedgerAdmin),
        ])->title(__('Bank reconciliation'));
    }
}
```

- [ ] **Step 6: Its view** `resources/views/livewire/ledger/bank-reconciliation-show.blade.php`:

```blade
<section class="w-full max-w-6xl space-y-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl" level="1">{{ __('Bank reconciliation') }} · {{ $rec->account->label() }} · {{ $rec->end_date->format('d/m/Y') }}</flux:heading>
        <flux:button :href="route('ledger.bank-reconciliation', ['account' => (string) $rec->ledger_account_id])" wire:navigate>{{ __('Back') }}</flux:button>
    </div>
    <p class="text-sm">
        {{ $open ? __('Open') : __('Finished :date by :name', ['date' => $rec->finished_at?->timezone('Asia/Bahrain')->format('d/m/Y H:i'), 'name' => $rec->finisher?->name]) }}
    </p>

    <dl class="grid max-w-xl gap-1 tabular-nums">
        @foreach ($figures as [$label, $value])
            <div class="flex justify-between gap-4"><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
        @endforeach
    </dl>

    <div class="flex flex-wrap gap-2">
        @if ($open && $canManage)
            <flux:button variant="primary" wire:click="finish">{{ __('Finish') }}</flux:button>
            <flux:button variant="danger" wire:click="discard" wire:confirm="{{ __('Discard this open reconciliation and its matches?') }}">{{ __('Discard') }}</flux:button>
        @endif
        @if (! $open && $canAdmin)
            <flux:button wire:click="reopen">{{ __('Reopen') }}</flux:button>
        @endif
        <flux:button wire:click="download">{{ __('Download report') }}</flux:button>
    </div>
    @foreach (['finish', 'discard', 'reopen'] as $field)
        @foreach ($errors->get($field) as $message)
            <p class="text-sm text-red-600">{{ $message }}</p>
        @endforeach
    @endforeach

    <div class="space-y-2">
        <flux:heading size="lg">{{ __('Unmatched statement lines') }}</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Description') }}</flux:table.column>
                <flux:table.column>{{ __('Reference') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($unmatched as $l)
                    <flux:table.row :key="'u-'.$l->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $l->date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $l->description }}</flux:table.cell>
                        <flux:table.cell>{{ $l->reference }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $l->amount }}</flux:table.cell>
                        <flux:table.cell class="text-end">
                            @if ($open && $canManage)
                                <flux:button size="sm" wire:click="pick({{ $l->id }})">{{ __('Match') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    @if ($line !== null)
        <div class="space-y-4 rounded-lg border border-zinc-300 p-4 dark:border-zinc-600">
            <flux:heading size="lg">{{ $line->date->format('d/m/Y') }} · {{ $line->description }} · {{ $line->amount }}</flux:heading>
            <flux:error name="line" />

            <flux:heading>{{ __('Suggested entries') }}</flux:heading>
            @forelse ($suggestions as $e)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live="picked" value="{{ $e['key'] }}" />
                    {{ $e['date'] }} · {{ $e['source'] }} · {{ $e['description'] }} · <span class="tabular-nums">{{ $e['amount'] }}</span>
                </label>
            @empty
                <p class="text-sm">{{ __('No suggestions.') }}</p>
            @endforelse

            <flux:input wire:model.live.debounce.400ms="search" :label="__('Find other entries (description or amount)')" />
            @foreach ($others as $e)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live="picked" value="{{ $e['key'] }}" />
                    {{ $e['date'] }} · {{ $e['source'] }} · {{ $e['description'] }} · <span class="tabular-nums">{{ $e['amount'] }}</span>
                </label>
            @endforeach

            <p class="text-sm tabular-nums">{{ __('Chosen: :total of :amount', ['total' => $chosen, 'amount' => $line->amount]) }}</p>
            <flux:error name="picked" />
            <div class="flex gap-2">
                <flux:button variant="primary" wire:click="match">{{ __('Match') }}</flux:button>
                <flux:button wire:click="cancelPick">{{ __('Cancel') }}</flux:button>
            </div>

            <flux:heading>{{ __('Not in the ledger yet') }}</flux:heading>
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:select wire:model.live="kind" :label="__('Record as')">
                    @foreach ($kinds as $k)
                        <flux:select.option :value="$k->value">{{ $k->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($kind === 'transfer')
                    <flux:select wire:model="otherAccount" :label="__('Other money account')">
                        <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                        @foreach ($money as $a)
                            <flux:select.option :value="(string) $a->id">{{ $a->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>
            @foreach (['kind', 'from_account_id', 'to_account_id', 'date', 'amount', 'reference'] as $field)
                <flux:error name="{{ $field }}" />
            @endforeach
            <flux:button wire:click="bankOnly">{{ __('Record and match') }}</flux:button>
        </div>
    @endif

    <div class="space-y-2">
        <flux:heading size="lg">{{ __('Matched statement lines') }}</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Description') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column>{{ __('Entries') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($matched as $l)
                    <flux:table.row :key="'m-'.$l->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $l->date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $l->description }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $l->amount }}</flux:table.cell>
                        <flux:table.cell>
                            @foreach ($l->matches as $m)
                                <div>{{ \App\Ledger\Sources::label($m->entry_type) }} #{{ $m->entry_id }} · <span class="tabular-nums">{{ $m->amount }}</span></div>
                            @endforeach
                        </flux:table.cell>
                        <flux:table.cell class="text-end">
                            @if ($open && $canManage)
                                <flux:button size="sm" wire:click="unmatch({{ $l->id }})">{{ __('Remove') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    @foreach ([__('Deposits in transit') => $inTransit, __('Outstanding payments') => $outstanding] as $title => $list)
        <div class="space-y-2">
            <flux:heading size="lg">{{ $title }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Source') }}</flux:table.column>
                    <flux:table.column>{{ __('Description') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($list as $e)
                        <flux:table.row :key="'e-'.$e['key']">
                            <flux:table.cell class="whitespace-nowrap">{{ $e['date'] }}</flux:table.cell>
                            <flux:table.cell>{{ $e['source'] }}</flux:table.cell>
                            <flux:table.cell><a href="{{ $e['url'] }}" wire:navigate class="underline">{{ $e['description'] }}</a></flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $e['amount'] }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endforeach
</section>
```

- [ ] **Step 7: Routes and the sidebar.**
  - In `routes/ledger.php`, after the `vat-returns` route add:

```php
    Route::livewire('bank-reconciliation', Ledger\BankReconciliations::class)->middleware('can:ledger.view')->name('bank-reconciliation');
    Route::livewire('bank-reconciliation/{reconciliation}', Ledger\BankReconciliationShow::class)->middleware('can:ledger.view')
        ->whereNumber('reconciliation')->name('bank-reconciliation.show');
```

  - In `resources/views/layouts/app/sidebar.blade.php`, after the "VAT returns" `flux:sidebar.item` line (inside `@can('ledger.view')`) add:

```blade
                            <flux:sidebar.item icon="banknotes" :href="route('ledger.bank-reconciliation')" :current="request()->routeIs('ledger.bank-reconciliation*')" wire:navigate>{{ __('Bank reconciliation') }}</flux:sidebar.item>
```

- [ ] **Step 8: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert (`"Money account"`, `"Choose…"`, `"Amounts"`, `"File"`, `"Delete"`, `"Opening balance"`, `"Closing balance"`, `"Status"`, `"Open"`, `"Date"`, `"Description"`, `"Reference"`, `"Amount"`, `"Source"`, `"Difference"`, `"Cancel"` and `"Remove"` are already there):

```json
    "Bank reconciliation": "التسوية البنكية",
    "Upload a statement": "رفع كشف حساب",
    "Header row (0 for none)": "صف العناوين (0 إن لم يوجد)",
    "Date column": "عمود التاريخ",
    "Date format": "صيغة التاريخ",
    "Description column": "عمود الوصف",
    "Reference column": "عمود المرجع",
    "One signed column (− is money out)": "عمود واحد بإشارة (السالب مبلغ خارج)",
    "Debit and credit columns": "عمودا المدين والدائن",
    "Debit column (money out)": "عمود المدين (مبلغ خارج)",
    "Credit column (money in)": "عمود الدائن (مبلغ داخل)",
    "Amount column": "عمود المبلغ",
    "Balance column": "عمود الرصيد",
    "Choose statement file": "اختر ملف الكشف",
    "Upload": "رفع",
    "The file must be 5 MB or smaller.": "يجب ألا يتجاوز حجم الملف 5 ميغابايت.",
    "Statement uploaded.": "تم رفع الكشف.",
    "Imported :imported, skipped as duplicates :skipped, failed :failed.": "تم استيراد :imported، وتخطي :skipped مكرر، وفشل :failed.",
    "Row :row: :reason": "الصف :row: :reason",
    "Uploads": "الملفات المرفوعة",
    "Uploaded": "تاريخ الرفع",
    "Uploaded by": "رفعه",
    "Imported": "المستورد",
    "Skipped": "المتخطى",
    "Failed": "الفاشل",
    "Delete this upload and its lines?": "حذف هذا الملف المرفوع وأسطره؟",
    "Upload deleted.": "تم حذف الملف المرفوع.",
    "Reconciliations": "التسويات",
    "End date": "تاريخ النهاية",
    "Finished": "منتهية",
    "Start a reconciliation": "بدء تسوية",
    "Statement end date": "تاريخ نهاية الكشف",
    "Statement closing balance": "الرصيد الختامي للكشف",
    "Statement opening balance": "الرصيد الافتتاحي للكشف",
    "Start": "بدء",
    "Reconciliation started.": "بدأت التسوية.",
    "Back": "رجوع",
    "Finished :date by :name": "انتهت في :date بواسطة :name",
    "Deposits in transit": "إيداعات في الطريق",
    "Outstanding payments": "مدفوعات معلّقة",
    "Adjusted balance": "الرصيد المعدَّل",
    "Ledger balance at the end date": "رصيد الدفتر في تاريخ النهاية",
    "Statement lines not matched": "أسطر الكشف غير المطابَقة",
    "Opening balance plus matched statement lines": "الرصيد الافتتاحي مع أسطر الكشف المطابَقة",
    "Finish": "إنهاء",
    "Discard": "تجاهل",
    "Discard this open reconciliation and its matches?": "تجاهل هذه التسوية المفتوحة ومطابقاتها؟",
    "Reopen": "إعادة فتح",
    "Download report": "تنزيل التقرير",
    "Unmatched statement lines": "أسطر الكشف التي لم تُطابَق",
    "Match": "مطابقة",
    "Suggested entries": "القيود المقترحة",
    "No suggestions.": "لا توجد اقتراحات.",
    "Find other entries (description or amount)": "ابحث عن قيود أخرى (الوصف أو المبلغ)",
    "Chosen: :total of :amount": "المختار: :total من :amount",
    "Not in the ledger yet": "غير مسجّل في الدفتر بعد",
    "Record as": "سجّل بصفة",
    "Other money account": "الحساب النقدي الآخر",
    "Record and match": "سجّل وطابق",
    "Matched statement lines": "أسطر الكشف المطابَقة",
    "Entries": "القيود",
    "Matched.": "تمت المطابقة.",
    "Recorded and matched.": "تم التسجيل والمطابقة.",
    "Match removed.": "أزيلت المطابقة.",
    "Reconciliation finished.": "انتهت التسوية.",
    "Reconciliation reopened.": "أعيد فتح التسوية.",
    "Reconciliation discarded.": "تم تجاهل التسوية.",
```

- [ ] **Step 9: Run the tests.**

Run: `php artisan test tests/Feature/Ledger/BankReconciliationPagesTest.php tests/Feature/Ledger/LedgerAccessTest.php`
Expected: PASS. LedgerAccessTest's sweep reads the two new components and views and finds every key; ar.json has no duplicate key.

- [ ] **Step 10: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Livewire/Ledger/BankReconciliations.php resources/views/livewire/ledger/bank-reconciliations.blade.php app/Livewire/Ledger/BankReconciliationShow.php resources/views/livewire/ledger/bank-reconciliation-show.blade.php routes/ledger.php resources/views/layouts/app/sidebar.blade.php tests/Feature/Ledger/BankReconciliationPagesTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
Bank reconciliation pages: upload with the saved mapping, uploads and reconciliations per account, start; match with suggestions, bank-only lines, finish, discard, reopen, and the report through SafeCells

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: The worked example, end to end, and the full ledger suite

**Files:**
- Test: `tests/Feature/Ledger/BankReconciliationWorkedExampleTest.php`

**Interfaces:**
- Consumes: everything above: `bankRecFixture()`, `bankRec()`, `bankStatement()`, `bankLine()`, `MatchBankLine`, `RecordBankOnlyLine`, `FinishBankReconciliation`, `ReopenBankReconciliation`, `ReverseMoneyEntry`, `RebuildLedger`, `BankEntries::suggestions()`, `BankReconciliationShow::reportRows()`, `postedLines()`, `ledgerBalances()`.
- Produces: nothing new. This task pins the plan's worked example; it adds no app code, so its test passes on the first run once Tasks 1–6 are in. A failure here means an earlier task drifted from the rulings.

- [ ] **Step 1: Write the test** `tests/Feature/Ledger/BankReconciliationWorkedExampleTest.php`:

```php
<?php

use App\Actions\Ledger\FinishBankReconciliation;
use App\Actions\Ledger\MatchBankLine;
use App\Actions\Ledger\RebuildLedger;
use App\Actions\Ledger\RecordBankOnlyLine;
use App\Actions\Ledger\ReopenBankReconciliation;
use App\Actions\Ledger\ReverseMoneyEntry;
use App\Ledger\BankEntries;
use App\Livewire\Ledger\BankReconciliationShow;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\LedgerSetting;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    LedgerSetting::query()->update(['start_date' => null, 'locked_until' => null, 'health' => null, 'health_checked_at' => null, 'rebuild_status' => null, 'rebuild_from' => null,
        'rebuild_to' => null, 'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null, 'rebuild_run' => null]);
});

test('the bank reconciliation worked example: Bank to 20/10, then to 25/10', function () {
    $f = bankRecFixture();
    $match = fn (BankReconciliation $rec, string $line, array $keys) => app(MatchBankLine::class)->handle($f['finance'], $rec, (string) bankLine($line)->id, $keys);
    $figures = fn (BankReconciliation $rec) => array_slice(BankReconciliationShow::reportRows($rec->fresh()), 1, 9);

    // Reconciliation 1, before matching.
    $first = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    expect($figures($first))->toBe([
        ['Statement opening balance', '1000.000'],
        ['Statement closing balance', '1198.700'],
        ['Deposits in transit', '650.000'],
        ['Outstanding payments', '340.000'],
        ['Adjusted balance', '1508.700'],
        ['Ledger balance at the end date', '1310.000'],
        ['Difference', '198.700'],
        ['Statement lines not matched', '4'],
        ['Opening balance plus matched statement lines', '1000.000'],
    ])->and(fn () => app(FinishBankReconciliation::class)->handle($f['finance'], $first))->toThrow(ValidationException::class)
        ->and(array_map(fn (stdClass $e) => $e->entry_type.':'.$e->entry_id, BankEntries::suggestions($first, bankLine('TRANSFER '.$f['headLease']->number))))
        ->toBe(['disbursement:'.$f['headLease']->id]);

    // Matching, two bank-only lines, finish.
    $match($first, 'TRANSFER '.$f['headLease']->number, ['disbursement:'.$f['headLease']->id]);
    $match($first, 'CASH DEPOSIT DEP-1 DEP-2', ['cash_movement:'.$f['dep1']->id, 'cash_movement:'.$f['dep2']->id]);
    $charge = app(RecordBankOnlyLine::class)->handle($f['finance'], $first, (string) bankLine('BANK CHARGES OCT')->id, 'bank_charge', null);
    $interest = app(RecordBankOnlyLine::class)->handle($f['finance'], $first, (string) bankLine('CREDIT INTEREST')->id, 'bank_interest', null);
    app(FinishBankReconciliation::class)->handle($f['finance'], $first);

    $report = [
        ['Bank reconciliation', '1010 Bank', '20/10/2026', 'Finished'],
        ['Statement opening balance', '1000.000'],
        ['Statement closing balance', '1198.700'],
        ['Deposits in transit', '150.000'],
        ['Outstanding payments', '40.000'],
        ['Adjusted balance', '1308.700'],
        ['Ledger balance at the end date', '1308.700'],
        ['Difference', '0.000'],
        ['Statement lines not matched', '0'],
        ['Opening balance plus matched statement lines', '1198.700'],
        ['Deposits in transit'],
        ['Date', 'Source', 'Description', 'Amount'],
        ['19/10/2026', 'Cash and bank movement', 'DEP-3', '150.000'],
        ['Outstanding payments'],
        ['Date', 'Source', 'Description', 'Amount'],
        ['17/10/2026', 'Manual journal', 'Office chairs CHQ 7781', '-40.000'],
    ];
    expect(BankReconciliationShow::reportRows($first->fresh()))->toBe($report)
        ->and(postedLines('cash_movement', $charge->id))->toBe(['5180 2.500 0.000', '1010 0.000 2.500'])
        ->and(postedLines('cash_movement', $interest->id))->toBe(['1010 1.200 0.000', '4300 0.000 1.200'])
        ->and(ledgerBalances('2026-10-20')[1010])->toBe('1308.700')
        ->and(BankMatch::query()->count())->toBe(5)
        ->and(fn () => app(ReverseMoneyEntry::class)->handle($f['finance'], $f['dep1'], '2026-10-20', 'Typo'))
        ->toThrow(ValidationException::class, 'This entry is reconciled with the bank statement up to 20/10/2026, so it cannot be reversed on or before that day.');

    // A rebuild re-posts every automatic journal under the same source keys.
    app(RebuildLedger::class)->handle($f['finance'], '2026-10', '2026-10');
    expect(BankReconciliationShow::reportRows($first->fresh()))->toBe($report)->and(BankMatch::query()->count())->toBe(5);

    // Statement 2 and reconciliation 2, chained from reconciliation 1.
    $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', 'Asia/Bahrain'));
    $statement = bankStatement($f['finance'], '1010', [['20/10/2026', 'CREDIT INTEREST', '', '1.200'], ['21/10/2026', 'CASH DEPOSIT DEP-3', '', '150.000'],
        ['22/10/2026', 'CHQ 7781', '', '-40.000']]);
    $next = bankRec($f['finance'], '2026-10-25', '1308.700');
    $match($next, 'CASH DEPOSIT DEP-3', ['cash_movement:'.$f['dep3']->id]);
    $match($next, 'CHQ 7781', ['manual:'.$f['manual']->id]);
    app(FinishBankReconciliation::class)->handle($f['finance'], $next);

    expect([$statement['upload']->imported, $statement['upload']->skipped])->toBe([2, 1])
        ->and($next->fresh()->opening_balance)->toBe('1198.700')
        ->and($figures($next))->toBe([
            ['Statement opening balance', '1198.700'],
            ['Statement closing balance', '1308.700'],
            ['Deposits in transit', '0.000'],
            ['Outstanding payments', '0.000'],
            ['Adjusted balance', '1308.700'],
            ['Ledger balance at the end date', '1308.700'],
            ['Difference', '0.000'],
            ['Statement lines not matched', '0'],
            ['Opening balance plus matched statement lines', '1308.700'],
        ])
        ->and(BankReconciliationShow::reportRows($first->fresh()))->toBe($report) // reconciliation 1 reads the same
        ->and(fn () => app(ReopenBankReconciliation::class)->handle($f['admin'], $first))
        ->toThrow(ValidationException::class, 'Only the latest reconciliation of an account can be reopened.')
        ->and(fn () => app(ReopenBankReconciliation::class)->handle($f['finance'], $next))->toThrow(AuthorizationException::class);

    app(ReopenBankReconciliation::class)->handle($f['admin'], $next);
    expect($next->fresh()->status)->toBe('open')
        ->and(BankMatch::query()->where('bank_reconciliation_id', $next->id)->count())->toBe(2);
});
```

- [ ] **Step 2: Run it.**

Run: `php artisan test tests/Feature/Ledger/BankReconciliationWorkedExampleTest.php`
Expected: PASS.

- [ ] **Step 3: Run the ledger suite and every suite the guard touched, one command at a time.**

Run: `php artisan test tests/Feature/Ledger`
Expected: PASS (the money screens worked example, ledger health, lock and rebuild are unchanged; LedgerAccessTest's sweep finds every new key in ar.json, with no duplicate).

Run: `php artisan test tests/Feature/Payments tests/Feature/Disbursements tests/Feature/Expenses tests/Feature/Cheques tests/Feature/Approvals tests/Feature/Billing tests/Feature/Integrity`
Expected: PASS.

- [ ] **Step 4: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add tests/Feature/Ledger/BankReconciliationWorkedExampleTest.php
git commit -m "$(cat <<'EOF'
Bank reconciliation worked example: Bank to 20/10 (difference 198.700, then 0.000 with DEP-3 in transit and the manual cheque outstanding), protection, rebuild, statement 2 chained to 25/10, reopen

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-review

**1. Spec coverage.**

| Spec | Where |
|---|---|
| §1 purpose; out of scope (no feeds, PDF/MT940, cross-account matching, unconfirmed matching) | Global Constraints; ruling 3 (Finance ticks every match) |
| §2 page, money account, CSV/XLSX | Task 6 (`BankReconciliations`), Task 2 |
| §2 column mapping per account, first asks, reused and editable; formats; debit + credit or signed; optional reference and balance; header row | Ruling 7; Task 1 (`bank_mappings`); Task 2 (`mapping()`); Task 6 (`loadMapping()`, first page test) |
| §2 statement line fields, signed BHD 3 dp | Task 1 (`bank_statement_lines`); Task 2 (`parse()`, `fils()`) |
| §2 duplicates skipped; counts; failed rows with reasons | Ruling 7 (with `occurrence`); Task 2 tests 3–4; Task 6 result text |
| §2 lines immutable; upload deleted only while unmatched | Task 1 trigger; Task 2 `DeleteBankUpload`, test 7; Task 6 delete button |
| §3 start: account, end date, closing; opening chained, first asks | Ruling 4; Task 3 `StartBankReconciliation`; Task 4 test 3; Task 7 |
| §3 one open per account; end dates forward | Task 1 `bank_reconciliations_one_open`; Task 3; Task 4 test 3 |
| §3 suggestions: direction, amount, ±7 days, reference first | Ruling 2; Task 3 `suggestions()`, test 2 |
| §3 many-to-one; an entry once | Ruling 3; Task 1 `bank_matches_entry_unique`; Task 3 test 3 |
| §3 bank-only line → existing action, matched | Ruling 8; Task 4 `RecordBankOnlyLine`, test 1 |
| §3 matches removed while open | Task 3 `UnmatchBankLine`; Task 1 delete guard |
| §4 figures: closing, in transit, outstanding, adjusted, ledger, difference; lines matched; opening + Σ matched = closing | Ruling 4; Task 4 `figures()`, `FinishBankReconciliation`, test 2; Task 6 `figureRows()` |
| §4 finish only at 0.000 and all lines matched; report to Excel through SafeCells | Task 4; Task 6 `reportFile()`, report test |
| §4 protection: no reversal on or before the end date; later is fine | Ruling 5; Task 5 |
| §4 matches reference the source, survive a rebuild | Rulings 1, 9; Task 4 test 5; Task 7 |
| §4 Admin reopens the latest, audited; entries unreconciled | Ruling 6; Task 4 `ReopenBankReconciliation`, test 4 |
| §5 tables, unique keys, triggers, EXPECTED_TRIGGERS | Ruling 11; Task 1 (77) |
| §6 permissions re-checked, audited; ≤ 5 MB, ≤ 5000 rows; data never formulas; tampered input → message; Fils; dd/mm/yyyy; EN/AR | Every Action's first lines; Task 2 test 5–6; Task 6 tamper and report tests; ar.json in Tasks 1–6; LedgerAccessTest sweep |
| §7 testing list | Tasks 1–7 tests, one by one |

No gaps.

**2. Placeholder scan.** Searched the plan for "TBD", "TODO", "implement later", "similar to", "appropriate", "handle edge cases" and "as above": none. Every code step has the code; every test step has the test.

**3. Type and name consistency.**
- `BankEntries::key()` gives `type:id`; `parseKeys()` accepts `[a-z_]{1,30}:\d{1,18}`; the views send `$e['key']` built by `key()`. Source types (`cash_movement`, `vat_return`, `payment_reversal`, …) and `manual` fit the pattern.
- `BankEntries::query()` selects `journal, kind, source_type, source_id, date, description, entry_type, entry_id, amount`; `suggestions()` adds `hit`; `BankReconciliationShow::rows()` reads exactly those.
- `Figures` keys (`closing, in_transit, outstanding, adjusted, ledger, difference, opening, movement, covered, unmatched_lines, changed, in, out`) are produced in Task 4 and read in Task 4's `FinishBankReconciliation`, Task 6's `figureRows()`/`reportRows()`/`render()`, and the Task 4 tests (in that order for `array_intersect_key`).
- Error keys: `account`/`file`/mapping keys (upload), `upload` (delete), `ledger_account_id`/`end_date`/`closing_balance`/`opening_balance` (start), `picked`/`line` (match), `line` (unmatch, bank-only), `kind` and RecordCashMovement's keys (bank-only), `finish`, `reopen`, `discard`. Each is shown on its page.
- `BankReconciliation::lockOpen(int, string)` and `unmatchedLine(mixed)` (Task 1) are used by Tasks 3–4 with the same signatures; `status` is always the string `open` or `finished`.
- Pest helpers: `bankMapping`, `bankCsv`, `bankStatement(User, string $code, array, array = [])`, `bankLine(string)` (Task 2); `bankRecFixture()`, `bankRec(User, string, string, ?string = null)` (Task 3); the fixture returns `bank`/`cash` as ints, `dep1`–`dep3` as `CashMovement`, `manual` as `Journal`.
- `EXPECTED_TRIGGERS` is 77 in IntegrityCheck, BankStorageTest, LedgerStorageTest, MoneyScreensStorageTest and VendorsMigrationTest.
- Every ar.json key added in Tasks 1–6 was checked absent from `lang/ar.json`, and no key is added by two tasks. Existing keys reused: "Choose an active money account.", "Choose two different money accounts.", "Wait for the rebuild to finish.", "Money account", "Choose…", "Amounts", "File", "Delete", "Opening balance", "Closing balance", "Status", "Open", "Date", "Description", "Reference", "Amount", "Source", "Difference", "Cancel", "Remove".

**4. Worked example check** (the numbers Task 7 asserts, recomputed):
- Ledger, Bank at 20/10: opening 1000.000 − 300.000 + 200.000 + 300.000 − 40.000 + 150.000 = 1310.000; after the two bank-only lines − 2.500 + 1.200 = 1308.700.
- Statement 1 closing: 1000.000 − 300.000 + 500.000 − 2.500 + 1.200 = 1198.700.
- Before matching: in transit 200 + 300 + 150 = 650.000; outstanding 300 + 40 = 340.000; adjusted 1198.700 + 650.000 − 340.000 = 1508.700; difference 1508.700 − 1310.000 = 198.700.
- After: in transit 150.000; outstanding 40.000; adjusted 1198.700 + 150.000 − 40.000 = 1308.700 = ledger; difference 0.000; opening + matched lines 1000.000 − 300.000 + 500.000 − 2.500 + 1.200 = 1198.700 = closing.
- Reconciliation 2: opening 1198.700; matched lines + 150.000 − 40.000 → 1308.700 = closing; ledger at 25/10 1308.700 (nothing new after 20/10); in transit 0, outstanding 0, difference 0.000.
- Reversal guard test (Task 5): statement closing 1198.700 − 40 + 50 + 30 − 42 = 1196.700; ledger 1310.000 + 50 + 30 − 42 − 2.500 + 1.200 = 1346.700; in transit DEP-3 150.000; adjusted 1196.700 + 150.000 = 1346.700; difference 0.000.

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-10-31-bank-reconciliation.md`. Two execution options:

1. **Subagent-Driven (recommended):** a fresh subagent per task, review between tasks (superpowers:subagent-driven-development).
2. **Inline Execution:** execute the tasks in one session with checkpoints (superpowers:executing-plans).
