# General Ledger Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the company's double-entry books inside the app, derived from the money records it already keeps, so Finance can read a trial balance, balance sheet, profit and loss and general ledger, post and reverse manual journals, lock closed months and rebuild open ones, and Finance and Admin can see a nightly "Ledger health" check, without staff changing how they record anything.

**Architecture:** Five new tables (`ledger_accounts`, `ledger_settings`, `ledger_rules`, `journals`, `journal_lines`) with the default chart and rules inserted by the migration. `App\Ledger\Sources` defines every money record the ledger reads as a *source type*: one SQL eligibility query (shared by live posting, catch-up, rebuild and Ledger health) and one line builder. `App\Ledger\Poster` posts a source **after the money record's transaction commits**, in its own transaction under one lock on the settings row; model `saved` events on the money models queue it, a failure is reported and never undoes the money record, and the nightly `rms:ledger:nightly` catches up and stores Ledger health. Manual and opening journals are written by Actions; six triggers keep manual journals and everything on or before "Locked up to" unchanged. Reports are `ReportPage` pages (Excel through `SafeCells`), plus a "Journal lines" subject in the report builder.

**Tech Stack:** Laravel 13, PHP 8.5, MySQL, Livewire 4 + Flux (free), Pest, spatie/laravel-permission, spatie/laravel-activitylog (`App\Audit\Audit`), `App\Support\Fils`, `App\Support\SafeCells` (through `ReportPage::export()`), `App\Livewire\Reports\Concerns\ReportPage`, `App\Reports\Builder\*`, `App\Billing\OwnerLedger`, `<x-date-input>`.

**Spec:** `docs/superpowers/specs/2026-10-24-general-ledger-design.md`. Read it first; this plan argues from it. Where this plan and the spec differ, the ruling says why.

## Global Constraints

- "The books are the **company's**; money collected for owners is a liability (**Owner payable**). The ledger starts at an Admin-chosen **go-live date** with **opening balances**. Each payment method maps to **one** ledger account" (spec §1). Out of scope (spec §1): bank reconciliation, export to accounting software, budgets, several bank accounts per method, trust accounting, any change to how staff record money.
- "Every journal must balance before it is saved; a manual journal needs ≥2 lines and a description, is never edited, and is corrected by reversal" (spec §4).
- "Permissions: Admin: accounts, rules, start date, unlock. Finance: view ledger and reports, manual journals, lock months, rebuild. Others: no access" (spec §3).
- Actions are the only write path, and they re-check permission.
- Existing money tables are read-only for the ledger. `EXPECTED_TRIGGERS` changes only by the new ledger triggers; the new number is **67** (61 + 6).
- Money is exact decimals through `Fils`, never floats. Timezone is Asia/Bahrain. Dates show as dd/mm/yyyy.
- All strings go through `__()` with entries in ar.json, inserted after `"Credit refund reversal": "عكس استرداد رصيد",` with no duplicate keys. Every key listed below was checked as absent from `lang/ar.json` and from the other tasks.
- Tampered Livewire input gives a message or a 404, never a 500. Public non-static Livewire methods are internal-safe. Ids use `#[Locked]`.
- Exports go through SafeCells.
- Tests run serially on rms_test, never two at once.
- Gates: Pint `--dirty`; `phpstan clear-result-cache`, then phpstan `--memory-limit=2G` with 0 errors; `npm run build`.
- The PHP binary is /c/Users/ababy/.config/herd/bin/php85/php.exe (written `php` below).
- Never run migrate:fresh on the dev DB `rms`. Never change MySQL global settings. The dev DB is migrated with `php artisan migrate --database=migrator --force`.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never commit `.superpowers/`.
- No visual polish. Pages are plain Flux, consistent with the existing admin and report pages; a design pass comes later.
- Blade: do not mix inline `@php(...)` with `@php … @endphp` blocks in one file.

## Rulings made while planning

1. **Hooking posting into the money actions: model events, posted after commit.**
   - The spec rejects "posting inline in each money action" (§2) and wants "a failure rolls back that source's journal only and is reported in Ledger health" (§8). Posting inside the money transaction would roll the money record back with a failed journal, which §8 forbids. Explicit calls or a domain event in each action would touch about 20 actions and approval handlers (`IssueInvoice`, `IssueCreditNote`, `PostPayment`, `ApplyToInvoices`, `ReverseAllocations`, `PaymentReversal`, `RecordExpense`, `ReverseExpense`, `MarkDisbursementPaid`, `PaymentOutReversal`, `OwnerStatementFinalisation`, `GenerateOwnerPayables`, `RescheduleOwnerPayables`, …) and miss the next one someone adds.
   - So `Poster::observe()` (called once in `AppServiceProvider::boot()`) registers a `saved` listener on each money model in `Poster::WATCH`: `Invoice`, `Payment`, `PaymentAllocation`, `Expense`, `Disbursement`, `OwnerCharge`, `OwnerPayable`. The listener calls `Poster::queue($types, $id)`, which registers `DB::afterCommit()`. After the money transaction commits, `Poster::post($type, $id)` runs in its own transaction. A throw is `report()`ed and swallowed: the money record stands.
   - Every app write to these tables goes through Eloquent (checked: no `DB::table(...)->insert/update` on them under `app/`), so the events see every change. A write that bypasses Eloquent (a test fixture, a manual fix) is caught by the nightly catch-up and counted in Ledger health until then.
   - Posting is idempotent: `journals` has a unique key on `(source_type, source_id)`, and `post()` re-checks eligibility (the record exists, is in the right status, its own date is from the start date to today, and it has no journal) under the settings-row lock. Reversals are separate source types (`payment_reversal`, …), so one record never needs two journals under one key.
   - The child rows a journal needs (payment tenders, deposit movements of a settlement or refund) are written in the same transaction as the parent, so after commit they are all there.
2. **Managed or leased on the invoice date.** `IssueInvoice` already stamps each invoice line's `owner_contract_id` at issue: the contract `effectiveOn` the line's attribution date (period start, the agreement unit's start for a deposit, else the due date), among contracts with status active, ended or terminated. The ledger reads that stamp and the contract's `type` and `deposits_held_by`. It never re-resolves.
   - A credit note line maps exactly as the line it credits (`credited_line_id`), so its opposite always lands on the same accounts.
   - **No contract on the line** (the unit had no owner contract on that date, or the line has no unit, as a tenant recharge or settlement deduction) falls back to company income: Rent income for `rent`, Other charges income for every other charge type, Tenant deposits held for a deposit.
   - Ledger health surfaces the fallback as the row "Invoice lines with no owner contract": issued, non-credit-note lines dated from the start date with a `unit_id` but no `owner_contract_id` (a unit that should have had a contract). Lines with no unit by design (recharges) are not counted.
3. **The posting map.** Lines are signed (+ debit, − credit). The idempotency key is always `(source_type, record id)`. Account names are rule keys (ruling 10).

   | # | Event | source_type | Date | Debit | Credit | Reversal path |
   |---|---|---|---|---|---|---|
   | 1 | Rent or other charge line issued, contract **managed** | `invoice` (one journal per invoice) | `invoices.issue_date` | receivables (line total) | owner_payable (net); vat_payable (tax) | credit note, row 6 |
   | 2 | Same, contract **leased** or none | `invoice` | issue_date | receivables (total) | rent_income for `rent`, other_income otherwise (net); vat_payable (tax) | row 6 |
   | 3 | Recharge invoice (expense charged to tenant: line has no unit, so no contract) | `invoice` | issue_date | receivables | other_income (net); vat_payable (tax) | row 6 |
   | 4 | Deposit line | `invoice` | issue_date | receivables | deposits_held; owner_payable when the contract is managed and `deposits_held_by = owner` | row 6 |
   | 5 | `opening_balance` line issued after the start date | `invoice` | issue_date | receivables | owner_payable when managed, else opening_equity | row 6 |
   | 6 | Credit note (`invoices.type = credit_note`) | `invoice` (its own id) | its issue_date | exact opposite of rows 1–5, line by line, mapped as the credited line | | is itself the reversal |
   | 7 | Receipt, one method | `payment` | `payments.received_on` | `method.<method>` (cash, bank_transfer, card; a cleared cheque is `method.cheque` = Bank) | tenant_credit | row 11 |
   | 8 | Receipt, `split` | `payment` | received_on | one `method.<m>` line per `payment_tenders` row | tenant_credit | row 11 |
   | 9 | Receipt, `deposit_applied` (deposit settlement) | `payment` | received_on | per `applied` deposit movement of the settlement whose `payment_id` is this payment: deposits_held, or owner_payable when owner-held | tenant_credit | row 11 |
   | 10 | Payment applied to a line (positive allocation) or taken back (negative allocation) | `allocation` (one per `payment_allocations` row) | `received_on` when the allocation was made the day the receipt was recorded (`DATE(a.posted_at) = DATE(p.posted_at)`, positive rows only), else `DATE(a.posted_at)` | tenant_credit | receivables (a negative row swaps sides) | its own negative row |
   | 11 | Receipt reversed (approval; also a cheque bounced after clearing) | `payment_reversal` | `DATE(payments.reversed_at)` | exact opposite of rows 7–9; its cut allocations post by row 10 | | — |
   | 12 | Post-dated cheque held, deposited, returned, cancelled, or bounced before clearing | — | — | nothing: no payment exists until `ClearCheque` | | — |
   | 13 | Expense charged to the company | `expense` | `expenses.expense_date` | `category.<category>` (total) | vendor_payable (total) | `expense_reversal`, `DATE(reversed_at)` |
   | 14 | Expense charged to the owner | `expense` | expense_date | owner_payable | vendor_payable | same |
   | 15 | Expense charged to the tenant | `expense` | expense_date | `category.<category>` | vendor_payable; the recharge invoice posts by row 3 | same |
   | 16 | Owner remittance paid | `disbursement` | `disbursements.paid_on` | owner_payable | `method.<method>` | `disbursement_reversal`, `DATE(reversed_at)` |
   | 17 | Head lease paid | `disbursement` | paid_on | owner_payable | `method.<method>` | same |
   | 18 | Credit refund | `disbursement` | paid_on | tenant_credit | `method.<method>` | same |
   | 19 | Deposit refund | `disbursement` | paid_on | per `refunded` movement with `source_type = disbursement`: deposits_held, or owner_payable when owner-held | `method.<method>` | same (built from the original movements) |
   | 20 | Other payment out (approved) | `disbursement` | paid_on | other_payments_out | `method.<method>` | same |
   | 21 | Management fee (owner charge) | `owner_charge` | `DATE(owner_charges.posted_at)` | owner_payable (net + VAT) | fee_income (net); vat_payable (fee VAT) | none exists in the app |
   | 22 | Owner opening balance after the start date | `owner_charge` | DATE(posted_at) | opening_equity | owner_payable (a negative amount swaps sides) | none |
   | 23 | Leased rent due to the owner | `owner_payable` | `owner_payables.due_date`, posted once due ≤ today | lease_rent | owner_payable | cancelled on or after its due date: `owner_payable_cancel`, `DATE(updated_at)`; cancelled before it: never posts |
   | 24 | Deposit transfer on renewal | — | — | nothing: `transfer_in` copies the `transfer_out` contract stamp, so the holder and account never change | | — |
   | 25 | Manual journal | — (kind `manual`) | entered date | Finance's lines | | a manual journal with `reverses_journal_id` |
   | 26 | Opening journal | — (kind `opening`) | the start date | Admin's balances; the difference to opening_equity | | replaced while nothing is locked |

   - **Split receipts** (row 8), **deposits applied** (row 9), **deposits held by the owner** (rows 4, 9, 19), **recharges** (row 3), **VAT on fees** (row 21) and **owner opening balances** (row 22) are as shown.
   - **Every reversal path:** credit notes (6), payment reversal including bounced-after-clearing cheques (11) and their negative allocations (10), expense reversal (13–15), payment-out reversal of every purpose (16–20, deposit refunds from their original movements), payable cancellation (23), manual journal reversal (25). Issued invoices cannot be cancelled (`invoices_guard`), management fees and opening balance charges have no reversal in the app, and deposit transfers post nothing.
   - **Eligibility** is the SQL in `Sources::TYPES`: invoices `status = issued`; payments all; `payment_reversal` `status = reversed`; disbursements `status IN (paid, reversed)`; `disbursement_reversal` and `expense_reversal` `status = reversed`; `owner_payable` `status <> cancelled OR DATE(updated_at) >= due_date`; `owner_payable_cancel` `status = cancelled AND DATE(updated_at) >= due_date`. Once cancelled a payable never changes again (the replacement link is set in the same transaction), so `updated_at` is its cancellation time.
4. **Rebuild mechanics.**
   - **Which records belong to a range:** a rebuild of `[from, to]` deletes the automatic journals dated in it, then runs the catch-up. The catch-up posts every eligible source that has no journal. Those are exactly the deleted ones, each re-posted on its own date, or on the first open day if its own date is locked (a late posting re-posts late again). Any genuine gap elsewhere is posted too.
   - **The range:** Finance or Admin pick months (`Y-m` to `Y-m`). `RebuildLedger` clamps to `[max(first day, start date, locked_until + 1), min(last day, today)]`; an empty range is refused.
   - **Chunks:** `RebuildLedgerJob` deletes 500 automatic journals (their lines first) per transaction, each taking the settings-row lock. It then counts `Poster::pendingCount()` into `rebuild_total` and calls `Poster::catchUp()`. That walks each source type by id, 200 ids per query, and writes `rebuild_done` after each chunk.
   - **Progress:** `ledger_settings.rebuild_status` is `queued` → `running` → `done` or `failed` (with `rebuild_message`), plus `rebuild_from`, `rebuild_to`, `rebuild_done`, `rebuild_total`. The Ledger page polls every 2 s while queued or running. One rebuild at a time: a second request, a lock, a start-date move or new opening balances are refused while one runs.
   - **Concurrency:** every posting, every deletion chunk, every lock change and the start date take `SELECT … FOR UPDATE` on the one settings row. A live posting during a rebuild is just a source the catch-up later finds already posted (unique key plus the re-check). If a lock lands mid-rebuild, the trigger refuses the next deletion chunk and the job ends `failed` with the trigger's message.
   - Manual and opening journals are never deleted by a rebuild. The job deletes `kind = auto` only, and the trigger refuses manual ones anyway. A rebuild is audited (`ledger.rebuild_requested`, `ledger.rebuilt`).
5. **Locking and late posting.**
   - `LockLedger` (Finance) only moves "Locked up to" forward, to a date from the start date to today. `UnlockLedger` (Admin) moves it back to a date on or after the start date, or clears it. Both are audited.
   - A source whose own date is on or before `locked_until` posts on `locked_until + 1` with `late_posting = true` (`Poster::post`).
   - **Triggers (6, so `EXPECTED_TRIGGERS` = 67):**
     - `journals_no_update` and `journal_lines_no_update`: journals are write-once; corrections are reversals.
     - `journals_insert_guard` and `journal_lines_insert_guard`: nothing is dated on or before `MAX(ledger_settings.locked_until)`.
     - `journals_delete_guard` and `journal_lines_delete_guard`: a manual journal is never deleted, and nothing on or before the lock is deleted. That covers the opening journal, which is dated the start date, and `locked_until >= start_date` is a CHECK.
6. **Opening balances and the start date.**
   - `SetLedgerStart` (Admin) is refused while `locked_until` is set, while a rebuild runs, or when a manual journal is dated before the new date. It saves the date and writes the opening journal again at the new date (journals never update). It then queues a rebuild of `[min(old, new), today]`. Moving later deletes what fell before the new start; moving earlier posts the newly eligible records. Before a start date exists nothing posts, and `Poster::post()` returns before taking any lock.
   - `SaveOpeningBalances` (Admin) takes one debit or credit per active account. It replaces the opening journal (kind `opening`, dated the start date) and adds the difference to opening_equity, so it always balances. It is refused while anything is locked.
   - Nothing before the start date ever posts. A reversal dated after the start of a record dated before it does post, because the opening balances already contain the original.
7. **Dimensions on lines.** `journal_lines` carries nullable `customer_id`, `owner_id`, `building_id`, `unit_id`. The builders fill them:
   - invoices and allocations: the tenant; the contract's owner; the unit (the line's, else its agreement unit's) and its building;
   - receipts and credit refunds: the tenant;
   - expenses: building, unit, and the owner for owner charges;
   - owner charges, payables, remittances and head leases: the owner and the contract's building;
   - deposit lines: the tenant, unit, building, and the owner of the stamped contract.
   Manual and opening lines carry none (a memo only). The ledger pages total by account and ignore dimensions. The "Journal lines" report-builder subject exposes tenant, owner, building and unit as columns, filters and groupings, and its building filter is `journal_lines.building_id`.
8. **Ledger health queries** (`App\Ledger\LedgerHealth::run()`, tolerance 0, figures in fils). Each row compares the ledger with the app; healthy means equal.
   - **Trial balance:** Σ debit − Σ credit over all lines, against 0.
   - **Records not posted:** `Poster::pendingCount()` (eligible sources with no journal), against 0.
   - **Tenant receivables:** the receivables account's Dr − Cr, against Σ `invoices.balance` of issued non-credit-note invoices (`Customer::outstandingFils()` summed).
   - **Tenant credit:** the tenant_credit account's Cr − Dr, against Σ confirmed payment amounts − Σ their allocations − Σ paid credit refunds on confirmed payments (`CustomerCredit::filsFor()` summed).
   - **Tenant deposits held:** the deposits_held account's Cr − Dr, against Σ `deposit_movements.amount` not held by an owner (`NOT (c.type <=> 'managed' AND c.deposits_held_by <=> 'owner')`) + the unpaid balance (total − allocated − credited) of issued deposit lines not held by an owner. The ledger recognises the deposit when it is invoiced (spec §4); the app's deposits-held figure counts it when paid.
   - **Owner payable:** the owner_payable account's Cr − Dr, against the sum of:
     - Σ `OwnerLedger::balance()` over managed contracts (cash basis: collections net of VAT, owner-held deposits, fees, owner expenses, remittances);
     - billed net − credited net − collected net (allocation amount − allocation tax) of managed non-deposit lines, because the ledger accrues at issue (spec §4);
     - the unpaid balance of owner-held deposit lines;
     - Σ payables due by today and not cancelled, − Σ paid head-lease payouts.
   - **Vendor payable:** the vendor_payable account's Cr − Dr on journals from `expense` and `expense_reversal` only, against Σ expense totals dated from the start date to today − Σ totals of those reversed in that window. Ruling 11 explains why only those.
   - **Invoice lines with no owner contract:** a count (ruling 2), against 0.
   - `LedgerHealth::store()` writes the rows to `ledger_settings.health` with `health_checked_at`. It runs nightly after the catch-up, and on "Check now". The "Ledger health" card on the Ledger page shows each row and its difference, red when not 0.
9. **Reports.** Five `ReportPage` pages, so each has the filters, Excel through `SafeCells`, an audit on export, and the headless package entry points:
   - Trial balance (single date);
   - Balance sheet (single date: equity accounts, then "Earlier years' profit" and "Current-year profit");
   - Profit and loss (range);
   - General ledger (range plus `account`; an opening row, each line linking to its source, a running Dr − Cr balance, and a closing row);
   - Journal list (range plus `source` and `account`; one row per line, so "expands to lines").
   They are not added to `ReadyMade::REPORTS` (its count of 16 is tested). The report builder gets the 13th subject "Journal lines", so saved reports and packages reach the ledger. Click-through: invoice to the invoice, receipt, reversal and allocation to the receipt, expense to the expense, payment out to the payment out, payable to the head-lease list, anything else to the journal page.

   **Worked example** (`ledgerFixture()`, Task 4; VAT 10%, start 01/10/2026, as at 20/10/2026), the figures the tests assert:
   - **Trial balance:**
     - Cash on hand 840.000 Dr;
     - Bank 300.000 Cr;
     - Card clearing 300.000 Dr;
     - Tenant deposits held 400.000 Cr;
     - Owner payable 348.000 Cr;
     - Vendor payable 80.000 Cr;
     - VAT payable 42.000 Cr;
     - Rent income 300.000 Cr;
     - Management fee income 20.000 Cr;
     - Lease rent to owners 300.000 Dr;
     - Maintenance and repairs 50.000 Dr;
     - totals 1490.000 = 1490.000.
   - **Profit and loss** 01/10–20/10: income 320.000, expenses 350.000, profit −30.000.
   - **Balance sheet:** assets 840.000; liabilities 870.000; current-year profit −30.000; liabilities and equity 840.000.
   - **General ledger, Owner payable:** opening 0.000; then −400.000, −700.000, −670.000, −370.000, −348.000.
   - **Health:** every row equal (deposits 400.000, owner payable 348.000, vendor payable 80.000).
10. **Default chart and seeding.** The migration inserts the chart and the rules, not a seeder. RefreshDatabase's `migrate:fresh` (tests), the dev DB (`migrate --database=migrator`) and production all get the same rows, and `TestCase::$exceptTables` keeps them through the Concurrency suite's truncation.

    | Code | Account | Type | system_key | Rule keys pointing at it |
    |---|---|---|---|---|
    | 1000 | Cash on hand | asset | cash | method.cash |
    | 1010 | Bank | asset | bank | method.bank_transfer, method.cheque |
    | 1020 | Card clearing | asset | card_clearing | method.card |
    | 1100 | Tenant receivables | asset | receivables | receivables |
    | 2000 | Tenant deposits held | liability | deposits_held | deposits_held |
    | 2100 | Owner payable | liability | owner_payable | owner_payable |
    | 2200 | Vendor payable | liability | vendor_payable | vendor_payable |
    | 2300 | Tenant credit | liability | tenant_credit | tenant_credit |
    | 2400 | VAT payable | liability | vat_payable | vat_payable |
    | 3000 | Opening balance equity | equity | opening_equity | opening_equity |
    | 3100 | Retained earnings | equity | retained_earnings | — (for year-end manual journals) |
    | 4000 | Rent income | income | rent_income | rent_income |
    | 4100 | Other charges income | income | other_income | other_income |
    | 4200 | Management fee income | income | fee_income | fee_income |
    | 5000 | Lease rent to owners | expense | lease_rent | lease_rent |
    | 5100 | Maintenance and repairs | expense | expense_maintenance | category.maintenance |
    | 5110 | Utilities | expense | expense_utilities | category.utilities |
    | 5120 | Cleaning | expense | expense_cleaning | category.cleaning |
    | 5130 | Security | expense | expense_security | category.security |
    | 5140 | Insurance | expense | expense_insurance | category.insurance |
    | 5150 | Government fees | expense | expense_government | category.government |
    | 5160 | Legal fees | expense | expense_legal | category.legal |
    | 5170 | Commissions | expense | expense_commission | category.commission |
    | 5190 | Other expenses | expense | expense_other | category.other, other_payments_out |

    24 accounts and 25 rules: 12 event keys, 4 `method.*` and 9 `category.*`. `deposit_applied` and `split` never reach a method rule (rows 8–9).
11. **Vendor payments have no source.** Expenses have no paid state, no vendor link and no payment record in the app, so the spec's "Vendor paid" has nothing to post from. Finance records vendor payments as manual journals (Dr Vendor payable, Cr Bank). The vendor health row therefore checks that expenses were posted completely, not what is unpaid. A vendor-payment feature would add a source type here.
12. **Permissions.** Three new permissions, granted by `RolesAndPermissionsSeeder` and, on an installed system, by the migration (additively, like the maintenance permissions):
    - `ledger.view` (Finance, Admin): pages, reports, health, the builder subject;
    - `ledger.manage` (Finance): manual journals, lock, rebuild;
    - `ledger.admin` (Admin): accounts, rules, start date, opening balances, unlock, rebuild.
    Existing permissions do not fit: Management holds `finance.view`, and Vendor support holds nearly everything. Vendor support is denied all three. All three join `twoFactorRequired()`. The ledger is company-wide, so `ledger.view` sees every building.
13. **Stored text.** A journal's `description` is the source's own reference: invoice, receipt or payment-out number; "receipt → invoice" for an allocation; the expense description; the contract number (and period, for a payable). For a manual journal it is what Finance typed, and for the opening journal it is "Opening balances". The kind of source shows on screen through `Sources::label()`, which is translated.
14. **Expense VAT** is posted gross to the expense or owner account. The spec has no input-VAT account, and expense VAT is not reclaimed in the app today.

## File Structure

```
database/migrations/2026_12_26_000100_create_ledger_tables.php        NEW  tables, chart, rules, settings row, 6 triggers, grants (Task 1)
app/Enums/LedgerAccountType.php                                      NEW  (Task 1)
app/Enums/PermissionName.php, database/seeders/RolesAndPermissionsSeeder.php  MOD  ledger.view/manage/admin (Task 1)
app/Models/LedgerAccount.php, LedgerSetting.php, Journal.php, JournalLine.php  NEW  (Task 1; JournalLine relations Task 7)
app/Integrity/IntegrityCheck.php                                     MOD  EXPECTED_TRIGGERS 67 (Task 1)
tests/TestCase.php                                                   MOD  exceptTables (Task 1)
app/Ledger/Rules.php                                                 NEW  rule key → account (Task 2)
app/Ledger/Sources.php                                               NEW  source types: eligibility SQL + line builders (Task 2; types Tasks 3–4; url Task 7)
app/Ledger/Poster.php                                                NEW  observe/queue/post/catchUp/write (Task 2; WATCH Tasks 3–4)
app/Providers/AppServiceProvider.php                                 MOD  Poster::observe() (Task 2)
tests/Pest.php                                                       MOD  ledgerStart, journalFor, linesOf, postedLines, ledgerBalances (Task 2); ledgerFixture (Task 4)
app/Actions/Ledger/LockLedger.php, UnlockLedger.php, RebuildLedger.php  NEW  (Task 5)
app/Jobs/RebuildLedgerJob.php                                        NEW  (Task 5)
app/Console/Commands/LedgerNightlyCommand.php                        NEW  (Task 5; health Task 8)
routes/console.php, config/services.php                              MOD  00:30 schedule, heartbeat (Task 5)
app/Actions/Ledger/SaveLedgerAccount.php, SetLedgerAccountActive.php, DeleteLedgerAccount.php, SaveLedgerRules.php, SetLedgerStart.php, SaveOpeningBalances.php  NEW  (Task 6)
app/Livewire/Ledger/Accounts.php, Setup.php + views                  NEW  (Task 6)
routes/ledger.php, routes/web.php                                    NEW/MOD  (Task 6; more routes Tasks 7–9)
resources/views/layouts/app/sidebar.blade.php                        MOD  Ledger group (Task 6; items Tasks 7–9)
app/Actions/Ledger/PostManualJournal.php, ReverseManualJournal.php   NEW  (Task 7)
app/Livewire/Ledger/NewJournal.php, JournalShow.php + views          NEW  (Task 7)
app/Ledger/LedgerHealth.php                                          NEW  (Task 8)
app/Livewire/Ledger/Home.php + view                                  NEW  (Task 8)
app/Ledger/Balances.php                                              NEW  (Task 9)
app/Livewire/Reports/TrialBalanceReport.php, BalanceSheetReport.php, ProfitAndLossReport.php, GeneralLedgerReport.php, JournalListReport.php  NEW  (Task 9)
app/Livewire/Reports/Index.php                                       MOD  Ledger group (Task 9)
app/Reports/Builder/Subjects/JournalLines.php, app/Reports/Builder/Registry.php  NEW/MOD  (Task 10)
lang/ar.json                                                         MOD  (Tasks 1, 2, 5–10)
tests/Feature/Auth/RolesAndPermissionsTest.php                       MOD  (Task 1)
tests/Feature/ReportBuilder/SubjectQueriesTest.php                   MOD  (Task 10)
tests/Feature/Ledger/LedgerStorageTest.php                           NEW  (Task 1)
tests/Feature/Ledger/InvoicePostingTest.php                          NEW  (Task 2)
tests/Feature/Ledger/ReceiptPostingTest.php                          NEW  (Task 3)
tests/Feature/Ledger/MoneyOutPostingTest.php                         NEW  (Task 4)
tests/Feature/Ledger/LockAndRebuildTest.php                          NEW  (Task 5)
tests/Feature/Ledger/LedgerSetupTest.php                             NEW  (Task 6)
tests/Feature/Ledger/ManualJournalTest.php                           NEW  (Task 7)
tests/Feature/Ledger/LedgerHealthTest.php                            NEW  (Task 8)
tests/Feature/Ledger/LedgerReportsTest.php                           NEW  (Task 9)
tests/Feature/Ledger/JournalLinesSubjectTest.php, LedgerAccessTest.php  NEW  (Task 10)
```

---

### Task 1: Storage: accounts, settings, rules, journals, the default chart, six triggers and three permissions

**Files:**
- Create: `database/migrations/2026_12_26_000100_create_ledger_tables.php`
- Create: `app/Enums/LedgerAccountType.php`, `app/Models/LedgerAccount.php`, `app/Models/LedgerSetting.php`, `app/Models/Journal.php`, `app/Models/JournalLine.php`
- Modify: `app/Enums/PermissionName.php`, `database/seeders/RolesAndPermissionsSeeder.php`, `app/Integrity/IntegrityCheck.php`, `tests/TestCase.php`, `tests/Feature/Auth/RolesAndPermissionsTest.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/LedgerStorageTest.php`

**Interfaces:**
- Consumes: `IntegrityCheck::EXPECTED_TRIGGERS` (61), the `rms_trigger_count()` function, spatie roles.
- Produces:
  - Tables `ledger_accounts`, `ledger_settings` (one row), `ledger_rules (key, ledger_account_id)`, `journals`, `journal_lines` (ruling 10 rows inserted).
  - `LedgerAccountType` (`asset|liability|equity|income|expense`) with `label(): string` and `sign(): int` (+1 asset/expense, −1 others).
  - `LedgerAccount` (`code`, `name_en`, `name_ar`, `type`, `system_key`, `active`; `lines(): HasMany`, `name(): string`, `label(): string`).
  - `LedgerSetting::current(): self`, `firstOpenDay(): ?CarbonImmutable`, `rebuilding(): bool`; columns `start_date`, `locked_until`, `health` (array), `health_checked_at`, `rebuild_status|from|to|done|total|message`.
  - `Journal` (`date`, `kind`, `source_type`, `source_id`, `description`, `late_posting`, `reverses_journal_id`, `created_by`; `lines(): HasMany`).
  - `JournalLine` (`journal_id`, `ledger_account_id`, `debit`, `credit`, `customer_id`, `owner_id`, `building_id`, `unit_id`, `memo`; `account(): BelongsTo`, `journal(): BelongsTo`; no timestamps).
  - `PermissionName::LedgerView|LedgerManage|LedgerAdmin` (`ledger.view|manage|admin`).
  - `IntegrityCheck::EXPECTED_TRIGGERS = 67`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Ledger/LedgerStorageTest.php`:

```php
<?php

use App\Integrity\IntegrityCheck;
use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** A two-line journal written straight to the tables (Cash 10.000 Dr, Bank 10.000 Cr). */
function storageJournal(string $kind, string $date, ?string $sourceType = null, ?int $sourceId = null): Journal
{
    $journal = (new Journal)->forceFill(['date' => $date, 'kind' => $kind, 'source_type' => $sourceType, 'source_id' => $sourceId, 'description' => 'Test']);
    $journal->save();
    $journal->lines()->create(['ledger_account_id' => LedgerAccount::query()->where('system_key', 'cash')->value('id'), 'debit' => '10.000', 'credit' => '0.000']);
    $journal->lines()->create(['ledger_account_id' => LedgerAccount::query()->where('system_key', 'bank')->value('id'), 'debit' => '0.000', 'credit' => '10.000']);

    return $journal;
}

test('the default chart, its posting rules and the one settings row are there', function () {
    $owner = LedgerAccount::query()->where('code', '2100')->firstOrFail();

    expect(LedgerAccount::query()->count())->toBe(24)
        ->and(LedgerAccount::query()->where('code', '1100')->value('system_key'))->toBe('receivables')
        ->and($owner->name_ar)->toBe('مستحقات الملاك')
        ->and($owner->type->value)->toBe('liability')
        ->and($owner->label())->toBe('2100 Owner payable')
        ->and(DB::table('ledger_rules')->count())->toBe(25)
        ->and(DB::table('ledger_rules')->where('key', 'method.cheque')->value('ledger_account_id'))->toBe(LedgerAccount::query()->where('code', '1010')->value('id'))
        ->and(DB::table('ledger_rules')->where('key', 'other_payments_out')->value('ledger_account_id'))->toBe(LedgerAccount::query()->where('code', '5190')->value('id'))
        ->and(LedgerSetting::query()->count())->toBe(1)
        ->and(LedgerSetting::current()->start_date)->toBeNull()
        ->and(LedgerSetting::current()->firstOpenDay())->toBeNull()
        ->and(IntegrityCheck::EXPECTED_TRIGGERS)->toBe(67)
        ->and((int) DB::selectOne('SELECT rms_trigger_count() AS n')->n)->toBe(67);
});

test('manual journals never change; automatic ones are removable only while their date is open', function () {
    $manual = storageJournal('manual', '2026-10-05');
    $auto = storageJournal('auto', '2026-10-05', 'invoice', 1);

    expect(fn () => DB::table('journals')->where('id', $manual->id)->update(['description' => 'Changed']))->toThrow(QueryException::class, 'write-once')
        ->and(fn () => DB::table('journal_lines')->where('journal_id', $manual->id)->update(['memo' => 'x']))->toThrow(QueryException::class, 'write-once')
        ->and(fn () => DB::table('journal_lines')->where('journal_id', $manual->id)->delete())->toThrow(QueryException::class, 'manual')
        ->and(fn () => DB::table('journals')->where('id', $manual->id)->delete())->toThrow(QueryException::class, 'manual');

    LedgerSetting::current()->forceFill(['start_date' => '2026-10-01', 'locked_until' => '2026-10-05'])->save();
    expect(LedgerSetting::current()->firstOpenDay()->toDateString())->toBe('2026-10-06')
        ->and(fn () => DB::table('journal_lines')->where('journal_id', $auto->id)->delete())->toThrow(QueryException::class, 'locked')
        ->and(fn () => storageJournal('auto', '2026-10-04', 'invoice', 2))->toThrow(QueryException::class, 'locked');

    LedgerSetting::current()->forceFill(['locked_until' => '2026-10-04'])->save();
    DB::table('journal_lines')->where('journal_id', $auto->id)->delete();
    DB::table('journals')->where('id', $auto->id)->delete();

    expect(Journal::query()->whereKey($auto->id)->exists())->toBeFalse();
});

test('a line has exactly one of debit or credit, one source has one journal, and the lock never precedes the start', function () {
    $journal = storageJournal('auto', '2026-10-05', 'payment', 7);
    $cash = LedgerAccount::query()->where('system_key', 'cash')->value('id');

    expect(fn () => $journal->lines()->create(['ledger_account_id' => $cash, 'debit' => '1.000', 'credit' => '1.000']))->toThrow(QueryException::class)
        ->and(fn () => $journal->lines()->create(['ledger_account_id' => $cash, 'debit' => '0.000', 'credit' => '0.000']))->toThrow(QueryException::class)
        ->and(fn () => storageJournal('auto', '2026-10-06', 'payment', 7))->toThrow(QueryException::class)
        ->and(fn () => LedgerSetting::current()->forceFill(['locked_until' => '2026-10-05'])->save())->toThrow(QueryException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Ledger/LedgerStorageTest.php`
Expected: FAIL. `App\Models\Journal` does not exist.

- [ ] **Step 3: Write the migration** `database/migrations/2026_12_26_000100_create_ledger_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * General ledger spec §3, §7 (plan rulings 5, 10 and 12): the chart of accounts, the one settings row, posting rules,
 * journals and their lines. The default chart and rules are inserted here, so the dev DB, production and every test
 * database get them without a seeder. Six triggers keep manual journals and everything on or before "Locked up to"
 * unchanged (EXPECTED_TRIGGERS 61 → 67). On an installed system the ledger permissions are granted additively.
 */
return new class extends Migration
{
    /** code, English, Arabic, type, system_key */
    private const array CHART = [
        ['1000', 'Cash on hand', 'النقد في الصندوق', 'asset', 'cash'],
        ['1010', 'Bank', 'البنك', 'asset', 'bank'],
        ['1020', 'Card clearing', 'تسويات البطاقات', 'asset', 'card_clearing'],
        ['1100', 'Tenant receivables', 'ذمم المستأجرين المدينة', 'asset', 'receivables'],
        ['2000', 'Tenant deposits held', 'تأمينات المستأجرين المحتفظ بها', 'liability', 'deposits_held'],
        ['2100', 'Owner payable', 'مستحقات الملاك', 'liability', 'owner_payable'],
        ['2200', 'Vendor payable', 'مستحقات الموردين', 'liability', 'vendor_payable'],
        ['2300', 'Tenant credit', 'أرصدة المستأجرين الدائنة', 'liability', 'tenant_credit'],
        ['2400', 'VAT payable', 'ضريبة القيمة المضافة المستحقة', 'liability', 'vat_payable'],
        ['3000', 'Opening balance equity', 'حقوق ملكية الأرصدة الافتتاحية', 'equity', 'opening_equity'],
        ['3100', 'Retained earnings', 'الأرباح المحتجزة', 'equity', 'retained_earnings'],
        ['4000', 'Rent income', 'إيرادات الإيجار', 'income', 'rent_income'],
        ['4100', 'Other charges income', 'إيرادات الرسوم الأخرى', 'income', 'other_income'],
        ['4200', 'Management fee income', 'إيرادات رسوم الإدارة', 'income', 'fee_income'],
        ['5000', 'Lease rent to owners', 'إيجار العقارات المستأجرة من الملاك', 'expense', 'lease_rent'],
        ['5100', 'Maintenance and repairs', 'الصيانة والإصلاحات', 'expense', 'expense_maintenance'],
        ['5110', 'Utilities', 'المرافق', 'expense', 'expense_utilities'],
        ['5120', 'Cleaning', 'التنظيف', 'expense', 'expense_cleaning'],
        ['5130', 'Security', 'الأمن والحراسة', 'expense', 'expense_security'],
        ['5140', 'Insurance', 'التأمين', 'expense', 'expense_insurance'],
        ['5150', 'Government fees', 'الرسوم الحكومية', 'expense', 'expense_government'],
        ['5160', 'Legal fees', 'الرسوم القانونية', 'expense', 'expense_legal'],
        ['5170', 'Commissions', 'العمولات', 'expense', 'expense_commission'],
        ['5190', 'Other expenses', 'مصروفات أخرى', 'expense', 'expense_other'],
    ];

    /** posting rule key => the system_key of its default account (plan ruling 10) */
    private const array RULES = [
        'receivables' => 'receivables', 'tenant_credit' => 'tenant_credit', 'deposits_held' => 'deposits_held',
        'owner_payable' => 'owner_payable', 'vendor_payable' => 'vendor_payable', 'vat_payable' => 'vat_payable',
        'rent_income' => 'rent_income', 'other_income' => 'other_income', 'fee_income' => 'fee_income',
        'lease_rent' => 'lease_rent', 'opening_equity' => 'opening_equity', 'other_payments_out' => 'expense_other',
        'method.cash' => 'cash', 'method.bank_transfer' => 'bank', 'method.cheque' => 'bank', 'method.card' => 'card_clearing',
        'category.maintenance' => 'expense_maintenance', 'category.utilities' => 'expense_utilities', 'category.cleaning' => 'expense_cleaning',
        'category.security' => 'expense_security', 'category.insurance' => 'expense_insurance', 'category.government' => 'expense_government',
        'category.legal' => 'expense_legal', 'category.commission' => 'expense_commission', 'category.other' => 'expense_other',
    ];

    /** role => permissions, as RolesAndPermissionsSeeder::matrix() grants them (plan ruling 12) */
    private const array GRANTS = ['admin' => ['ledger.view', 'ledger.admin'], 'finance' => ['ledger.view', 'ledger.manage']];

    private const array PERMISSIONS = ['ledger.view', 'ledger.manage', 'ledger.admin'];

    private const array TRIGGERS = ['journal_lines_delete_guard', 'journal_lines_insert_guard', 'journal_lines_no_update', 'journals_delete_guard', 'journals_insert_guard', 'journals_no_update'];

    private const string LOCKED = "(SELECT COALESCE(MAX(locked_until), '1000-01-01') FROM ledger_settings)";

    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name_en', 150);
            $table->string('name_ar', 150);
            $table->string('type', 10);
            $table->string('system_key', 40)->nullable()->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_chk CHECK (type IN ('asset', 'liability', 'equity', 'income', 'expense'))");

        Schema::create('ledger_settings', function (Blueprint $table) {
            $table->id();
            $table->date('start_date')->nullable();
            $table->date('locked_until')->nullable();
            $table->json('health')->nullable();
            $table->timestamp('health_checked_at')->nullable();
            $table->string('rebuild_status', 10)->nullable();
            $table->date('rebuild_from')->nullable();
            $table->date('rebuild_to')->nullable();
            $table->unsignedInteger('rebuild_done')->default(0);
            $table->unsignedInteger('rebuild_total')->default(0);
            $table->string('rebuild_message', 500)->nullable();
            $table->timestamps();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_settings
                ADD CONSTRAINT ledger_settings_lock_chk CHECK (locked_until IS NULL OR (start_date IS NOT NULL AND locked_until >= start_date)),
                ADD CONSTRAINT ledger_settings_rebuild_chk CHECK (rebuild_status IS NULL OR rebuild_status IN ('queued', 'running', 'done', 'failed'))
            SQL);

        Schema::create('ledger_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('kind', 10);
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 500);
            $table->boolean('late_posting')->default(false);
            $table->foreignId('reverses_journal_id')->nullable()->unique()->constrained('journals')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['source_type', 'source_id']); // plan ruling 1: one journal per source
            $table->index(['date', 'id']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE journals
                ADD CONSTRAINT journals_kind_chk CHECK (kind IN ('auto', 'manual', 'opening')),
                ADD CONSTRAINT journals_source_chk CHECK ((kind = 'auto') = (source_type IS NOT NULL) AND (source_type IS NULL) = (source_id IS NULL)),
                ADD CONSTRAINT journals_reverses_chk CHECK (reverses_journal_id IS NULL OR kind = 'manual'),
                ADD CONSTRAINT journals_late_chk CHECK (late_posting = 0 OR kind = 'auto')
            SQL);

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete(); // spec §3: an account with lines cannot be deleted
            $table->decimal('debit', 15, 3)->default(0);
            $table->decimal('credit', 15, 3)->default(0);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('memo', 255)->nullable();
            $table->index(['ledger_account_id', 'journal_id']);
        });
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_side_chk CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');

        // Plan ruling 5. One statement per unprepared() call.
        DB::unprepared("CREATE TRIGGER journals_no_update BEFORE UPDATE ON journals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journals are write-once: correct one by reversal'");
        DB::unprepared("CREATE TRIGGER journal_lines_no_update BEFORE UPDATE ON journal_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines are write-once'");
        DB::unprepared('CREATE TRIGGER journals_insert_guard BEFORE INSERT ON journals FOR EACH ROW
            BEGIN
                IF NEW.date <= '.self::LOCKED." THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journals: that date is in a locked period';
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER journals_delete_guard BEFORE DELETE ON journals FOR EACH ROW
            BEGIN
                IF OLD.kind = 'manual' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journals: a manual journal is never deleted; reverse it';
                END IF;
                IF OLD.date <= ".self::LOCKED." THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journals: that date is in a locked period';
                END IF;
            END");
        DB::unprepared('CREATE TRIGGER journal_lines_insert_guard BEFORE INSERT ON journal_lines FOR EACH ROW
            BEGIN
                IF (SELECT date FROM journals WHERE id = NEW.journal_id) <= '.self::LOCKED." THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines: that date is in a locked period';
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER journal_lines_delete_guard BEFORE DELETE ON journal_lines FOR EACH ROW
            BEGIN
                DECLARE k VARCHAR(10);
                DECLARE d DATE;
                SELECT kind, date INTO k, d FROM journals WHERE id = OLD.journal_id;
                IF k = 'manual' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines: a manual journal never changes; reverse it';
                END IF;
                IF d <= ".self::LOCKED." THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines: that date is in a locked period';
                END IF;
            END");

        // Plan ruling 10: the default chart, its rules and the one settings row.
        $now = now();
        foreach (self::CHART as [$code, $en, $ar, $type, $key]) {
            DB::table('ledger_accounts')->insert(['code' => $code, 'name_en' => $en, 'name_ar' => $ar, 'type' => $type, 'system_key' => $key,
                'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        $ids = DB::table('ledger_accounts')->pluck('id', 'system_key');
        foreach (self::RULES as $rule => $key) {
            DB::table('ledger_rules')->insert(['key' => $rule, 'ledger_account_id' => $ids[$key], 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('ledger_settings')->insert(['created_at' => $now, 'updated_at' => $now]);

        // Plan ruling 12: a fresh install has no roles yet and gets these from RolesAndPermissionsSeeder (rms:install).
        if (Role::query()->exists()) {
            foreach (self::PERMISSIONS as $name) {
                Permission::findOrCreate($name, 'web');
            }
            foreach (self::GRANTS as $role => $permissions) {
                Role::query()->where('name', $role)->first()?->givePermissionTo($permissions); // additive: keeps Admin's role edits
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journals');
        Schema::dropIfExists('ledger_rules');
        Schema::dropIfExists('ledger_settings');
        Schema::dropIfExists('ledger_accounts');
        Permission::query()->whereIn('name', self::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
```

- [ ] **Step 4: Create the enum** `app/Enums/LedgerAccountType.php`:

```php
<?php

namespace App\Enums;

/** General ledger spec §3: the five account types. Assets and expenses carry debit balances; the rest credit balances. */
enum LedgerAccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset => __('Asset'),
            self::Liability => __('Liability'),
            self::Equity => __('Equity'),
            self::Income => __('Income'),
            self::Expense => __('Expense'),
        };
    }

    /** +1 when a debit balance is the account's normal side (assets, expenses), else −1: balance × sign() is what reports show. */
    public function sign(): int
    {
        return in_array($this, [self::Asset, self::Expense], true) ? 1 : -1;
    }
}
```

- [ ] **Step 5: Create the four models.** `app/Models/LedgerAccount.php`:

```php
<?php

namespace App\Models;

use App\Enums\LedgerAccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * General ledger spec §3, §7. Written only by the ledger Actions (SaveLedgerAccount, SetLedgerAccountActive,
 * DeleteLedgerAccount). An account with lines cannot be deleted (foreign key).
 *
 * @property int $id
 * @property string $code
 * @property string $name_en
 * @property string $name_ar
 * @property LedgerAccountType $type
 * @property string|null $system_key
 * @property bool $active
 */
class LedgerAccount extends Model
{
    protected $fillable = ['code', 'name_en', 'name_ar', 'type'];

    protected function casts(): array
    {
        return ['type' => LedgerAccountType::class, 'active' => 'boolean'];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function name(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }

    /** "2100 Owner payable": pickers and reports. */
    public function label(): string
    {
        return $this->code.' '.$this->name();
    }
}
```

`app/Models/LedgerSetting.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * General ledger spec §7: the one settings row (the migration inserts it). Plan rulings 4–6 and 8. Every posting, lock
 * change, start-date move and rebuild chunk takes `lockForUpdate()` on this row.
 *
 * @property int $id
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $locked_until
 * @property list<array{check: string, ledger: int, app: int, money: bool}>|null $health
 * @property CarbonImmutable|null $health_checked_at
 * @property string|null $rebuild_status queued | running | done | failed
 * @property CarbonImmutable|null $rebuild_from
 * @property CarbonImmutable|null $rebuild_to
 * @property int $rebuild_done
 * @property int $rebuild_total
 * @property string|null $rebuild_message
 */
class LedgerSetting extends Model
{
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'locked_until' => 'immutable_date',
            'health' => 'array',
            'health_checked_at' => 'immutable_datetime',
            'rebuild_from' => 'immutable_date',
            'rebuild_to' => 'immutable_date',
            'rebuild_done' => 'integer',
            'rebuild_total' => 'integer',
        ];
    }

    public static function current(): self
    {
        return self::query()->orderBy('id')->firstOrFail();
    }

    /** The first day a posting may land on: the start date, or the day after the lock. Null before the ledger is set up. */
    public function firstOpenDay(): ?CarbonImmutable
    {
        if ($this->start_date === null) {
            return null;
        }

        return $this->locked_until === null ? $this->start_date : $this->locked_until->addDay()->max($this->start_date);
    }

    public function rebuilding(): bool
    {
        return in_array($this->rebuild_status, ['queued', 'running'], true);
    }
}
```

`app/Models/Journal.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * General ledger spec §7. Written only through Poster::write() (plan ruling 1); never updated (journals_no_update).
 * kind: auto (from a source record) | manual (Finance) | opening (Admin's opening balances).
 *
 * @property int $id
 * @property CarbonImmutable $date
 * @property string $kind
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string $description
 * @property bool $late_posting
 * @property int|null $reverses_journal_id
 * @property int|null $created_by
 * @property-read Collection<int, JournalLine> $lines
 */
class Journal extends Model
{
    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'late_posting' => 'boolean'];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
```

`app/Models/JournalLine.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * General ledger spec §7: exactly one of debit or credit is above zero (journal_lines_side_chk). Dimensions are nullable
 * (plan ruling 7). Write-once.
 *
 * @property int $id
 * @property int $journal_id
 * @property int $ledger_account_id
 * @property string $debit
 * @property string $credit
 * @property int|null $customer_id
 * @property int|null $owner_id
 * @property int|null $building_id
 * @property int|null $unit_id
 * @property string|null $memo
 * @property-read LedgerAccount $account
 */
class JournalLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:3', 'credit' => 'decimal:3'];
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
```

- [ ] **Step 6: Permissions.** In `app/Enums/PermissionName.php`:
  - Replace the docblock line `/** The 25 permissions of spec §8.1 plus the two of the maintenance spec (2026-10-17 §6). The values are the names stored by spatie/laravel-permission. */` with `/** The 25 permissions of spec §8.1, the two of the maintenance spec (2026-10-17 §6) and the three of the general ledger (2026-10-24 §3). The values are the names stored by spatie/laravel-permission. */`.
  - After `    case MaintenanceManage = 'maintenance.manage';` add:

```php
    case LedgerView = 'ledger.view';     // Finance and Admin: pages, reports, health (general ledger plan ruling 12)
    case LedgerManage = 'ledger.manage'; // Finance: manual journals, lock, rebuild
    case LedgerAdmin = 'ledger.admin';   // Admin: accounts, rules, start date, opening balances, unlock, rebuild
```

  - In `twoFactorRequired()`, replace `            ...self::financeManage(), self::ApprovalsDecide,` with `            ...self::financeManage(), self::ApprovalsDecide, self::LedgerView, self::LedgerManage, self::LedgerAdmin,`.

  In `database/seeders/RolesAndPermissionsSeeder.php`:
  - Admin: replace `                P::MaintenanceView, P::MaintenanceManage,` with `                P::MaintenanceView, P::MaintenanceManage, P::LedgerView, P::LedgerAdmin,`.
  - Finance: replace `                ...$view, P::BuildingsViewAll, P::OwnersManage, ...P::financeManage(), ...$reports,` with `                ...$view, P::BuildingsViewAll, P::OwnersManage, ...P::financeManage(), ...$reports, P::LedgerView, P::LedgerManage,`.
  - Vendor support: replace `                fn (P $p) => ! in_array($p, [P::ApprovalsDecide, P::OwnersBankManage, ...P::financeManage()], true),` with `                fn (P $p) => ! in_array($p, [P::ApprovalsDecide, P::OwnersBankManage, ...P::financeManage(), P::LedgerView, P::LedgerManage, P::LedgerAdmin], true),`.

- [ ] **Step 7: The permission tests.** In `tests/Feature/Auth/RolesAndPermissionsTest.php`:
  - Rename `'exactly the 27 permissions and 6 roles exist'` to `'exactly the 30 permissions and 6 roles exist'`, and replace `'expenses.manage', 'reports.operational', 'reports.financial', 'approvals.decide', 'import.run', 'maintenance.view', 'maintenance.manage',` with `'expenses.manage', 'reports.operational', 'reports.financial', 'approvals.decide', 'import.run', 'maintenance.view', 'maintenance.manage', 'ledger.view', 'ledger.manage', 'ledger.admin',`.
  - In `'seeding twice changes nothing'`, replace `toBe(27)` with `toBe(30)`.
  - Append:

```php
test('the ledger belongs to Finance (view, post) and Admin (view, administer) only', function () {
    expect(roleHas(RoleName::Finance, P::LedgerView))->toBeTrue()
        ->and(roleHas(RoleName::Finance, P::LedgerManage))->toBeTrue()
        ->and(roleHas(RoleName::Finance, P::LedgerAdmin))->toBeFalse()
        ->and(roleHas(RoleName::Admin, P::LedgerView))->toBeTrue()
        ->and(roleHas(RoleName::Admin, P::LedgerAdmin))->toBeTrue()
        ->and(roleHas(RoleName::Admin, P::LedgerManage))->toBeFalse();

    foreach ([RoleName::Management, RoleName::PropertyManager, RoleName::Leasing, RoleName::VendorSupport] as $role) {
        foreach ([P::LedgerView, P::LedgerManage, P::LedgerAdmin] as $permission) {
            expect(roleHas($role, $permission))->toBeFalse();
        }
    }
});
```

- [ ] **Step 8: The trigger count and the truncation exceptions.**
  - In `app/Integrity/IntegrityCheck.php` replace the `EXPECTED_TRIGGERS` line with:

```php
    public const int EXPECTED_TRIGGERS = 67; // 38 at the end of M3a + 2 (disbursements) + 8 (deposit settlements) + 1 (invoice line charge) + 3 (amendments; the two insert triggers are replaced, not added) + 1 (credit source) + 2 (owner payables) + 2 (owner charges) + 2 (owner statements) + 2 (payment tenders) + 6 (ledger journals and journal lines)
```

  - In `tests/TestCase.php` replace `protected array $exceptTables = ['unit_types', 'reminder_settings'];` with `protected array $exceptTables = ['unit_types', 'reminder_settings', 'ledger_accounts', 'ledger_rules', 'ledger_settings'];`.

- [ ] **Step 9: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Asset": "أصل",
    "Liability": "التزام",
    "Equity": "حقوق الملكية",
    "Expense": "مصروف",
```

- [ ] **Step 10: Migrate the dev DB.**

Run: `php artisan migrate --database=migrator --force`
Expected: `2026_12_26_000100_create_ledger_tables ... DONE`.

- [ ] **Step 11: Run the tests.**

Run: `php artisan test tests/Feature/Ledger/LedgerStorageTest.php tests/Feature/Auth/RolesAndPermissionsTest.php tests/Feature/Integrity/IntegrityCheckTest.php`
Expected: PASS (IntegrityCheckTest's trigger-count test now sees 67).

- [ ] **Step 12: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add database/migrations/2026_12_26_000100_create_ledger_tables.php app/Enums/LedgerAccountType.php app/Models/LedgerAccount.php app/Models/LedgerSetting.php app/Models/Journal.php app/Models/JournalLine.php app/Enums/PermissionName.php database/seeders/RolesAndPermissionsSeeder.php app/Integrity/IntegrityCheck.php tests/TestCase.php tests/Feature/Auth/RolesAndPermissionsTest.php tests/Feature/Ledger/LedgerStorageTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
General ledger storage: accounts, settings, rules, journals and lines, the default chart, six immutability triggers (67) and the ledger permissions

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: The posting engine, and invoices and credit notes

**Files:**
- Create: `app/Ledger/Rules.php`, `app/Ledger/Sources.php`, `app/Ledger/Poster.php`
- Modify: `app/Providers/AppServiceProvider.php`, `tests/Pest.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/InvoicePostingTest.php`

**Interfaces:**
- Consumes: Task 1's models and tables; `invoice_lines.owner_contract_id` as `IssueInvoice` stamps it; `OwnerContract.type`, `deposits_held_by`.
- Produces:
  - `Rules::load(): Rules`, `Rules->account(string $key): int` (throws `LogicException` for a missing or inactive account), `Rules::keys(): list<string>`, `Rules::label(string $key): string`, `Rules::EVENTS`, `Rules::METHODS`.
  - `Sources::TYPES` (`type => [table, own-date SQL, eligibility SQL, joins payments]`), `Sources::REVERSES`, `Sources::pending(string $type, string $start, string $today): Illuminate\Database\Query\Builder` (selects `id`, `own_date`), `Sources::build(string $type, int $id, Rules $rules): array{description: string, lines: list<Line>}`, `Sources::label(string $kindOrType): string`. `Line` = `array{account: int, amount: int, customer_id: ?int, owner_id: ?int, building_id: ?int, unit_id: ?int, memo: ?string}`, amount signed (+ debit).
  - `Poster::WATCH`, `Poster::observe(): void`, `Poster::queue(list<string> $types, int $id): void`, `Poster->post(string $type, int $id): ?Journal`, `Poster->catchUp(?callable $progress = null): int`, `Poster::pendingCount(): int`, `Poster::write(string $kind, string $date, string $description, list<Line> $lines, ?string $sourceType = null, ?int $sourceId = null, bool $late = false, ?int $reverses = null, ?int $createdBy = null): Journal`.
  - Pest: `ledgerStart(string $date): void`, `journalFor(string $sourceType, int $sourceId): ?Journal`, `linesOf(?Journal $journal): list<string>`, `postedLines(string $sourceType, int $sourceId): list<string>` (each line "code debit credit"), `ledgerBalances(?string $upTo = null): array<int, string>` (code ⇒ Dr − Cr, zero balances left out).

- [ ] **Step 1: Add the ledger test helpers to `tests/Pest.php`.** Add to the imports:

```php
use App\Models\Journal;
use App\Models\LedgerSetting;
```

and append:

```php
/** Sets the ledger start date directly (ledger tests; the Admin action is SetLedgerStart). */
function ledgerStart(string $date): void
{
    LedgerSetting::current()->forceFill(['start_date' => $date])->save();
}

/** The journal a source posted, if any (ledger tests). */
function journalFor(string $sourceType, int $sourceId): ?Journal
{
    return Journal::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->first();
}

/** A journal's lines as "code debit credit", in line order, e.g. ['1100 440.000 0.000', '2100 0.000 400.000']; [] for none. */
function linesOf(?Journal $journal): array
{
    return $journal === null ? [] : DB::table('journal_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
        ->where('l.journal_id', $journal->id)->orderBy('l.id')
        ->get(['a.code', 'l.debit', 'l.credit'])->map(fn ($r) => "{$r->code} {$r->debit} {$r->credit}")->all();
}

function postedLines(string $sourceType, int $sourceId): array
{
    return linesOf(journalFor($sourceType, $sourceId));
}

/** Every account's balance (debit − credit, BHD) keyed by code, journals up to a date (all when null), zero balances left out. */
function ledgerBalances(?string $upTo = null): array
{
    return DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
        ->when($upTo, fn ($q, string $d) => $q->where('j.date', '<=', $d))
        ->groupBy('a.code')->havingRaw('SUM(l.debit) <> SUM(l.credit)')->orderBy('a.code')
        ->selectRaw('a.code, SUM(l.debit) - SUM(l.credit) AS balance')->pluck('balance', 'code')->all();
}
```

- [ ] **Step 2: Write the failing test** `tests/Feature/Ledger/InvoicePostingTest.php`:

```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Ledger\Poster;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Models\Owner;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    ledgerStart('2026-10-01');
    $this->building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $this->owner = Owner::factory()->create();
    $this->tenant = Customer::factory()->create();
    // A new unit let to the tenant for 2026, under a contract of the given type for 2026 (none when null).
    $this->unitUnder = function (?string $type, string $heldBy = 'company'): array {
        $unit = Unit::factory()->for($this->building)->create();
        if ($type !== null) {
            activeOwnerContract(['owner_id' => $this->owner->id, 'building_id' => $this->building->id, 'type' => $type, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
                ...($type === 'leased'
                    ? ['rent_amount' => '300.000', 'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null]
                    : ['deposits_held_by' => $heldBy])], [$unit]);
        }
        $agreement = activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$unit]);

        return [$agreement, $agreement->agreementUnits->sole()];
    };
});

test('a managed building\'s rent posts the net to Owner payable and the VAT to VAT payable, with its dimensions', function () {
    [$agreement, $au] = ($this->unitUnder)('managed');
    $invoice = issuedInvoice($this->tenant, [['net' => '400.000', 'tax' => 'standard', 'au' => $au]], '2026-10-01', $agreement);
    $journal = journalFor('invoice', $invoice->id);

    expect(postedLines('invoice', $invoice->id))->toBe(['1100 440.000 0.000', '2100 0.000 400.000', '2400 0.000 40.000'])
        ->and($journal->date->toDateString())->toBe('2026-10-01')
        ->and($journal->kind)->toBe('auto')
        ->and($journal->late_posting)->toBeFalse()
        ->and($journal->description)->toBe($invoice->number)
        ->and($journal->lines->first()->only(['customer_id', 'owner_id', 'building_id', 'unit_id']))
        ->toBe(['customer_id' => $this->tenant->id, 'owner_id' => $this->owner->id, 'building_id' => $this->building->id, 'unit_id' => $au->unit_id]);
});

test('a leased building\'s rent is company income, other charges go to Other charges income, and posting twice changes nothing', function () {
    [$agreement, $au] = ($this->unitUnder)('leased');
    $invoice = issuedInvoice($this->tenant, [['net' => '300.000', 'au' => $au], ['net' => '25.000', 'tax' => 'standard', 'type' => 'service_charge', 'au' => $au]], '2026-10-01', $agreement);

    expect(postedLines('invoice', $invoice->id))->toBe([
        '1100 300.000 0.000', '4000 0.000 300.000',
        '1100 27.500 0.000', '4100 0.000 25.000', '2400 0.000 2.500',
    ])->and(app(Poster::class)->post('invoice', $invoice->id))->toBeNull()
        ->and(Journal::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->count())->toBe(1);
});

test('a deposit is held for the tenant, or owed to the owner when the owner holds deposits', function () {
    [$companyAgreement, $companyAu] = ($this->unitUnder)('managed', 'company');
    [$ownerAgreement, $ownerAu] = ($this->unitUnder)('managed', 'owner');
    $company = issuedInvoice($this->tenant, [['net' => '400.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => $companyAu]], '2026-10-01', $companyAgreement, 'deposit');
    $owner = issuedInvoice($this->tenant, [['net' => '500.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => $ownerAu]], '2026-10-01', $ownerAgreement, 'deposit');

    expect(postedLines('invoice', $company->id))->toBe(['1100 400.000 0.000', '2000 0.000 400.000'])
        ->and(postedLines('invoice', $owner->id))->toBe(['1100 500.000 0.000', '2100 0.000 500.000']);
});

test('a credit note posts the exact opposite of the lines it credits, on its own issue date', function () {
    [$agreement, $au] = ($this->unitUnder)('managed');
    $invoice = issuedInvoice($this->tenant, [['net' => '400.000', 'tax' => 'standard', 'au' => $au]], '2026-10-01', $agreement);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00', 'Asia/Bahrain'));
    $cn = app(SaveCreditNote::class)->handle($this->finance, $invoice, null, ['reason' => 'Goodwill', 'lines' => [['credited_line_id' => $invoice->lines->sole()->id, 'amount' => '110.000']]]);
    app(DecideApproval::class)->handle(matrixUser(RoleName::Management, $this->building), app(SubmitCreditNote::class)->handle($this->finance, $cn), true);

    expect(postedLines('invoice', $cn->id))->toBe(['1100 0.000 110.000', '2100 100.000 0.000', '2400 10.000 0.000'])
        ->and(journalFor('invoice', $cn->id)->date->toDateString())->toBe('2026-10-12');
});

test('a unit with no owner contract on the date falls back to company income', function () {
    [$agreement, $au] = ($this->unitUnder)(null);
    $invoice = issuedInvoice($this->tenant, [['net' => '200.000', 'au' => $au]], '2026-10-01', $agreement);

    expect(postedLines('invoice', $invoice->id))->toBe(['1100 200.000 0.000', '4000 0.000 200.000']);
});

test('nothing posts before the start date, before the ledger is set up, or before an invoice is issued', function () {
    [$agreement, $au] = ($this->unitUnder)('leased');
    $this->travelTo(CarbonImmutable::parse('2026-09-30 09:00', 'Asia/Bahrain'));
    $september = issuedInvoice($this->tenant, [['net' => '300.000', 'au' => $au]], '2026-09-01', $agreement);
    LedgerSetting::current()->forceFill(['start_date' => null])->save();
    $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00', 'Asia/Bahrain'));
    $unset = issuedInvoice($this->tenant, [['net' => '300.000', 'au' => $au]], '2026-10-01', $agreement);
    $draft = (new Invoice)->forceFill(['type' => 'manual', 'customer_id' => $this->tenant->id, 'issue_date' => '2026-10-02', 'due_date' => '2026-10-02', 'status' => 'draft']);
    $draft->save();

    expect(journalFor('invoice', $september->id))->toBeNull()
        ->and(journalFor('invoice', $unset->id))->toBeNull()
        ->and(journalFor('invoice', $draft->id))->toBeNull();

    ledgerStart('2026-10-01');
    app(Poster::class)->catchUp();

    expect(journalFor('invoice', $unset->id))->not->toBeNull()
        ->and(journalFor('invoice', $september->id))->toBeNull()
        ->and(journalFor('invoice', $draft->id))->toBeNull();
});

test('a posting failure leaves the invoice issued; catch-up posts it once the account is active again', function () {
    [$agreement, $au] = ($this->unitUnder)('leased');
    $receivables = LedgerAccount::query()->where('code', '1100')->firstOrFail();
    $receivables->forceFill(['active' => false])->save();
    $invoice = issuedInvoice($this->tenant, [['net' => '300.000', 'au' => $au]], '2026-10-01', $agreement);

    expect($invoice->status->value)->toBe('issued')
        ->and(journalFor('invoice', $invoice->id))->toBeNull()
        ->and(Poster::pendingCount())->toBe(1);

    $receivables->forceFill(['active' => true])->save();
    app(Poster::class)->catchUp();

    expect(postedLines('invoice', $invoice->id))->toBe(['1100 300.000 0.000', '4000 0.000 300.000'])
        ->and(Poster::pendingCount())->toBe(0);
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/Ledger/InvoicePostingTest.php`
Expected: FAIL. `App\Ledger\Poster` does not exist.

- [ ] **Step 4: Create `app/Ledger/Rules.php`:**

```php
<?php

namespace App\Ledger;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * General ledger spec §3 "Posting rules" (plan rulings 3 and 10): each rule key names one ledger account. Keys are the
 * events' accounts, `method.<payment or payout method>` and `category.<expense category>`.
 */
final class Rules
{
    /** event key => label (translated by label()) */
    public const array EVENTS = [
        'receivables' => 'Tenant receivables',
        'tenant_credit' => 'Tenant credit',
        'deposits_held' => 'Tenant deposits held',
        'owner_payable' => 'Owner payable',
        'vendor_payable' => 'Vendor payable',
        'vat_payable' => 'VAT payable',
        'rent_income' => 'Rent income',
        'other_income' => 'Other charges income',
        'fee_income' => 'Management fee income',
        'lease_rent' => 'Lease rent to owners',
        'opening_equity' => 'Opening balance equity',
        'other_payments_out' => 'Other payments out',
    ];

    /** The methods that move money. deposit_applied and split never reach a rule (plan ruling 3, rows 8–9). */
    public const array METHODS = ['cash', 'bank_transfer', 'cheque', 'card'];

    /** @param  array<string, int>  $accounts  rule key => active account id */
    private function __construct(private readonly array $accounts) {}

    /** The rules whose account is active; a rule on an inactive account is missing, so posting to it fails (spec §3). */
    public static function load(): self
    {
        $accounts = [];
        foreach (DB::table('ledger_rules as r')->join('ledger_accounts as a', 'a.id', '=', 'r.ledger_account_id')
            ->where('a.active', true)->get(['r.key', 'a.id']) as $row) {
            $accounts[(string) $row->key] = (int) $row->id;
        }

        return new self($accounts);
    }

    public function account(string $key): int
    {
        return $this->accounts[$key] ?? throw new LogicException("Ledger rule [{$key}] has no active account.");
    }

    /** @return list<string> every rule key, in screen order */
    public static function keys(): array
    {
        return [
            ...array_keys(self::EVENTS),
            ...array_map(fn (string $m) => 'method.'.$m, self::METHODS),
            ...array_map(fn (ExpenseCategory $c) => 'category.'.$c->value, ExpenseCategory::cases()),
        ];
    }

    public static function label(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'method.') => __('Paid or received by :method', ['method' => __(PaymentMethod::from(substr($key, 7))->label())]),
            str_starts_with($key, 'category.') => __('Expense: :category', ['category' => __(str(substr($key, 9))->headline()->toString())]),
            default => __(self::EVENTS[$key] ?? $key),
        };
    }
}
```

- [ ] **Step 5: Create `app/Ledger/Sources.php`** (invoices only; Tasks 3 and 4 add the other types):

```php
<?php

namespace App\Ledger;

use App\Support\Fils;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * General ledger spec §4 (plan rulings 1–3 and 7): the money records the ledger reads, one source type each. A type has
 * one eligibility query, shared by live posting, catch-up, rebuild and Ledger health, and one line builder. Lines are
 * signed: + debit, − credit. A reversal type posts the exact opposite of its base type's lines.
 *
 * @phpstan-type Line array{account: int, amount: int, customer_id: int|null, owner_id: int|null, building_id: int|null, unit_id: int|null, memo: string|null}
 * @phpstan-type Built array{description: string, lines: list<Line>}
 */
final class Sources
{
    /** type => [table, own date (SQL over alias t), eligibility (SQL over t), joins payments as p] (plan ruling 3) */
    public const array TYPES = [
        'invoice' => ['invoices', 't.issue_date', "t.status = 'issued'", false],
    ];

    /** reversal type => the type whose lines it negates */
    public const array REVERSES = [
        'payment_reversal' => 'payment',
        'expense_reversal' => 'expense',
        'disbursement_reversal' => 'disbursement',
        'owner_payable_cancel' => 'owner_payable',
    ];

    /** The type's records due a journal that have none: own date from $start to $today. Selects id and own_date. */
    public static function pending(string $type, string $start, string $today): Builder
    {
        [$table, $date, $where, $payments] = self::TYPES[$type] ?? throw new LogicException("Unknown ledger source [{$type}].");

        return DB::table($table.' as t')
            ->when($payments, fn (Builder $q) => $q->join('payments as p', 'p.id', '=', 't.payment_id'))
            ->whereRaw($where)
            ->whereRaw("{$date} BETWEEN ? AND ?", [$start, $today])
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('journals as j')->where('j.source_type', $type)->whereColumn('j.source_id', 't.id'))
            ->selectRaw("t.id AS id, {$date} AS own_date");
    }

    /** @return Built */
    public static function build(string $type, int $id, Rules $rules): array
    {
        if (isset(self::REVERSES[$type])) {
            $base = self::build(self::REVERSES[$type], $id, $rules);

            return ['description' => $base['description'], 'lines' => array_map(fn (array $l) => [...$l, 'amount' => -$l['amount']], $base['lines'])];
        }

        return match ($type) {
            'invoice' => self::invoice($id, $rules),
            default => throw new LogicException("Unknown ledger source [{$type}]."),
        };
    }

    /** What a journal's source is called on screen: a source type, or the kind of a manual or opening journal. */
    public static function label(string $kindOrType): string
    {
        return match ($kindOrType) {
            'invoice' => __('Invoice'),
            'payment' => __('Receipt'),
            'payment_reversal' => __('Payment reversal'),
            'allocation' => __('Payment applied'),
            'expense' => __('Expense'),
            'expense_reversal' => __('Expense reversal'),
            'disbursement' => __('Payment out'),
            'disbursement_reversal' => __('Payment out reversal'),
            'owner_charge' => __('Owner charge'),
            'owner_payable' => __('Head-lease rent due'),
            'owner_payable_cancel' => __('Head-lease rent cancelled'),
            'manual' => __('Manual journal'),
            'opening' => __('Opening balances'),
            default => $kindOrType,
        };
    }

    /**
     * Rows 1–6: per line, receivables against the owner (managed), company income (leased or no contract), the deposit's
     * holder, or opening equity; VAT separately. A credit note posts the opposite, each line mapped as the line it credits.
     *
     * @return Built
     */
    private static function invoice(int $id, Rules $rules): array
    {
        $invoice = DB::table('invoices')->where('id', $id)->first(['type', 'number', 'customer_id']) ?? throw new LogicException("Invoice {$id} is missing.");
        $sign = $invoice->type === 'credit_note' ? -1 : 1;
        $customer = (int) $invoice->customer_id;
        $lines = [];

        foreach (DB::table('invoice_lines as l')
            ->leftJoin('invoice_lines as tl', 'tl.id', '=', 'l.credited_line_id')
            ->leftJoin('agreement_units as au', 'au.id', '=', DB::raw('COALESCE(tl.agreement_unit_id, l.agreement_unit_id)'))
            ->leftJoin('units as u', 'u.id', '=', DB::raw('COALESCE(tl.unit_id, l.unit_id, au.unit_id)'))
            ->leftJoin('owner_contracts as c', 'c.id', '=', DB::raw('COALESCE(tl.owner_contract_id, l.owner_contract_id)'))
            ->where('l.invoice_id', $id)->orderBy('l.id')
            ->get([DB::raw('COALESCE(tl.charge_type, l.charge_type) AS charge_type'), 'l.net', 'l.tax_amount', 'l.total',
                'c.type AS contract_type', 'c.deposits_held_by', 'c.owner_id', 'u.id AS unit_id', 'u.building_id']) as $row) {
            $dims = [$customer, self::id($row->owner_id), self::id($row->building_id), self::id($row->unit_id)];
            $total = $sign * self::fils($row->total);
            $lines[] = self::line($rules->account('receivables'), $total, ...$dims);

            if ($row->charge_type === 'deposit') {
                $lines[] = self::line(self::heldAccount($row->contract_type, $row->deposits_held_by, $rules), -$total, ...$dims);

                continue;
            }

            $income = match (true) {
                $row->contract_type === 'managed' => 'owner_payable',
                $row->charge_type === 'opening_balance' => 'opening_equity',
                $row->charge_type === 'rent' => 'rent_income',
                default => 'other_income',
            };
            $lines[] = self::line($rules->account($income), -$sign * self::fils($row->net), ...$dims);
            $lines[] = self::line($rules->account('vat_payable'), -$sign * self::fils($row->tax_amount), ...$dims);
        }

        return ['description' => (string) $invoice->number, 'lines' => $lines];
    }

    /** Plan ruling 3: a deposit sits with the owner only on a managed contract whose owner holds deposits. */
    private static function heldAccount(mixed $contractType, mixed $heldBy, Rules $rules): int
    {
        return $contractType === 'managed' && $heldBy === 'owner' ? $rules->account('owner_payable') : $rules->account('deposits_held');
    }

    /** @return Line */
    private static function line(int $account, int $amount, ?int $customer = null, ?int $owner = null, ?int $building = null, ?int $unit = null): array
    {
        return ['account' => $account, 'amount' => $amount, 'customer_id' => $customer, 'owner_id' => $owner, 'building_id' => $building, 'unit_id' => $unit, 'memo' => null];
    }

    private static function id(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function fils(mixed $decimal): int
    {
        return Fils::fromDecimal((string) $decimal);
    }
}
```

- [ ] **Step 6: Create `app/Ledger/Poster.php`:**

```php
<?php

namespace App\Ledger;

use App\Models\Invoice;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Support\Fils;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Plan ruling 1: a money record posts after its own transaction commits, in a transaction of its own. A failure is
 * reported and never undoes the money record; the nightly catch-up retries and Ledger health counts what is unposted.
 *
 * @phpstan-import-type Line from Sources
 */
final class Poster
{
    /** model => the source types its saves may post (plan ruling 1) */
    public const array WATCH = [
        Invoice::class => ['invoice'],
    ];

    /** Called once from AppServiceProvider::boot(). */
    public static function observe(): void
    {
        foreach (self::WATCH as $model => $types) {
            $model::saved(fn (Model $record) => self::queue($types, (int) $record->getKey()));
        }
    }

    /** @param  list<string>  $types */
    public static function queue(array $types, int $id): void
    {
        DB::afterCommit(function () use ($types, $id) {
            foreach ($types as $type) {
                try {
                    app(self::class)->post($type, $id);
                } catch (Throwable $e) {
                    report($e); // spec §8: the money record stands; catch-up retries and Ledger health shows the gap
                }
            }
        });
    }

    /** Posts one source when it is eligible and has no journal yet. Idempotent. */
    public function post(string $type, int $id): ?Journal
    {
        if (LedgerSetting::current()->start_date === null) {
            return null; // the ledger is not set up: nothing to post, and no lock taken
        }

        return DB::transaction(function () use ($type, $id) {
            // ponytail: one row lock serialises every posting, lock change and rebuild chunk; per-month locks if volume ever needs it.
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            $first = $settings->firstOpenDay();
            if ($settings->start_date === null || $first === null) {
                return null;
            }
            $row = Sources::pending($type, $settings->start_date->toDateString(), now('Asia/Bahrain')->toDateString())->where('t.id', $id)->first();
            if ($row === null) {
                return null;
            }
            $own = (string) $row->own_date;
            $late = $own < $first->toDateString(); // spec §5: dated in a locked month, so it lands on the first open day
            $built = Sources::build($type, $id, Rules::load());

            return self::write('auto', $late ? $first->toDateString() : $own, $built['description'], $built['lines'], $type, $id, $late);
        }, attempts: 3);
    }

    /**
     * Posts every pending source: nightly, after a rebuild's deletions, after the start date moves. Returns how many it tried.
     *
     * @param  (callable(int): void)|null  $progress  called with the running count after each chunk
     */
    public function catchUp(?callable $progress = null): int
    {
        $start = LedgerSetting::current()->start_date?->toDateString();
        if ($start === null) {
            return 0;
        }
        $today = now('Asia/Bahrain')->toDateString();
        $done = 0;

        foreach (array_keys(Sources::TYPES) as $type) {
            $after = 0;
            while (($ids = Sources::pending($type, $start, $today)->where('t.id', '>', $after)->orderBy('t.id')->limit(200)->pluck('id'))->isNotEmpty()) {
                foreach ($ids as $id) {
                    $after = (int) $id;
                    try {
                        $this->post($type, $after);
                    } catch (Throwable $e) {
                        report($e); // still pending: Ledger health counts it
                    }
                    $done++;
                }
                if ($progress !== null) {
                    $progress($done);
                }
            }
        }

        return $done;
    }

    /** Sources due a journal that have none (Ledger health; a rebuild's progress total). */
    public static function pendingCount(): int
    {
        $start = LedgerSetting::current()->start_date?->toDateString();
        if ($start === null) {
            return 0;
        }
        $today = now('Asia/Bahrain')->toDateString();

        return array_sum(array_map(fn (string $type) => Sources::pending($type, $start, $today)->count(), array_keys(Sources::TYPES)));
    }

    /**
     * The only writer of journals. Refuses fewer than two non-zero lines, an unbalanced journal and an inactive account
     * (spec §3: a deactivated account takes no new postings; §4: every journal balances before it is saved).
     *
     * @param  list<Line>  $lines
     */
    public static function write(string $kind, string $date, string $description, array $lines, ?string $sourceType = null, ?int $sourceId = null,
        bool $late = false, ?int $reverses = null, ?int $createdBy = null): Journal
    {
        $lines = array_values(array_filter($lines, fn (array $l) => $l['amount'] !== 0));
        if (count($lines) < 2 || array_sum(array_column($lines, 'amount')) !== 0) {
            throw new LogicException("Journal \"{$description}\" does not balance.");
        }
        $accounts = array_values(array_unique(array_column($lines, 'account')));
        if (LedgerAccount::query()->whereKey($accounts)->where('active', true)->count() !== count($accounts)) {
            throw new LogicException("Journal \"{$description}\" uses an inactive account.");
        }

        $journal = (new Journal)->forceFill([
            'date' => $date, 'kind' => $kind, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'description' => mb_substr($description, 0, 500), 'late_posting' => $late, 'reverses_journal_id' => $reverses, 'created_by' => $createdBy,
        ]);
        $journal->save();

        foreach ($lines as $l) {
            JournalLine::query()->create([
                'journal_id' => $journal->id,
                'ledger_account_id' => $l['account'],
                'debit' => Fils::toDecimal(max(0, $l['amount'])),
                'credit' => Fils::toDecimal(max(0, -$l['amount'])),
                'customer_id' => $l['customer_id'],
                'owner_id' => $l['owner_id'],
                'building_id' => $l['building_id'],
                'unit_id' => $l['unit_id'],
                'memo' => $l['memo'],
            ]);
        }

        return $journal;
    }
}
```

- [ ] **Step 7: Register the observer.** In `app/Providers/AppServiceProvider.php` add `use App\Ledger\Poster;` to the imports, and after `        Event::subscribe(AuthEventSubscriber::class);` add:

```php

        // General ledger plan ruling 1: money records post to the ledger after their own transaction commits.
        Poster::observe();
```

- [ ] **Step 8: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Tenant receivables": "ذمم المستأجرين المدينة",
    "Tenant credit": "أرصدة المستأجرين الدائنة",
    "Tenant deposits held": "تأمينات المستأجرين المحتفظ بها",
    "Owner payable": "مستحقات الملاك",
    "Vendor payable": "مستحقات الموردين",
    "VAT payable": "ضريبة القيمة المضافة المستحقة",
    "Rent income": "إيرادات الإيجار",
    "Other charges income": "إيرادات الرسوم الأخرى",
    "Management fee income": "إيرادات رسوم الإدارة",
    "Lease rent to owners": "إيجار العقارات المستأجرة من الملاك",
    "Opening balance equity": "حقوق ملكية الأرصدة الافتتاحية",
    "Other payments out": "مدفوعات صادرة أخرى",
    "Paid or received by :method": "مدفوع أو مقبوض عن طريق :method",
    "Expense: :category": "مصروف: :category",
    "Receipt": "إيصال",
    "Payment applied": "دفعة مخصصة لفاتورة",
    "Expense reversal": "عكس مصروف",
    "Payment out": "دفعة صادرة",
    "Payment out reversal": "عكس دفعة صادرة",
    "Owner charge": "رسوم على المالك",
    "Head-lease rent due": "إيجار مستحق للمالك",
    "Head-lease rent cancelled": "إلغاء إيجار مستحق للمالك",
    "Manual journal": "قيد يدوي",
    "Opening balances": "الأرصدة الافتتاحية",
```

- [ ] **Step 9: Run the tests.**

Run: `php artisan test tests/Feature/Ledger/InvoicePostingTest.php tests/Feature/Billing tests/Feature/Invoices`
Expected: PASS. The billing and invoice suites still pass: with no start date, `post()` returns before taking any lock.

- [ ] **Step 10: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Ledger/Rules.php app/Ledger/Sources.php app/Ledger/Poster.php app/Providers/AppServiceProvider.php tests/Pest.php tests/Feature/Ledger/InvoicePostingTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
General ledger posting engine: sources posted after commit, idempotent, failures reported; invoices and credit notes, managed or leased by the line's contract stamp

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Receipts, applied payments, cheques, deposits applied and payment reversals

**Files:**
- Modify: `app/Ledger/Sources.php`, `app/Ledger/Poster.php`
- Test: `tests/Feature/Ledger/ReceiptPostingTest.php`

**Interfaces:**
- Consumes: Task 2's `Sources`, `Poster`, `Rules`, Pest helpers; `RecordPayment`, `ClearCheque`, `RequestPaymentReversal`, `DecideApproval`, `CreateDepositSettlement`, `SaveSettlementDeductions`, `SubmitDepositSettlement`, `AllocateCustomerCredit` (run by `IssueInvoice` after commit).
- Produces: source types `payment`, `payment_reversal`, `allocation` (plan ruling 3, rows 7–11); `Poster::WATCH` entries for `Payment` and `PaymentAllocation`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Ledger/ReceiptPostingTest.php`:

```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Cheques\ClearCheque;
use App\Actions\Deposits\CreateDepositSettlement;
use App\Actions\Deposits\SaveSettlementDeductions;
use App\Actions\Deposits\SubmitDepositSettlement;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RequestPaymentReversal;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Journal;
use App\Models\PaymentAllocation;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create(['vat_registered' => false, 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    ledgerStart('2026-10-01');
    $this->building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $this->management = matrixUser(RoleName::Management, $this->building);
    $this->tenant = Customer::factory()->create();
    $unit = Unit::factory()->for($this->building)->create();
    activeOwnerContract(['building_id' => $this->building->id, 'type' => 'leased', 'rent_amount' => '300.000', 'payment_frequency' => 'monthly', 'fee_type' => null,
        'fee_value' => null, 'deposits_held_by' => null, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$unit]);
    $this->agreement = activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$unit]);
    $this->au = $this->agreement->agreementUnits->sole();
    $this->rent = issuedInvoice($this->tenant, [['net' => '440.000', 'au' => $this->au]], '2026-10-01', $this->agreement); // Dr 1100 / Cr 4000 440.000
    $this->pay = fn (array $data) => app(RecordPayment::class)->handle($this->finance, $this->tenant, ['received_on' => '2026-10-03', ...$data]);
});

test('a receipt debits its method\'s account and credits Tenant credit; what it pays moves to receivables on the receipt date', function () {
    $payment = ($this->pay)(['method' => 'cash', 'amount' => '500.000']);
    $allocation = $payment->allocations()->sole();

    expect(postedLines('payment', $payment->id))->toBe(['1000 500.000 0.000', '2300 0.000 500.000'])
        ->and(journalFor('payment', $payment->id)->date->toDateString())->toBe('2026-10-03')
        ->and(journalFor('payment', $payment->id)->lines->first()->customer_id)->toBe($this->tenant->id)
        ->and(postedLines('allocation', $allocation->id))->toBe(['2300 440.000 0.000', '1100 0.000 440.000'])
        ->and(journalFor('allocation', $allocation->id)->date->toDateString())->toBe('2026-10-03')
        ->and(ledgerBalances())->toBe([1000 => '500.000', 2300 => '-60.000', 4000 => '-440.000']);
});

test('a split receipt debits each part\'s account', function () {
    $payment = ($this->pay)(['tenders' => [['method' => 'card', 'amount' => '300.000'], ['method' => 'cash', 'amount' => '200.000']]]);

    expect(postedLines('payment', $payment->id))->toBe(['1020 300.000 0.000', '1000 200.000 0.000', '2300 0.000 500.000']);
});

test('a cheque posts nothing while held or deposited and debits Bank when it clears, on the clearing date', function () {
    $cheque = (new Cheque)->forceFill(['direction' => 'received', 'customer_id' => $this->tenant->id, 'agreement_id' => $this->agreement->id, 'invoice_id' => $this->rent->id,
        'cheque_no' => '000123', 'bank_name' => 'NBB', 'cheque_date' => '2026-10-01', 'amount' => '440.000', 'status' => 'held', 'created_by' => $this->finance->id]);
    $cheque->save();
    $cheque->forceFill(['status' => 'deposited', 'deposited_on' => '2026-10-04'])->save();

    expect(Journal::query()->where('source_type', '<>', 'invoice')->count())->toBe(0);

    $payment = app(ClearCheque::class)->handle($this->finance, $cheque, '2026-10-05');

    expect(postedLines('payment', $payment->id))->toBe(['1010 440.000 0.000', '2300 0.000 440.000'])
        ->and(journalFor('payment', $payment->id)->date->toDateString())->toBe('2026-10-05');
});

test('credit applied to a later invoice moves on the day it is applied', function () {
    ($this->pay)(['method' => 'bank_transfer', 'amount' => '500.000']); // 60.000 stays as credit
    $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00', 'Asia/Bahrain'));
    $later = issuedInvoice($this->tenant, [['net' => '50.000', 'type' => 'utilities', 'au' => $this->au]], '2026-10-09', $this->agreement);
    $allocation = PaymentAllocation::query()->whereHas('line', fn ($q) => $q->where('invoice_id', $later->id))->sole();

    expect(postedLines('allocation', $allocation->id))->toBe(['2300 50.000 0.000', '1100 0.000 50.000'])
        ->and(journalFor('allocation', $allocation->id)->date->toDateString())->toBe('2026-10-09');
});

test('a reversed receipt posts the exact opposite on the reversal day, and so do its cut allocations', function () {
    $payment = ($this->pay)(['method' => 'cash', 'amount' => '500.000']);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Asia/Bahrain'));
    app(DecideApproval::class)->handle($this->management, app(RequestPaymentReversal::class)->handle($this->finance, $payment, 'Counterfeit notes'), true);
    $cut = PaymentAllocation::query()->where('payment_id', $payment->id)->whereNotNull('reverses_allocation_id')->sole();

    expect(postedLines('payment_reversal', $payment->id))->toBe(['1000 0.000 500.000', '2300 500.000 0.000'])
        ->and(journalFor('payment_reversal', $payment->id)->date->toDateString())->toBe('2026-10-08')
        ->and(postedLines('allocation', $cut->id))->toBe(['2300 0.000 440.000', '1100 440.000 0.000'])
        ->and(journalFor('allocation', $cut->id)->date->toDateString())->toBe('2026-10-08')
        ->and(ledgerBalances())->toBe([1100 => '440.000', 4000 => '-440.000']);
});

test('a deposit applied by a settlement leaves Tenant deposits held for the tenant\'s credit, then pays the named rent', function () {
    $deposit = issuedInvoice($this->tenant, [['net' => '400.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => $this->au]], '2025-12-01', $this->agreement, 'deposit');
    ($this->pay)(['method' => 'cash', 'amount' => '400.000', 'allocations' => [['invoice_id' => $deposit->id, 'amount' => '400.000']]]);
    $this->au->forceFill(['move_out_date' => '2026-10-04'])->save();
    $settlement = DB::transaction(fn () => app(CreateDepositSettlement::class)->handle($this->agreement, [$this->au->id], $this->finance));
    app(SaveSettlementDeductions::class)->handle($this->finance, $settlement, [
        ['agreement_unit_id' => $this->au->id, 'type' => 'unpaid_rent', 'description' => 'October rent', 'amount' => '300.000', 'invoice_line_id' => $this->rent->lines->sole()->id],
    ]);
    app(DecideApproval::class)->handle($this->management, app(SubmitDepositSettlement::class)->handle($this->finance, $settlement->fresh()), true);
    $payment = $settlement->fresh()->payment;

    expect($payment->method->value)->toBe('deposit_applied')
        ->and(postedLines('invoice', $deposit->id))->toBe(['1100 400.000 0.000', '2000 0.000 400.000'])
        ->and(postedLines('payment', $payment->id))->toBe(['2000 300.000 0.000', '2300 0.000 300.000'])
        ->and(postedLines('allocation', $payment->allocations()->sole()->id))->toBe(['2300 300.000 0.000', '1100 0.000 300.000']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Ledger/ReceiptPostingTest.php`
Expected: FAIL. No journal for `payment` (`postedLines` returns `[]`).

- [ ] **Step 3: Add the three source types to `app/Ledger/Sources.php`.**
  - Replace the `TYPES` constant with:

```php
    /** type => [table, own date (SQL over alias t), eligibility (SQL over t), joins payments as p] (plan ruling 3) */
    public const array TYPES = [
        'invoice' => ['invoices', 't.issue_date', "t.status = 'issued'", false],
        'payment' => ['payments', 't.received_on', '1 = 1', false],
        'payment_reversal' => ['payments', 'DATE(t.reversed_at)', "t.status = 'reversed'", false],
        // Made with the receipt (same day it was recorded): the receipt's date. Later (credit applied, a cut): the day it happened.
        'allocation' => ['payment_allocations', 'IF(t.amount > 0 AND DATE(t.posted_at) = DATE(p.posted_at), p.received_on, DATE(t.posted_at))', '1 = 1', true],
    ];
```

  - In `build()`, replace the `match` with:

```php
        return match ($type) {
            'invoice' => self::invoice($id, $rules),
            'payment' => self::payment($id, $rules),
            'allocation' => self::allocation($id, $rules),
            default => throw new LogicException("Unknown ledger source [{$type}]."),
        };
```

  - Add `use stdClass;` to the imports, and add these methods after `invoice()`:

```php
    /**
     * Rows 7–9: the method's account (one line per part of a split; the deposit's holder for a deposit applied) against
     * Tenant credit. Allocations then move the credit to receivables (row 10).
     *
     * @return Built
     */
    private static function payment(int $id, Rules $rules): array
    {
        $p = DB::table('payments')->where('id', $id)->first(['number', 'customer_id', 'method', 'amount']) ?? throw new LogicException("Payment {$id} is missing.");
        $customer = (int) $p->customer_id;
        $amount = self::fils($p->amount);

        /** @var list<Line> $lines */
        $lines = match ($p->method) {
            'split' => DB::table('payment_tenders')->where('payment_id', $id)->orderBy('id')->get(['method', 'amount'])
                ->map(fn (stdClass $t) => self::line($rules->account('method.'.$t->method), self::fils($t->amount), $customer))->values()->all(),
            'deposit_applied' => DB::table('deposit_movements as m')
                ->join('deposit_settlements as s', 's.id', '=', 'm.source_id')
                ->join('agreement_units as au', 'au.id', '=', 'm.agreement_unit_id')
                ->join('units as u', 'u.id', '=', 'au.unit_id')
                ->leftJoin('owner_contracts as c', 'c.id', '=', 'm.owner_contract_id')
                ->where('m.source_type', 'deposit_settlement')->where('m.type', 'applied')->where('s.payment_id', $id)->orderBy('m.id')
                ->get(['m.amount', 'c.type AS contract_type', 'c.deposits_held_by', 'c.owner_id', 'u.id AS unit_id', 'u.building_id'])
                ->map(fn (stdClass $m) => self::line(self::heldAccount($m->contract_type, $m->deposits_held_by, $rules), -self::fils($m->amount), $customer,
                    self::id($m->owner_id), self::id($m->building_id), self::id($m->unit_id)))->values()->all(),
            default => [self::line($rules->account('method.'.$p->method), $amount, $customer)],
        };
        $lines[] = self::line($rules->account('tenant_credit'), -$amount, $customer);

        return ['description' => (string) $p->number, 'lines' => $lines];
    }

    /**
     * Row 10: a payment applied to an invoice line moves the amount from Tenant credit to receivables; a negative row (a
     * reversal or a credit note's cut) moves it back.
     *
     * @return Built
     */
    private static function allocation(int $id, Rules $rules): array
    {
        $a = DB::table('payment_allocations as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id')
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->leftJoin('agreement_units as au', 'au.id', '=', 'l.agreement_unit_id')
            ->leftJoin('units as u', 'u.id', '=', DB::raw('COALESCE(l.unit_id, au.unit_id)'))
            ->leftJoin('owner_contracts as c', 'c.id', '=', 'a.owner_contract_id')
            ->where('a.id', $id)
            ->first(['a.amount', 'p.number AS payment', 'i.number AS invoice', 'p.customer_id', 'c.owner_id', 'u.id AS unit_id', 'u.building_id'])
            ?? throw new LogicException("Payment allocation {$id} is missing.");
        $amount = self::fils($a->amount);
        $dims = [(int) $a->customer_id, self::id($a->owner_id), self::id($a->building_id), self::id($a->unit_id)];

        return ['description' => "{$a->payment} → {$a->invoice}", 'lines' => [
            self::line($rules->account('tenant_credit'), $amount, ...$dims),
            self::line($rules->account('receivables'), -$amount, ...$dims),
        ]];
    }
```

- [ ] **Step 4: Watch the two models.** In `app/Ledger/Poster.php` add `use App\Models\Payment;` and `use App\Models\PaymentAllocation;` to the imports and replace the `WATCH` constant with:

```php
    /** model => the source types its saves may post (plan ruling 1) */
    public const array WATCH = [
        Invoice::class => ['invoice'],
        Payment::class => ['payment', 'payment_reversal'],
        PaymentAllocation::class => ['allocation'],
    ];
```

- [ ] **Step 5: Run the tests.**

Run: `php artisan test tests/Feature/Ledger tests/Feature/Payments tests/Feature/Cheques tests/Feature/Deposits`
Expected: PASS.

- [ ] **Step 6: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Ledger/Sources.php app/Ledger/Poster.php tests/Feature/Ledger/ReceiptPostingTest.php
git commit -m "$(cat <<'EOF'
General ledger: receipts by method and split part, applied payments, cleared cheques, deposits applied and payment reversals

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Expenses, payments out, owner charges, head-lease rent due, and the worked example

**Files:**
- Modify: `app/Ledger/Sources.php`, `app/Ledger/Poster.php`, `tests/Pest.php`
- Test: `tests/Feature/Ledger/MoneyOutPostingTest.php`

**Interfaces:**
- Consumes: Tasks 2–3; `RecordExpense`, `ReverseExpense`, `RecordDisbursement`, `PayDisbursement`, `RequestDisbursementReversal`, `CreateDepositSettlement`, `SubmitDepositSettlement`, `DecideApproval`, `OwnerCharge`, `OwnerPayable`.
- Produces:
  - Source types `expense`, `expense_reversal`, `disbursement`, `disbursement_reversal`, `owner_charge`, `owner_payable`, `owner_payable_cancel` (plan ruling 3, rows 13–23); `Poster::WATCH` entries for `Expense`, `Disbursement`, `OwnerCharge`, `OwnerPayable`.
  - Pest `ledgerFixture(): array` (plan ruling 9's worked example). It needs `Storage::fake('local')` and `RolesAndPermissionsSeeder` first, creates the `CompanySetting`, sets the start date, and ends on 20/10/2026 10:00. It returns keys `finance`, `management`, `admin`, `sara`, `omar`, `ali`, `tower`, `lease`, `t1`, `l1`, `managed`, `leased`, `saraAgreement`, `saraRent`, `saraDeposit`, `omarRent`, `saraPayment`, `companyExpense`, `ownerExpense`, `payable`, `headLease`, `statement`, `fee`, `refund`.

- [ ] **Step 1: Add the worked example to `tests/Pest.php`.** Add to the imports:

```php
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Expenses\RecordExpense;
use App\Models\CompanySetting;
use App\Models\Payment;
use Carbon\CarbonImmutable;
```

and append:

```php
/**
 * The general ledger worked example (plan ruling 9): October 2026, VAT 10%, ledger start 01/10/2026. Seed
 * RolesAndPermissionsSeeder and fake the local disk first. Ends on 20/10/2026 10:00.
 *  - Tower (TWR) unit T1, managed for Ali Owner, deposits held by the company: Sara's rent 400.000 + VAT 40.000 and her
 *    deposit 400.000, issued 01/10.
 *  - Lease House (LSE) unit L1, leased from Badr Owner at 300.000 a month: Omar's rent 300.000 (exempt), issued 01/10;
 *    the October payable, due 01/10.
 *  - 05/10 Sara pays 900.000 cash (840.000 applied, 60.000 credit); 06/10 Omar pays 300.000 by card.
 *  - 08/10 expenses: "Lobby lights" 50.000 (company, maintenance) and "T1 tap" 30.000 (charged to Ali).
 *  - 10/10 the head lease 300.000 paid by bank transfer; 15/10 Ali's management fee 20.000 + VAT 2.000;
 *    18/10 Sara's 60.000 credit refunded in cash.
 *
 * @return array{finance: User, management: User, admin: User, sara: Customer, omar: Customer, ali: Owner, tower: Building, lease: Building, t1: Unit, l1: Unit, managed: OwnerContract, leased: OwnerContract, saraAgreement: Agreement, saraRent: Invoice, saraDeposit: Invoice, omarRent: Invoice, saraPayment: Payment, companyExpense: Expense, ownerExpense: Expense, payable: OwnerPayable, headLease: Disbursement, statement: OwnerStatement, fee: OwnerCharge, refund: Disbursement}
 */
function ledgerFixture(): array
{
    $at = fn (string $when) => test()->travelTo(CarbonImmutable::parse($when, 'Asia/Bahrain'));
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $at('2026-10-01 09:00');
    app(EnsureNumberSequences::class)(2026);
    ledgerStart('2026-10-01');

    $tower = Building::factory()->create(['code' => 'TWR', 'name' => 'Tower']);
    $lease = Building::factory()->create(['code' => 'LSE', 'name' => 'Lease House']);
    $finance = matrixUser(RoleName::Finance, $tower);
    $finance->buildings()->attach($lease->id);
    $management = matrixUser(RoleName::Management, $tower);
    $admin = matrixUser(RoleName::Admin, $tower);
    $ali = Owner::factory()->create(['name_en' => 'Ali Owner']);
    $badr = Owner::factory()->create(['name_en' => 'Badr Owner']);
    $t1 = Unit::factory()->for($tower)->create(['code' => 'T1']);
    $l1 = Unit::factory()->for($lease)->create(['code' => 'L1']);
    $managed = activeOwnerContract(['owner_id' => $ali->id, 'building_id' => $tower->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'fee_type' => 'fixed', 'fee_value' => '20.000', 'expense_approval_limit' => null, 'deposits_held_by' => 'company', 'created_by' => $finance->id], [$t1]);
    $leased = activeOwnerContract(['owner_id' => $badr->id, 'building_id' => $lease->id, 'type' => 'leased', 'rent_amount' => '300.000', 'payment_frequency' => 'monthly',
        'fee_type' => null, 'fee_value' => null, 'expense_approval_limit' => null, 'deposits_held_by' => null, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'created_by' => $finance->id], [$l1]);
    $sara = Customer::factory()->create(['name_en' => 'Sara Tenant']);
    $omar = Customer::factory()->create(['name_en' => 'Omar Tenant']);
    $saraAgreement = activeAgreement(['customer_id' => $sara->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$t1]);
    $omarAgreement = activeAgreement(['customer_id' => $omar->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$l1]);
    $saraAu = $saraAgreement->agreementUnits->sole();
    $saraRent = issuedInvoice($sara, [['net' => '400.000', 'tax' => 'standard', 'au' => $saraAu]], '2026-10-01', $saraAgreement);
    $saraDeposit = issuedInvoice($sara, [['net' => '400.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => $saraAu]], '2026-10-01', $saraAgreement, 'deposit');
    $omarRent = issuedInvoice($omar, [['net' => '300.000', 'au' => $omarAgreement->agreementUnits->sole()]], '2026-10-01', $omarAgreement);

    $at('2026-10-05 10:00');
    $saraPayment = app(RecordPayment::class)->handle($finance, $sara, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '900.000']);
    $at('2026-10-06 10:00');
    app(RecordPayment::class)->handle($finance, $omar, ['received_on' => '2026-10-06', 'method' => 'card', 'amount' => '300.000']);

    $at('2026-10-08 10:00');
    $companyExpense = app(RecordExpense::class)->handle($finance, ['building_id' => $tower->id, 'category' => 'maintenance', 'description' => 'Lobby lights',
        'expense_date' => '2026-10-08', 'net' => '50.000', 'charge_to' => 'company']);
    $ownerExpense = app(RecordExpense::class)->handle($finance, ['building_id' => $tower->id, 'unit_id' => $t1->id, 'category' => 'maintenance', 'description' => 'T1 tap',
        'expense_date' => '2026-10-08', 'net' => '30.000', 'charge_to' => 'owner']);

    $at('2026-10-10 10:00');
    $payable = (new OwnerPayable)->forceFill(['owner_contract_id' => $leased->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'due_date' => '2026-10-01',
        'amount' => '300.000', 'status' => 'scheduled']);
    $payable->save();
    $headLease = app(RecordDisbursement::class)->handle($finance, ['purpose' => 'head_lease', 'owner_payable_id' => $payable->id, 'amount' => '300.000',
        'method' => 'bank_transfer', 'paid_on' => '2026-10-10']);

    $at('2026-10-15 10:00');
    $statement = (new OwnerStatement)->forceFill(['owner_contract_id' => $managed->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'cutoff_at' => '2026-09-30 23:59:59', 'status' => 'draft', 'created_by' => $finance->id]);
    $statement->save();
    $fee = OwnerCharge::create(['owner_contract_id' => $managed->id, 'owner_statement_id' => $statement->id, 'type' => 'management_fee', 'net' => '20.000',
        'tax_amount' => '2.000', 'amount' => '-22.000', 'posted_at' => '2026-10-15 10:00:00', 'created_by' => $finance->id]);

    $at('2026-10-18 10:00');
    $refund = app(RecordDisbursement::class)->handle($finance, ['purpose' => 'credit_refund', 'payment_id' => $saraPayment->id, 'amount' => '60.000',
        'method' => 'cash', 'paid_on' => '2026-10-18']);
    $at('2026-10-20 10:00');

    return ['finance' => $finance, 'management' => $management, 'admin' => $admin, 'sara' => $sara, 'omar' => $omar, 'ali' => $ali, 'tower' => $tower, 'lease' => $lease,
        't1' => $t1, 'l1' => $l1, 'managed' => $managed, 'leased' => $leased, 'saraAgreement' => $saraAgreement, 'saraRent' => $saraRent, 'saraDeposit' => $saraDeposit,
        'omarRent' => $omarRent, 'saraPayment' => $saraPayment, 'companyExpense' => $companyExpense, 'ownerExpense' => $ownerExpense, 'payable' => $payable,
        'headLease' => $headLease, 'statement' => $statement, 'fee' => $fee, 'refund' => $refund];
}
```

- [ ] **Step 2: Write the failing test** `tests/Feature/Ledger/MoneyOutPostingTest.php`:

```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Deposits\CreateDepositSettlement;
use App\Actions\Deposits\SubmitDepositSettlement;
use App\Actions\Disbursements\PayDisbursement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Expenses\ReverseExpense;
use App\Ledger\Poster;
use App\Models\Approval;
use App\Models\OwnerCharge;
use App\Models\OwnerPayable;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
});

test('the worked example: every record posts and the books show the plan\'s balances', function () {
    expect(ledgerBalances())->toBe([
        1000 => '840.000', 1010 => '-300.000', 1020 => '300.000', 2000 => '-400.000', 2100 => '-348.000', 2200 => '-80.000',
        2400 => '-42.000', 4000 => '-300.000', 4200 => '-20.000', 5000 => '300.000', 5100 => '50.000',
    ])->and(Poster::pendingCount())->toBe(0);
});

test('expenses, head-lease rent, the payout, the management fee and the credit refund each post their own journal', function () {
    $f = $this->f;

    expect(postedLines('expense', $f['companyExpense']->id))->toBe(['5100 50.000 0.000', '2200 0.000 50.000'])
        ->and(postedLines('expense', $f['ownerExpense']->id))->toBe(['2100 30.000 0.000', '2200 0.000 30.000'])
        ->and(journalFor('expense', $f['ownerExpense']->id)->lines->first()->only(['owner_id', 'building_id', 'unit_id']))
        ->toBe(['owner_id' => $f['ali']->id, 'building_id' => $f['tower']->id, 'unit_id' => $f['t1']->id])
        ->and(postedLines('owner_payable', $f['payable']->id))->toBe(['5000 300.000 0.000', '2100 0.000 300.000'])
        ->and(journalFor('owner_payable', $f['payable']->id)->date->toDateString())->toBe('2026-10-01')
        ->and(postedLines('disbursement', $f['headLease']->id))->toBe(['2100 300.000 0.000', '1010 0.000 300.000'])
        ->and(postedLines('owner_charge', $f['fee']->id))->toBe(['2100 22.000 0.000', '4200 0.000 20.000', '2400 0.000 2.000'])
        ->and(postedLines('disbursement', $f['refund']->id))->toBe(['2300 60.000 0.000', '1000 0.000 60.000'])
        ->and(journalFor('disbursement', $f['refund']->id)->date->toDateString())->toBe('2026-10-18');
});

test('a reversed expense and a reversed payment out post the exact opposite on the reversal day', function () {
    $f = $this->f;
    app(ReverseExpense::class)->handle($f['finance'], $f['companyExpense'], 'Entered twice');
    app(DecideApproval::class)->handle($f['management'], app(RequestDisbursementReversal::class)->handle($f['finance'], $f['refund'], 'Tenant never collected it'), true);

    expect(postedLines('expense_reversal', $f['companyExpense']->id))->toBe(['5100 0.000 50.000', '2200 50.000 0.000'])
        ->and(journalFor('expense_reversal', $f['companyExpense']->id)->date->toDateString())->toBe('2026-10-20')
        ->and(postedLines('disbursement_reversal', $f['refund']->id))->toBe(['2300 0.000 60.000', '1000 60.000 0.000']);
});

test('an expense charged to the tenant is the company\'s cost, and its recharge invoice is other charges income', function () {
    $f = $this->f;
    $f['t1']->forceFill(['default_tax_category' => 'standard'])->save();
    $expense = app(RecordExpense::class)->handle($f['finance'], ['building_id' => $f['tower']->id, 'unit_id' => $f['t1']->id, 'category' => 'cleaning',
        'description' => 'Carpet clean', 'expense_date' => '2026-10-20', 'net' => '25.000', 'charge_to' => 'tenant']);

    expect(postedLines('expense', $expense->id))->toBe(['5120 25.000 0.000', '2200 0.000 25.000'])
        ->and(postedLines('invoice', $expense->invoice_id))->toBe(['1100 27.500 0.000', '4100 0.000 25.000', '2400 0.000 2.500']);
});

test('a remittance debits Owner payable and an approved other payment out debits Other payments out', function () {
    $f = $this->f;
    DB::table('owner_statements')->where('id', $f['statement']->id)->update(['status' => 'pending_approval']);
    DB::table('owner_statements')->where('id', $f['statement']->id)->update(['status' => 'finalised', 'number' => 'OS-LEDGER-1', 'finalised_at' => now(), 'finalised_by' => $f['finance']->id]);
    $remittance = app(RecordDisbursement::class)->handle($f['finance'], ['purpose' => 'owner_remittance', 'owner_statement_id' => $f['statement']->id,
        'amount' => '100.000', 'method' => 'bank_transfer', 'paid_on' => '2026-10-20']);
    $other = app(RecordDisbursement::class)->handle($f['finance'], ['purpose' => 'other', 'payee_type' => 'owner', 'payee_id' => $f['ali']->id,
        'amount' => '15.000', 'method' => 'cash', 'reason' => 'Key cutting']);
    app(DecideApproval::class)->handle($f['management'], Approval::query()->latest('id')->firstOrFail(), true);
    app(PayDisbursement::class)->handle($f['finance'], $other->fresh(), ['method' => 'cash', 'paid_on' => '2026-10-20']);

    expect(postedLines('disbursement', $remittance->id))->toBe(['2100 100.000 0.000', '1010 0.000 100.000'])
        ->and(postedLines('disbursement', $other->id))->toBe(['5190 15.000 0.000', '1000 0.000 15.000']);
});

test('a deposit refund debits the deposits held for each unit it refunds', function () {
    $f = $this->f;
    $au = $f['saraAgreement']->agreementUnits->sole();
    $au->forceFill(['move_out_date' => '2026-10-19'])->save();
    $settlement = DB::transaction(fn () => app(CreateDepositSettlement::class)->handle($f['saraAgreement'], [$au->id], $f['finance']));
    app(DecideApproval::class)->handle($f['management'], app(SubmitDepositSettlement::class)->handle($f['finance'], $settlement->fresh()), true);
    $refund = app(RecordDisbursement::class)->handle($f['finance'], ['purpose' => 'deposit_refund', 'deposit_settlement_id' => $settlement->id,
        'amount' => '400.000', 'method' => 'bank_transfer', 'paid_on' => '2026-10-20']);

    expect(postedLines('disbursement', $refund->id))->toBe(['2000 400.000 0.000', '1010 0.000 400.000']);
});

test('an owner opening balance after the start date moves Opening balance equity to Owner payable', function () {
    $f = $this->f;
    $owed = OwnerCharge::create(['owner_contract_id' => $f['managed']->id, 'type' => 'opening_balance', 'net' => '75.000', 'tax_amount' => '0.000', 'amount' => '75.000',
        'posted_at' => '2026-10-20 08:00:00', 'created_by' => $f['finance']->id]);
    $owing = OwnerCharge::create(['owner_contract_id' => $f['managed']->id, 'type' => 'opening_balance', 'net' => '-5.000', 'tax_amount' => '0.000', 'amount' => '-5.000',
        'posted_at' => '2026-10-20 08:00:00', 'created_by' => $f['finance']->id]);

    expect(postedLines('owner_charge', $owed->id))->toBe(['3000 75.000 0.000', '2100 0.000 75.000'])
        ->and(postedLines('owner_charge', $owing->id))->toBe(['3000 0.000 5.000', '2100 5.000 0.000']);
});

test('head-lease rent posts on its due date; cancelled before then it never posts, cancelled after it is reversed', function () {
    $f = $this->f;
    $payable = function (string $from, string $to) use ($f): OwnerPayable {
        $p = (new OwnerPayable)->forceFill(['owner_contract_id' => $f['leased']->id, 'period_start' => $from, 'period_end' => $to, 'due_date' => $from,
            'amount' => '300.000', 'status' => 'scheduled']);
        $p->save();

        return $p;
    };
    $november = $payable('2026-11-01', '2026-11-30');
    $december = $payable('2026-12-01', '2026-12-31');
    expect(journalFor('owner_payable', $november->id))->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-01 00:30', 'Asia/Bahrain'));
    app(Poster::class)->catchUp();
    $december->forceFill(['status' => 'cancelled'])->save(); // before its due date
    expect(postedLines('owner_payable', $november->id))->toBe(['5000 300.000 0.000', '2100 0.000 300.000']);

    $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00', 'Asia/Bahrain'));
    $november->forceFill(['status' => 'cancelled'])->save(); // after its due date
    app(Poster::class)->catchUp();

    expect(journalFor('owner_payable', $december->id))->toBeNull()
        ->and(journalFor('owner_payable_cancel', $december->id))->toBeNull()
        ->and(postedLines('owner_payable_cancel', $november->id))->toBe(['5000 0.000 300.000', '2100 300.000 0.000'])
        ->and(journalFor('owner_payable_cancel', $november->id)->date->toDateString())->toBe('2026-12-03');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/Ledger/MoneyOutPostingTest.php`
Expected: FAIL. The worked example has no expense, payout, fee or payable journals.

- [ ] **Step 4: Add the seven source types to `app/Ledger/Sources.php`.**
  - Replace the `TYPES` constant with:

```php
    /** type => [table, own date (SQL over alias t), eligibility (SQL over t), joins payments as p] (plan ruling 3) */
    public const array TYPES = [
        'invoice' => ['invoices', 't.issue_date', "t.status = 'issued'", false],
        'payment' => ['payments', 't.received_on', '1 = 1', false],
        'payment_reversal' => ['payments', 'DATE(t.reversed_at)', "t.status = 'reversed'", false],
        // Made with the receipt (same day it was recorded): the receipt's date. Later (credit applied, a cut): the day it happened.
        'allocation' => ['payment_allocations', 'IF(t.amount > 0 AND DATE(t.posted_at) = DATE(p.posted_at), p.received_on, DATE(t.posted_at))', '1 = 1', true],
        'expense' => ['expenses', 't.expense_date', '1 = 1', false],
        'expense_reversal' => ['expenses', 'DATE(t.reversed_at)', "t.status = 'reversed'", false],
        'disbursement' => ['disbursements', 't.paid_on', "t.status IN ('paid', 'reversed')", false],
        'disbursement_reversal' => ['disbursements', 'DATE(t.reversed_at)', "t.status = 'reversed'", false],
        'owner_charge' => ['owner_charges', 'DATE(t.posted_at)', '1 = 1', false],
        // Due today or earlier; a payable cancelled before its due date never posts. Once cancelled it never changes again,
        // so updated_at is its cancellation time.
        'owner_payable' => ['owner_payables', 't.due_date', "(t.status <> 'cancelled' OR DATE(t.updated_at) >= t.due_date)", false],
        'owner_payable_cancel' => ['owner_payables', 'DATE(t.updated_at)', "t.status = 'cancelled' AND DATE(t.updated_at) >= t.due_date", false],
    ];
```

  - In `build()`, replace the `match` with:

```php
        return match ($type) {
            'invoice' => self::invoice($id, $rules),
            'payment' => self::payment($id, $rules),
            'allocation' => self::allocation($id, $rules),
            'expense' => self::expense($id, $rules),
            'disbursement' => self::disbursement($id, $rules),
            'owner_charge' => self::ownerCharge($id, $rules),
            'owner_payable' => self::ownerPayable($id, $rules),
            default => throw new LogicException("Unknown ledger source [{$type}]."),
        };
```

  - Add `use Carbon\CarbonImmutable;` to the imports, and add these methods after `allocation()`:

```php
    /**
     * Rows 13–15: the expense's total (VAT included, plan ruling 14) is a cost of the company (by category) or of the
     * owner, owed to the vendor. A tenant charge is the company's cost; its recharge invoice posts as an invoice.
     *
     * @return Built
     */
    private static function expense(int $id, Rules $rules): array
    {
        $e = DB::table('expenses as e')->leftJoin('owner_contracts as c', 'c.id', '=', 'e.owner_contract_id')->where('e.id', $id)
            ->first(['e.description', 'e.category', 'e.charge_to', 'e.total', 'e.building_id', 'e.unit_id', 'c.owner_id'])
            ?? throw new LogicException("Expense {$id} is missing.");
        $total = self::fils($e->total);
        $dims = [null, self::id($e->owner_id), self::id($e->building_id), self::id($e->unit_id)];
        $debit = $e->charge_to === 'owner' ? 'owner_payable' : 'category.'.$e->category;

        return ['description' => (string) $e->description, 'lines' => [
            self::line($rules->account($debit), $total, ...$dims),
            self::line($rules->account('vendor_payable'), -$total, ...$dims),
        ]];
    }

    /**
     * Rows 16–20: what the payment out settles (the owner, the tenant's credit, the deposit's holder per refunded unit, or
     * Other payments out) against the payout method's account.
     *
     * @return Built
     */
    private static function disbursement(int $id, Rules $rules): array
    {
        $d = DB::table('disbursements')->where('id', $id)->first(['number', 'payee_type', 'payee_id', 'purpose', 'amount', 'method', 'source_id'])
            ?? throw new LogicException("Payment out {$id} is missing.");
        $amount = self::fils($d->amount);
        $customer = $d->payee_type === 'customer' ? (int) $d->payee_id : null;
        $owner = $d->payee_type === 'owner' ? (int) $d->payee_id : null;
        $building = self::id(match ($d->purpose) {
            'owner_remittance' => DB::table('owner_statements as s')->join('owner_contracts as c', 'c.id', '=', 's.owner_contract_id')->where('s.id', $d->source_id)->value('c.building_id'),
            'head_lease' => DB::table('owner_payables as p')->join('owner_contracts as c', 'c.id', '=', 'p.owner_contract_id')->where('p.id', $d->source_id)->value('c.building_id'),
            default => null,
        });

        /** @var list<Line> $lines */
        $lines = match ($d->purpose) {
            'owner_remittance', 'head_lease' => [self::line($rules->account('owner_payable'), $amount, null, $owner, $building)],
            'credit_refund' => [self::line($rules->account('tenant_credit'), $amount, $customer)],
            'deposit_refund' => DB::table('deposit_movements as m')
                ->join('agreement_units as au', 'au.id', '=', 'm.agreement_unit_id')
                ->join('units as u', 'u.id', '=', 'au.unit_id')
                ->leftJoin('owner_contracts as c', 'c.id', '=', 'm.owner_contract_id')
                ->where('m.source_type', 'disbursement')->where('m.source_id', $id)->where('m.type', 'refunded')->orderBy('m.id')
                ->get(['m.amount', 'c.type AS contract_type', 'c.deposits_held_by', 'c.owner_id', 'u.id AS unit_id', 'u.building_id'])
                ->map(fn (stdClass $m) => self::line(self::heldAccount($m->contract_type, $m->deposits_held_by, $rules), -self::fils($m->amount), $customer,
                    self::id($m->owner_id), self::id($m->building_id), self::id($m->unit_id)))->values()->all(),
            default => [self::line($rules->account('other_payments_out'), $amount, $customer, $owner)],
        };
        $lines[] = self::line($rules->account('method.'.$d->method), -$amount, $customer, $owner, $building);

        return ['description' => (string) $d->number, 'lines' => $lines];
    }

    /**
     * Rows 21–22: a management fee is owed by the owner (net + VAT) as fee income and VAT; an opening balance moves
     * Opening balance equity to the owner (amount + = the company owes the owner).
     *
     * @return Built
     */
    private static function ownerCharge(int $id, Rules $rules): array
    {
        $c = DB::table('owner_charges as oc')->join('owner_contracts as c', 'c.id', '=', 'oc.owner_contract_id')->where('oc.id', $id)
            ->first(['oc.type', 'oc.net', 'oc.tax_amount', 'oc.amount', 'c.number', 'c.owner_id', 'c.building_id'])
            ?? throw new LogicException("Owner charge {$id} is missing.");
        $amount = self::fils($c->amount);
        $dims = [null, (int) $c->owner_id, (int) $c->building_id];

        $lines = $c->type === 'management_fee'
            ? [
                self::line($rules->account('owner_payable'), -$amount, ...$dims),
                self::line($rules->account('fee_income'), -self::fils($c->net), ...$dims),
                self::line($rules->account('vat_payable'), -self::fils($c->tax_amount), ...$dims),
            ]
            : [
                self::line($rules->account('opening_equity'), $amount, ...$dims),
                self::line($rules->account('owner_payable'), -$amount, ...$dims),
            ];

        return ['description' => (string) $c->number, 'lines' => $lines];
    }

    /**
     * Row 23: a leased contract's rent for a period is the company's cost and owed to the owner from its due date.
     *
     * @return Built
     */
    private static function ownerPayable(int $id, Rules $rules): array
    {
        $p = DB::table('owner_payables as p')->join('owner_contracts as c', 'c.id', '=', 'p.owner_contract_id')->where('p.id', $id)
            ->first(['p.amount', 'p.period_start', 'p.period_end', 'c.number', 'c.owner_id', 'c.building_id'])
            ?? throw new LogicException("Owner payable {$id} is missing.");
        $amount = self::fils($p->amount);
        $dims = [null, (int) $p->owner_id, (int) $p->building_id];
        $period = CarbonImmutable::parse((string) $p->period_start)->format('d/m/Y').'–'.CarbonImmutable::parse((string) $p->period_end)->format('d/m/Y');

        return ['description' => trim($p->number.' '.$period), 'lines' => [
            self::line($rules->account('lease_rent'), $amount, ...$dims),
            self::line($rules->account('owner_payable'), -$amount, ...$dims),
        ]];
    }
```

- [ ] **Step 5: Watch the four models.** In `app/Ledger/Poster.php` add `use App\Models\Disbursement;`, `use App\Models\Expense;`, `use App\Models\OwnerCharge;` and `use App\Models\OwnerPayable;` to the imports and replace the `WATCH` constant with:

```php
    /** model => the source types its saves may post (plan ruling 1) */
    public const array WATCH = [
        Invoice::class => ['invoice'],
        Payment::class => ['payment', 'payment_reversal'],
        PaymentAllocation::class => ['allocation'],
        Expense::class => ['expense', 'expense_reversal'],
        Disbursement::class => ['disbursement', 'disbursement_reversal'],
        OwnerCharge::class => ['owner_charge'],
        OwnerPayable::class => ['owner_payable', 'owner_payable_cancel'],
    ];
```

- [ ] **Step 6: Run the tests.**

Run: `php artisan test tests/Feature/Ledger tests/Feature/Expenses tests/Feature/Disbursements tests/Feature/OwnerStatements tests/Feature/OwnerContracts`
Expected: PASS.

- [ ] **Step 7: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Ledger/Sources.php app/Ledger/Poster.php tests/Pest.php tests/Feature/Ledger/MoneyOutPostingTest.php
git commit -m "$(cat <<'EOF'
General ledger: expenses, payments out of every purpose, management fees with VAT, owner opening balances, head-lease rent due, their reversals, and the worked example

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Locking, late posting, rebuild in the background, and the nightly run

**Files:**
- Create: `app/Actions/Ledger/LockLedger.php`, `app/Actions/Ledger/UnlockLedger.php`, `app/Actions/Ledger/RebuildLedger.php`, `app/Jobs/RebuildLedgerJob.php`, `app/Console/Commands/LedgerNightlyCommand.php`
- Modify: `routes/console.php`, `config/services.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/LockAndRebuildTest.php`

**Interfaces:**
- Consumes: `Poster::catchUp()`, `Poster::pendingCount()`, `Poster::write()`, `LedgerSetting::firstOpenDay()`, `rebuilding()`, `Audit::log()`, `ledgerFixture()`.
- Produces:
  - `LockLedger::handle(User $actor, mixed $date): LedgerSetting`: needs `ledger.manage`; errors on `lockDate`; audit `ledger.locked`.
  - `UnlockLedger::handle(User $actor, mixed $date): LedgerSetting`: needs `ledger.admin`; `$date` null or empty unlocks everything; errors on `unlockDate`; audit `ledger.unlocked`.
  - `RebuildLedger::handle(User $actor, mixed $fromMonth, mixed $toMonth): array{from: string, to: string}`: needs `ledger.manage` or `ledger.admin`; errors on `fromMonth`, `toMonth` and `rebuild`; audit `ledger.rebuild_requested`.
  - `RebuildLedger::queue(LedgerSetting $locked, string $from, string $to, User $actor): void`, used by SetLedgerStart in Task 6.
  - `RebuildLedgerJob(string $from, string $to, int $userId)`: audit `ledger.rebuilt`.
  - Command `rms:ledger:nightly`, scheduled daily at 00:30.

- [ ] **Step 1: Write the failing test** `tests/Feature/Ledger/LockAndRebuildTest.php`:

```php
<?php

use App\Actions\Expenses\RecordExpense;
use App\Actions\Ledger\LockLedger;
use App\Actions\Ledger\RebuildLedger;
use App\Actions\Ledger\UnlockLedger;
use App\Enums\RoleName;
use App\Ledger\Poster;
use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Models\OwnerPayable;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
});

/** Every line with its journal's source, date and late flag, without ids: two ledgers built alike compare equal. */
function ledgerSnapshot(): array
{
    return DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
        ->orderBy('j.kind')->orderBy('j.source_type')->orderBy('j.source_id')->orderBy('a.code')->orderBy('l.debit')->orderBy('l.credit')
        ->get(['j.kind', 'j.source_type', 'j.source_id', 'j.date', 'j.late_posting', 'a.code', 'l.debit', 'l.credit', 'l.customer_id', 'l.owner_id', 'l.building_id', 'l.unit_id'])
        ->map(fn ($r) => (array) $r)->all();
}

test('Finance locks forward, only Admin unlocks, and both are audited', function () {
    $f = $this->f;
    app(LockLedger::class)->handle($f['finance'], '2026-10-10');

    expect(LedgerSetting::current()->locked_until->toDateString())->toBe('2026-10-10')
        ->and(fn () => app(LockLedger::class)->handle($f['finance'], '2026-10-09'))->toThrow(ValidationException::class, 'already locked')
        ->and(fn () => app(LockLedger::class)->handle($f['finance'], '2026-10-21'))->toThrow(ValidationException::class)
        ->and(fn () => app(LockLedger::class)->handle($f['finance'], ['2026-10-12']))->toThrow(ValidationException::class)
        ->and(fn () => app(LockLedger::class)->handle($f['admin'], '2026-10-12'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UnlockLedger::class)->handle($f['finance'], null))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UnlockLedger::class)->handle($f['admin'], '2026-09-30'))->toThrow(ValidationException::class);

    app(UnlockLedger::class)->handle($f['admin'], '2026-10-05');
    expect(LedgerSetting::current()->locked_until->toDateString())->toBe('2026-10-05');
    app(UnlockLedger::class)->handle($f['admin'], '');

    expect(LedgerSetting::current()->locked_until)->toBeNull()
        ->and(Activity::query()->where('event', 'ledger.locked')->sole()->causer_id)->toBe($f['finance']->id)
        ->and(Activity::query()->where('event', 'ledger.unlocked')->count())->toBe(2);
});

test('a locked month never changes: its journals cannot be deleted and nothing is rebuilt in it', function () {
    $f = $this->f;
    app(LockLedger::class)->handle($f['finance'], '2026-10-10');
    $journal = journalFor('expense', $f['companyExpense']->id);

    expect(fn () => $journal->lines()->delete())->toThrow(QueryException::class, 'locked')
        ->and(fn () => app(RebuildLedger::class)->handle($f['finance'], '2026-09', '2026-09'))->toThrow(ValidationException::class, 'Nothing to rebuild');
});

test('a record dated in a locked month posts on the first open day, marked late', function () {
    $f = $this->f;
    app(LockLedger::class)->handle($f['finance'], '2026-10-10');
    $expense = app(RecordExpense::class)->handle($f['finance'], ['building_id' => $f['tower']->id, 'category' => 'utilities', 'description' => 'Late bill',
        'expense_date' => '2026-10-04', 'net' => '12.000', 'charge_to' => 'company']);
    $journal = journalFor('expense', $expense->id);

    expect($journal->date->toDateString())->toBe('2026-10-11')
        ->and($journal->late_posting)->toBeTrue()
        ->and(postedLines('expense', $expense->id))->toBe(['5110 12.000 0.000', '2200 0.000 12.000']);
});

test('rebuilding an open month gives the same journals as live posting, keeps manual journals, and reports its progress', function () {
    $f = $this->f;
    $line = fn (string $code, int $amount) => ['account' => (int) LedgerAccount::query()->where('code', $code)->value('id'), 'amount' => $amount,
        'customer_id' => null, 'owner_id' => null, 'building_id' => null, 'unit_id' => null, 'memo' => null];
    $manual = Poster::write('manual', '2026-10-19', 'Bank charges', [$line('5190', 2_000), $line('1010', -2_000)], createdBy: $f['finance']->id);
    $before = ledgerSnapshot();
    $old = Journal::query()->where('kind', 'auto')->pluck('id');

    expect(app(RebuildLedger::class)->handle($f['finance'], '2026-10', '2026-10'))->toBe(['from' => '2026-10-01', 'to' => '2026-10-20']);

    $settings = LedgerSetting::current();
    expect(ledgerSnapshot())->toBe($before)
        ->and(Journal::query()->whereKey($old)->exists())->toBeFalse()
        ->and(Journal::query()->whereKey($manual->id)->exists())->toBeTrue()
        ->and($settings->rebuild_status)->toBe('done')
        ->and($settings->rebuild_total)->toBe($old->count())
        ->and($settings->rebuild_done)->toBe($old->count())
        ->and($settings->rebuild_from->toDateString())->toBe('2026-10-01')
        ->and(Activity::query()->where('event', 'ledger.rebuild_requested')->sole()->causer_id)->toBe($f['finance']->id)
        ->and(Activity::query()->where('event', 'ledger.rebuilt')->sole()->causer_id)->toBe($f['finance']->id);
});

test('a rebuild skips locked days, refuses while another runs, and needs Finance or Admin', function () {
    $f = $this->f;
    app(LockLedger::class)->handle($f['finance'], '2026-10-10');
    $locked = journalFor('expense', $f['companyExpense']->id)->id;

    expect(app(RebuildLedger::class)->handle($f['admin'], '2026-10', '2026-12'))->toBe(['from' => '2026-10-11', 'to' => '2026-10-20'])
        ->and(Journal::query()->whereKey($locked)->exists())->toBeTrue();

    LedgerSetting::current()->forceFill(['rebuild_status' => 'running'])->save();
    expect(fn () => app(RebuildLedger::class)->handle($f['finance'], '2026-10', '2026-10'))->toThrow(ValidationException::class, 'already running')
        ->and(fn () => app(LockLedger::class)->handle($f['finance'], '2026-10-12'))->toThrow(ValidationException::class, 'rebuild')
        ->and(fn () => app(RebuildLedger::class)->handle(matrixUser(RoleName::Leasing, $f['tower']), '2026-10', '2026-10'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RebuildLedger::class)->handle($f['admin'], '2026-11', '2026-10'))->toThrow(ValidationException::class);
});

test('the nightly run posts head-lease rent on its due date, and runs at 00:30', function () {
    $f = $this->f;
    $november = (new OwnerPayable)->forceFill(['owner_contract_id' => $f['leased']->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30',
        'due_date' => '2026-11-01', 'amount' => '300.000', 'status' => 'scheduled']);
    $november->save();
    expect(journalFor('owner_payable', $november->id))->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-01 00:30', 'Asia/Bahrain'));
    $this->artisan('rms:ledger:nightly')->assertExitCode(0);

    expect(journalFor('owner_payable', $november->id)->date->toDateString())->toBe('2026-11-01')
        ->and(scheduledEvent('rms:ledger:nightly')->expression)->toBe('30 0 * * *');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Ledger/LockAndRebuildTest.php`
Expected: FAIL. `App\Actions\Ledger\LockLedger` does not exist.

- [ ] **Step 3: Create `app/Actions/Ledger/LockLedger.php`:**

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\LedgerSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Spec §5 (plan ruling 5): Finance moves "Locked up to" forward. Nothing on or before it is posted, rebuilt or changed. */
final class LockLedger
{
    public function handle(User $actor, mixed $date): LedgerSetting
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        Validator::make(['lockDate' => $date], [
            'lockDate' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
        ])->validate();
        $date = (string) $date;

        return DB::transaction(function () use ($actor, $date) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            $fail = fn (string $message) => throw ValidationException::withMessages(['lockDate' => $message]);
            if ($settings->start_date === null) {
                $fail(__('Set the ledger start date first.'));
            }
            if ($settings->start_date !== null && $date < $settings->start_date->toDateString()) {
                $fail(__('Lock a date on or after the start date, :date.', ['date' => $settings->start_date->format('d/m/Y')]));
            }
            if ($settings->locked_until !== null && $date <= $settings->locked_until->toDateString()) {
                $fail(__('The ledger is already locked up to :date.', ['date' => $settings->locked_until->format('d/m/Y')]));
            }
            if ($settings->rebuilding()) {
                $fail(__('Wait for the rebuild to finish.'));
            }

            $old = $settings->locked_until?->toDateString();
            $settings->forceFill(['locked_until' => $date])->save();
            Audit::log('ledger.locked', $settings, ['locked_until' => $old], ['locked_until' => $date], causer: $actor);

            return $settings;
        });
    }
}
```

- [ ] **Step 4: Create `app/Actions/Ledger/UnlockLedger.php`:**

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\LedgerSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Spec §5: Admin moves "Locked up to" back to an earlier date (on or after the start date), or clears it. Audited. */
final class UnlockLedger
{
    public function handle(User $actor, mixed $date): LedgerSetting
    {
        if (! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }
        Validator::make(['unlockDate' => $date], ['unlockDate' => ['nullable', 'string', 'date_format:Y-m-d']])->validate();
        $date = filled($date) ? (string) $date : null;

        return DB::transaction(function () use ($actor, $date) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            $fail = fn (string $message) => throw ValidationException::withMessages(['unlockDate' => $message]);
            if ($settings->locked_until === null || $settings->start_date === null) {
                $fail(__('The ledger is not locked.'));
            }
            if ($settings->locked_until !== null && $settings->start_date !== null && $date !== null
                && ($date >= $settings->locked_until->toDateString() || $date < $settings->start_date->toDateString())) {
                $fail(__('Choose a date before :locked and on or after the start date, or leave it empty to unlock everything.', ['locked' => $settings->locked_until->format('d/m/Y')]));
            }
            if ($settings->rebuilding()) {
                $fail(__('Wait for the rebuild to finish.'));
            }

            $old = $settings->locked_until?->toDateString();
            $settings->forceFill(['locked_until' => $date])->save();
            Audit::log('ledger.unlocked', $settings, ['locked_until' => $old], ['locked_until' => $date], causer: $actor);

            return $settings;
        });
    }
}
```

- [ ] **Step 5: Create `app/Actions/Ledger/RebuildLedger.php`:**

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Jobs\RebuildLedgerJob;
use App\Models\LedgerSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Spec §5 (plan ruling 4): Finance or Admin rebuild whole months in the background. The range is clamped to open days:
 * from the later of the first day, the start date and the day after the lock, to the earlier of the last day and today.
 */
final class RebuildLedger
{
    /** @return array{from: string, to: string} the days actually rebuilt */
    public function handle(User $actor, mixed $fromMonth, mixed $toMonth): array
    {
        if (! $actor->can(PermissionName::LedgerManage) && ! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }
        Validator::make(['fromMonth' => $fromMonth, 'toMonth' => $toMonth], [
            'fromMonth' => ['required', 'string', 'date_format:Y-m'],
            'toMonth' => ['required', 'string', 'date_format:Y-m', 'after_or_equal:fromMonth'],
        ])->validate();

        return DB::transaction(function () use ($actor, $fromMonth, $toMonth) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            $first = $settings->firstOpenDay();
            if ($first === null) {
                throw ValidationException::withMessages(['rebuild' => __('Set the ledger start date first.')]);
            }
            if ($settings->rebuilding()) {
                throw ValidationException::withMessages(['rebuild' => __('A rebuild is already running.')]);
            }
            $from = CarbonImmutable::parse($fromMonth.'-01', 'Asia/Bahrain')->max($first)->toDateString();
            $to = CarbonImmutable::parse($toMonth.'-01', 'Asia/Bahrain')->endOfMonth()->min(now('Asia/Bahrain'))->toDateString();
            if ($from > $to) {
                throw ValidationException::withMessages(['rebuild' => __('Nothing to rebuild: those months are locked, before the start date or still to come.')]);
            }

            self::queue($settings, $from, $to, $actor);
            Audit::log('ledger.rebuild_requested', $settings, new: ['from' => $from, 'to' => $to], causer: $actor);

            return ['from' => $from, 'to' => $to];
        });
    }

    /** Marks a rebuild queued and dispatches it after commit. Internal: the caller holds the settings lock and checked rebuilding(). */
    public static function queue(LedgerSetting $locked, string $from, string $to, User $actor): void
    {
        $locked->forceFill(['rebuild_status' => 'queued', 'rebuild_from' => $from, 'rebuild_to' => $to, 'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null])->save();
        $userId = $actor->id;
        DB::afterCommit(fn () => RebuildLedgerJob::dispatch($from, $to, $userId));
    }
}
```

- [ ] **Step 6: Create `app/Jobs/RebuildLedgerJob.php`:**

```php
<?php

namespace App\Jobs;

use App\Audit\Audit;
use App\Ledger\Poster;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\LedgerSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Spec §5 (plan ruling 4): deletes the range's automatic journals 500 at a time, then posts every pending source, writing
 * progress on the settings row. Manual and opening journals are never touched. Each deletion chunk takes the settings
 * lock, so live posting, locking and the rebuild never interleave inside a chunk.
 */
class RebuildLedgerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public string $from, public string $to, public int $userId) {}

    public function handle(Poster $poster): void
    {
        try {
            self::progress(['rebuild_status' => 'running']);
            do {
                $deleted = DB::transaction(function () {
                    LedgerSetting::query()->lockForUpdate()->firstOrFail();
                    $ids = Journal::query()->where('kind', 'auto')->whereBetween('date', [$this->from, $this->to])->orderBy('id')->limit(500)->pluck('id');
                    JournalLine::query()->whereIn('journal_id', $ids)->delete();
                    Journal::query()->whereKey($ids)->delete();

                    return $ids->count();
                });
            } while ($deleted > 0);

            self::progress(['rebuild_total' => Poster::pendingCount()]);
            $poster->catchUp(fn (int $done) => self::progress(['rebuild_done' => $done]));
            self::progress(['rebuild_status' => 'done']);
            Audit::log('ledger.rebuilt', LedgerSetting::current(), new: ['from' => $this->from, 'to' => $this->to], causer: User::query()->find($this->userId));
        } catch (Throwable $e) {
            report($e);
            self::progress(['rebuild_status' => 'failed', 'rebuild_message' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    /** @param  array<string, mixed>  $values */
    private static function progress(array $values): void
    {
        LedgerSetting::query()->whereKey(LedgerSetting::current()->id)->update($values);
    }
}
```

- [ ] **Step 7: Create `app/Console/Commands/LedgerNightlyCommand.php`:**

```php
<?php

namespace App\Console\Commands;

use App\Ledger\Poster;
use Illuminate\Console\Command;

/** Plan rulings 1 and 8: posts what fell due (head-lease rent due today) or failed live. */
class LedgerNightlyCommand extends Command
{
    protected $signature = 'rms:ledger:nightly';

    protected $description = 'Post pending ledger sources';

    public function handle(Poster $poster): int
    {
        $tried = $poster->catchUp();
        $this->info("Posted {$tried} pending ledger sources.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 8: Schedule it.**
  - In `routes/console.php`, after the `rms:integrity-check` entry, add:

```php

Schedule::command('rms:ledger:nightly')->dailyAt('00:30')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.ledger')), (string) $url);
```

  - In `config/services.php`, after `'tenant_reminders' => env('HEARTBEAT_TENANT_REMINDERS'),` add `'ledger' => env('HEARTBEAT_LEDGER'),`.

- [ ] **Step 9: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Set the ledger start date first.": "حدد تاريخ بدء الدفاتر أولاً.",
    "Lock a date on or after the start date, :date.": "اختر تاريخ إقفال في تاريخ البدء :date أو بعده.",
    "The ledger is already locked up to :date.": "الدفاتر مقفلة بالفعل حتى :date.",
    "Wait for the rebuild to finish.": "انتظر حتى تنتهي إعادة البناء.",
    "The ledger is not locked.": "الدفاتر غير مقفلة.",
    "Choose a date before :locked and on or after the start date, or leave it empty to unlock everything.": "اختر تاريخاً قبل :locked وفي تاريخ البدء أو بعده، أو اتركه فارغاً لفتح كل الفترات.",
    "A rebuild is already running.": "إعادة بناء قيد التنفيذ بالفعل.",
    "Nothing to rebuild: those months are locked, before the start date or still to come.": "لا شيء لإعادة بنائه: هذه الأشهر مقفلة أو قبل تاريخ البدء أو لم تأتِ بعد.",
```

- [ ] **Step 10: Run the tests.**

Run: `php artisan test tests/Feature/Ledger`
Expected: PASS. The rebuild runs inside the test because the queue is `sync` in `phpunit.xml`.

- [ ] **Step 11: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Actions/Ledger/LockLedger.php app/Actions/Ledger/UnlockLedger.php app/Actions/Ledger/RebuildLedger.php app/Jobs/RebuildLedgerJob.php app/Console/Commands/LedgerNightlyCommand.php routes/console.php config/services.php tests/Feature/Ledger/LockAndRebuildTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
General ledger: lock forward (Finance) and unlock (Admin), late postings on the first open day, background rebuild with progress, nightly catch-up at 00:30

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Admin setup: accounts, posting rules, start date and opening balances

**Files:**
- Create: `app/Actions/Ledger/SaveLedgerAccount.php`, `SetLedgerAccountActive.php`, `DeleteLedgerAccount.php`, `SaveLedgerRules.php`, `SetLedgerStart.php`, `SaveOpeningBalances.php` (all in `app/Actions/Ledger/`)
- Create: `app/Livewire/Ledger/Accounts.php`, `app/Livewire/Ledger/Setup.php`, `resources/views/livewire/ledger/accounts.blade.php`, `resources/views/livewire/ledger/setup.blade.php`, `routes/ledger.php`
- Modify: `routes/web.php`, `resources/views/layouts/app/sidebar.blade.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/LedgerSetupTest.php`

**Interfaces:**
- Consumes: `Rules::keys()`, `Rules::label()`, `Rules::load()`, `Poster::write()`, `RebuildLedger::handle()`, `RebuildLedger::queue()`, `LockLedger`, `ledgerFixture()`, `linesOf()`, `postedLines()`, `ledgerBalances()`.
- Produces (every action needs `ledger.admin`):
  - `SaveLedgerAccount::handle(User $actor, ?LedgerAccount $account, array $data): LedgerAccount` (`code`, `name_en`, `name_ar`, `type`).
  - `SetLedgerAccountActive::handle(User $actor, LedgerAccount $account, bool $active): void`.
  - `DeleteLedgerAccount::handle(User $actor, LedgerAccount $account): void`.
  - `SaveLedgerRules::handle(User $actor, array $map): bool` (rule key ⇒ account id; true when something changed).
  - `SetLedgerStart::handle(User $actor, mixed $date): void` (errors on `startDate`).
  - `SaveOpeningBalances::handle(User $actor, mixed $balances): ?Journal` (account id ⇒ `['debit' => string, 'credit' => string]`; errors on `balances` and `balances.<id>.debit|credit`).
  - Routes `ledger.accounts`, `ledger.setup`; the sidebar's "Ledger" group.

- [ ] **Step 1: Write the failing test** `tests/Feature/Ledger/LedgerSetupTest.php`:

```php
<?php

use App\Actions\Ledger\DeleteLedgerAccount;
use App\Actions\Ledger\LockLedger;
use App\Actions\Ledger\SaveLedgerAccount;
use App\Actions\Ledger\SaveOpeningBalances;
use App\Actions\Ledger\SetLedgerAccountActive;
use App\Actions\Ledger\SetLedgerStart;
use App\Actions\Payments\RecordPayment;
use App\Ledger\Poster;
use App\Ledger\Rules;
use App\Livewire\Ledger\Accounts;
use App\Livewire\Ledger\Setup;
use App\Models\Journal;
use App\Models\LedgerAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
    $this->id = fn (string $code) => (int) LedgerAccount::query()->where('code', $code)->value('id');
});

test('Admin adds and renames accounts; an account with postings keeps its type; codes are unique; Finance cannot', function () {
    $f = $this->f;
    $parking = app(SaveLedgerAccount::class)->handle($f['admin'], null, ['code' => '4300', 'name_en' => 'Parking income', 'name_ar' => 'إيرادات المواقف', 'type' => 'income']);
    app(SaveLedgerAccount::class)->handle($f['admin'], $parking, ['code' => '4300', 'name_en' => 'Car park income', 'name_ar' => 'إيرادات المواقف', 'type' => 'expense']);
    $cash = LedgerAccount::query()->where('code', '1000')->firstOrFail();

    expect($parking->fresh()->name_en)->toBe('Car park income')
        ->and($parking->fresh()->type->value)->toBe('expense')
        ->and(fn () => app(SaveLedgerAccount::class)->handle($f['admin'], $cash, ['code' => '1000', 'name_en' => 'Cash', 'name_ar' => 'نقد', 'type' => 'expense']))->toThrow(ValidationException::class, 'postings')
        ->and(fn () => app(SaveLedgerAccount::class)->handle($f['admin'], null, ['code' => '1000', 'name_en' => 'Twice', 'name_ar' => 'مكرر', 'type' => 'asset']))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveLedgerAccount::class)->handle($f['admin'], null, ['code' => '9 9', 'name_en' => 'Bad', 'name_ar' => 'سيء', 'type' => 'asset']))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveLedgerAccount::class)->handle($f['finance'], null, ['code' => '9999', 'name_en' => 'X', 'name_ar' => 'س', 'type' => 'asset']))->toThrow(AuthorizationException::class);
});

test('a rule\'s account cannot be deactivated or deleted; an inactive account takes no postings; an unused one can go', function () {
    $f = $this->f;
    $cash = LedgerAccount::query()->where('code', '1000')->firstOrFail();
    $petty = app(SaveLedgerAccount::class)->handle($f['admin'], null, ['code' => '1090', 'name_en' => 'Petty cash', 'name_ar' => 'نثرية', 'type' => 'asset']);
    $line = fn (int $account, int $amount) => ['account' => $account, 'amount' => $amount, 'customer_id' => null, 'owner_id' => null, 'building_id' => null, 'unit_id' => null, 'memo' => null];

    expect(fn () => app(SetLedgerAccountActive::class)->handle($f['admin'], $cash, false))->toThrow(ValidationException::class, 'Posting rules')
        ->and(fn () => app(DeleteLedgerAccount::class)->handle($f['admin'], $cash))->toThrow(ValidationException::class);

    app(SetLedgerAccountActive::class)->handle($f['admin'], $petty, false);
    expect(fn () => Poster::write('manual', '2026-10-20', 'Test', [$line($petty->id, 1_000), $line($cash->id, -1_000)]))->toThrow(LogicException::class, 'inactive');

    app(DeleteLedgerAccount::class)->handle($f['admin'], $petty);
    expect(LedgerAccount::query()->whereKey($petty->id)->exists())->toBeFalse();
});

test('a rule change applies to new postings, and rebuilding the open months restates the old ones', function () {
    $f = $this->f;
    $terminal = app(SaveLedgerAccount::class)->handle($f['admin'], null, ['code' => '1030', 'name_en' => 'Card terminal', 'name_ar' => 'جهاز البطاقات', 'type' => 'asset']);
    $index = array_search('method.card', Rules::keys(), true);
    $page = Livewire::actingAs($f['admin'])->test(Accounts::class)
        ->set("ruleAccounts.{$index}", (string) $terminal->id)->call('saveRules')->assertHasNoErrors()->assertSet('offerRebuild', true);

    $later = app(RecordPayment::class)->handle($f['finance'], $f['omar'], ['received_on' => '2026-10-20', 'method' => 'card', 'amount' => '10.000']);
    expect(postedLines('payment', $later->id))->toBe(['1030 10.000 0.000', '2300 0.000 10.000'])
        ->and(ledgerBalances()[1020])->toBe('300.000');

    $page->call('rebuildOpen')->assertHasNoErrors();
    expect(ledgerBalances())->not->toHaveKey(1020)
        ->and(ledgerBalances()[1030])->toBe('310.000');
});

test('the start date moves only while nothing is locked, and moving it rebuilds from the earlier date', function () {
    $f = $this->f;
    $before = ledgerBalances();
    app(SetLedgerStart::class)->handle($f['admin'], '2026-10-06');

    expect(journalFor('invoice', $f['saraRent']->id))->toBeNull()
        ->and(journalFor('payment', $f['saraPayment']->id))->toBeNull()
        ->and(postedLines('expense', $f['companyExpense']->id))->toBe(['5100 50.000 0.000', '2200 0.000 50.000']);

    app(SetLedgerStart::class)->handle($f['admin'], '2026-10-01');
    expect(ledgerBalances())->toBe($before);

    app(LockLedger::class)->handle($f['finance'], '2026-10-05');
    expect(fn () => app(SetLedgerStart::class)->handle($f['admin'], '2026-10-02'))->toThrow(ValidationException::class, 'no month is locked')
        ->and(fn () => app(SetLedgerStart::class)->handle($f['finance'], '2026-10-02'))->toThrow(AuthorizationException::class);
});

test('opening balances balance through Opening balance equity, are replaced on each save, and freeze once a month is locked', function () {
    $f = $this->f;
    $id = $this->id;
    app(SaveOpeningBalances::class)->handle($f['admin'], [$id('1010') => ['debit' => '1000.000', 'credit' => ''], $id('2100') => ['debit' => '', 'credit' => '600.000']]);
    $journal = Journal::query()->where('kind', 'opening')->sole();

    expect($journal->date->toDateString())->toBe('2026-10-01')
        ->and(linesOf($journal))->toBe(['1010 1000.000 0.000', '2100 0.000 600.000', '3000 0.000 400.000']);

    app(SaveOpeningBalances::class)->handle($f['admin'], [$id('1010') => ['debit' => '500.000', 'credit' => '']]);
    expect(Journal::query()->where('kind', 'opening')->count())->toBe(1)
        ->and(ledgerBalances()[3000])->toBe('-500.000')
        ->and(fn () => app(SaveOpeningBalances::class)->handle($f['admin'], [$id('1010') => ['debit' => '1.000', 'credit' => '1.000']]))->toThrow(ValidationException::class, 'not both');

    app(LockLedger::class)->handle($f['finance'], '2026-10-01');
    expect(fn () => app(SaveOpeningBalances::class)->handle($f['admin'], []))->toThrow(ValidationException::class, 'locked');
});

test('the setup pages are Admin only and shrug off tampered input', function () {
    $f = $this->f;
    $bank = ($this->id)('1010');
    Livewire::actingAs($f['finance'])->test(Accounts::class)->assertForbidden();
    Livewire::actingAs($f['finance'])->test(Setup::class)->assertForbidden();
    Livewire::actingAs($f['admin'])->test(Accounts::class)->call('edit', 'abc')->assertNotFound();
    Livewire::actingAs($f['admin'])->test(Accounts::class)->set('ruleAccounts', 'oops')->call('saveRules')->assertHasErrors('rules');
    Livewire::actingAs($f['admin'])->test(Accounts::class)->set('code', ['x'])->call('save')->assertHasErrors('code');
    Livewire::actingAs($f['admin'])->test(Setup::class)->set('balances', 'oops')->call('saveOpening')->assertHasNoErrors();
    Livewire::actingAs($f['admin'])->test(Setup::class)->set("balances.{$bank}.debit", '1.2345')->call('saveOpening')->assertHasErrors("balances.{$bank}.debit");
    Livewire::actingAs($f['admin'])->test(Setup::class)->set('startDate', ['x'])->call('setStart')->assertHasErrors('startDate');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Ledger/LedgerSetupTest.php`
Expected: FAIL. `App\Actions\Ledger\SaveLedgerAccount` does not exist.

- [ ] **Step 3: Create the account actions.** `app/Actions/Ledger/SaveLedgerAccount.php`:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\LedgerAccountType;
use App\Enums\PermissionName;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec §3: Admin adds and renames accounts. Once an account has postings its type stays (the reports would change shape). */
final class SaveLedgerAccount
{
    /** @param  array<string, mixed>  $data  code, name_en, name_ar, type */
    public function handle(User $actor, ?LedgerAccount $account, array $data): LedgerAccount
    {
        if (! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }
        $v = Validator::make($data, [
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/', Rule::unique('ledger_accounts', 'code')->ignore($account?->id)],
            'name_en' => ['required', 'string', 'max:150'],
            'name_ar' => ['required', 'string', 'max:150'],
            'type' => ['required', 'string', Rule::enum(LedgerAccountType::class)],
        ], ['code.regex' => __('Use up to 20 letters, digits, dots or dashes.')])->validate();

        return DB::transaction(function () use ($actor, $account, $v) {
            $account = $account === null ? new LedgerAccount : LedgerAccount::query()->lockForUpdate()->findOrFail($account->id);
            $old = $account->exists ? ['code' => $account->code, 'name_en' => $account->name_en, 'name_ar' => $account->name_ar, 'type' => $account->type->value] : [];
            if ($account->exists && $account->type->value !== $v['type'] && $account->lines()->exists()) {
                throw ValidationException::withMessages(['type' => __('This account has postings, so its type cannot change.')]);
            }
            $new = ['code' => trim((string) $v['code']), 'name_en' => trim((string) $v['name_en']), 'name_ar' => trim((string) $v['name_ar']), 'type' => (string) $v['type']];
            $account->fill($new)->save();
            Audit::log('ledger.account_saved', $account, $old, $new, causer: $actor);

            return $account;
        });
    }
}
```

`app/Actions/Ledger/SetLedgerAccountActive.php`:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §3: a deactivated account takes no new postings, so an account a posting rule uses stays active. */
final class SetLedgerAccountActive
{
    public function handle(User $actor, LedgerAccount $account, bool $active): void
    {
        if (! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }
        if (! $active && DB::table('ledger_rules')->where('ledger_account_id', $account->id)->exists()) {
            throw ValidationException::withMessages(['accounts' => __('Posting rules use this account. Point them at another account first.')]);
        }
        $account->forceFill(['active' => $active])->save();
        Audit::log($active ? 'ledger.account_activated' : 'ledger.account_deactivated', $account, causer: $actor);
    }
}
```

`app/Actions/Ledger/DeleteLedgerAccount.php`:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §3: an account with lines cannot be deleted (the foreign key agrees); neither can a default or a rule's account. */
final class DeleteLedgerAccount
{
    public function handle(User $actor, LedgerAccount $account): void
    {
        if (! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }
        $error = match (true) {
            $account->system_key !== null => __('A default account cannot be deleted. Deactivate it instead.'),
            $account->lines()->exists() => __('This account has postings. Deactivate it instead.'),
            DB::table('ledger_rules')->where('ledger_account_id', $account->id)->exists() => __('Posting rules use this account. Point them at another account first.'),
            default => null,
        };
        if ($error !== null) {
            throw ValidationException::withMessages(['accounts' => $error]);
        }
        Audit::log('ledger.account_deleted', $account, properties: ['code' => $account->code, 'name_en' => $account->name_en], causer: $actor);
        $account->delete();
    }
}
```

- [ ] **Step 4: Create `app/Actions/Ledger/SaveLedgerRules.php`:**

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\Rules;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §3 (plan ruling 10): every rule key points at an active account. A change applies to new postings; the page then offers a rebuild. */
final class SaveLedgerRules
{
    /** @param  array<array-key, mixed>  $map  rule key => account id */
    public function handle(User $actor, array $map): bool
    {
        if (! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }
        $active = LedgerAccount::query()->where('active', true)->pluck('id')->flip();
        $chosen = [];
        foreach (Rules::keys() as $key) {
            $id = $map[$key] ?? null;
            $id = is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) < 19) ? (int) $id : 0;
            if (! $active->has($id)) {
                throw ValidationException::withMessages(['rules' => __('Choose an active account for every rule.')]);
            }
            $chosen[$key] = $id;
        }

        return DB::transaction(function () use ($actor, $chosen) {
            $old = DB::table('ledger_rules')->lockForUpdate()->pluck('ledger_account_id', 'key')->map(fn ($id) => (int) $id)->all();
            $changed = array_filter($chosen, fn (int $id, string $key) => ($old[$key] ?? null) !== $id, ARRAY_FILTER_USE_BOTH);
            foreach ($changed as $key => $id) {
                DB::table('ledger_rules')->updateOrInsert(['key' => $key], ['ledger_account_id' => $id, 'updated_at' => now()]);
            }
            if ($changed !== []) {
                Audit::log('ledger.rules_saved', null, array_intersect_key($old, $changed), $changed, causer: $actor);
            }

            return $changed !== [];
        });
    }
}
```

- [ ] **Step 5: Create `app/Actions/Ledger/SetLedgerStart.php`:**

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\Poster;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Spec §3 (plan ruling 6): Admin sets or moves the start date, only while nothing is locked. The opening journal moves
 * with it, and the ledger is rebuilt from the earlier of the old and new dates to today.
 */
final class SetLedgerStart
{
    public function handle(User $actor, mixed $date): void
    {
        if (! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }
        Validator::make(['startDate' => $date], ['startDate' => ['required', 'string', 'date_format:Y-m-d']])->validate();
        $date = (string) $date;

        DB::transaction(function () use ($actor, $date) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            $fail = fn (string $message) => throw ValidationException::withMessages(['startDate' => $message]);
            if ($settings->locked_until !== null) {
                $fail(__('The start date can move only while no month is locked.'));
            }
            if ($settings->rebuilding()) {
                $fail(__('Wait for the rebuild to finish.'));
            }
            $manual = Journal::query()->where('kind', 'manual')->where('date', '<', $date)->min('date');
            if ($manual !== null) {
                $fail(__('A manual journal is dated :date, before that start date.', ['date' => CarbonImmutable::parse((string) $manual)->format('d/m/Y')]));
            }
            $old = $settings->start_date?->toDateString();
            if ($old === $date) {
                return;
            }

            $settings->forceFill(['start_date' => $date])->save();
            self::moveOpening($date);
            Audit::log('ledger.start_set', $settings, ['start_date' => $old], ['start_date' => $date], causer: $actor);

            $from = $old === null ? $date : min($old, $date);
            $today = now('Asia/Bahrain')->toDateString();
            if ($from <= $today) {
                RebuildLedger::queue($settings, $from, $today, $actor);
            }
        });
    }

    /** The opening journal is dated the start date; journals never change, so it is written again at the new date. */
    private static function moveOpening(string $date): void
    {
        $opening = Journal::query()->where('kind', 'opening')->with('lines')->first();
        if ($opening === null) {
            return;
        }
        $lines = $opening->lines->map(fn (JournalLine $l) => ['account' => $l->ledger_account_id, 'amount' => Fils::fromDecimal($l->debit) - Fils::fromDecimal($l->credit),
            'customer_id' => null, 'owner_id' => null, 'building_id' => null, 'unit_id' => null, 'memo' => $l->memo])->values()->all();
        JournalLine::query()->where('journal_id', $opening->id)->delete();
        $opening->delete();
        Poster::write('opening', $date, $opening->description, $lines, createdBy: $opening->created_by);
    }
}
```

- [ ] **Step 6: Create `app/Actions/Ledger/SaveOpeningBalances.php`:**

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\Poster;
use App\Ledger\Rules;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Spec §3 (plan ruling 6): one opening balance per account, as a debit or a credit; the difference goes to Opening
 * balance equity, so the opening journal always balances. Replaces the opening journal, only while nothing is locked.
 */
final class SaveOpeningBalances
{
    /** @param  mixed  $balances  account id => ['debit' => '0.000', 'credit' => '0.000'] */
    public function handle(User $actor, mixed $balances): ?Journal
    {
        if (! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }
        $active = LedgerAccount::query()->where('active', true)->pluck('id')->flip();
        $lines = [];
        foreach (is_array($balances) ? $balances : [] as $id => $row) {
            $row = is_array($row) ? $row : [];
            $debit = self::amount($row['debit'] ?? null, "balances.{$id}.debit");
            $credit = self::amount($row['credit'] ?? null, "balances.{$id}.credit");
            if ($debit === 0 && $credit === 0) {
                continue;
            }
            if (! $active->has((int) $id)) {
                throw ValidationException::withMessages(['balances' => __('Opening balances go on active accounts only.')]);
            }
            if ($debit > 0 && $credit > 0) {
                throw ValidationException::withMessages(["balances.{$id}.debit" => __('Enter a debit or a credit, not both.')]);
            }
            $lines[] = ['account' => (int) $id, 'amount' => $debit - $credit, 'customer_id' => null, 'owner_id' => null, 'building_id' => null, 'unit_id' => null, 'memo' => null];
        }
        $difference = array_sum(array_column($lines, 'amount'));

        return DB::transaction(function () use ($actor, $lines, $difference) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            if ($settings->start_date === null) {
                throw ValidationException::withMessages(['balances' => __('Set the ledger start date first.')]);
            }
            if ($settings->locked_until !== null) {
                throw ValidationException::withMessages(['balances' => __('Opening balances change only while no month is locked.')]);
            }
            if ($settings->rebuilding()) {
                throw ValidationException::withMessages(['balances' => __('Wait for the rebuild to finish.')]);
            }
            $old = Journal::query()->where('kind', 'opening')->first();
            if ($old !== null) {
                JournalLine::query()->where('journal_id', $old->id)->delete();
                $old->delete();
            }
            if ($lines === []) {
                Audit::log('ledger.opening_saved', $settings, properties: ['lines' => 0], causer: $actor);

                return null;
            }
            if ($difference !== 0) {
                $lines[] = ['account' => Rules::load()->account('opening_equity'), 'amount' => -$difference, 'customer_id' => null, 'owner_id' => null,
                    'building_id' => null, 'unit_id' => null, 'memo' => null];
            }
            $journal = Poster::write('opening', $settings->start_date->toDateString(), __('Opening balances'), $lines, createdBy: $actor->id);
            Audit::log('ledger.opening_saved', $journal, properties: ['lines' => count($lines), 'to_equity' => Fils::toDecimal(-$difference)], causer: $actor);

            return $journal;
        });
    }

    private static function amount(mixed $value, string $field): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (! is_string($value) || preg_match('/^\d{1,9}(\.\d{1,3})?$/', $value) !== 1) {
            throw ValidationException::withMessages([$field => __('Enter an amount with up to 3 decimals.')]);
        }

        return Fils::fromDecimal($value);
    }
}
```

- [ ] **Step 7: Create the Accounts page.** `app/Livewire/Ledger/Accounts.php`:

```php
<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\DeleteLedgerAccount;
use App\Actions\Ledger\RebuildLedger;
use App\Actions\Ledger\SaveLedgerAccount;
use App\Actions\Ledger\SaveLedgerRules;
use App\Actions\Ledger\SetLedgerAccountActive;
use App\Enums\LedgerAccountType;
use App\Enums\PermissionName;
use App\Ledger\Rules;
use App\Livewire\Concerns\WithActor;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Spec §3: Admin's chart of accounts and posting rules. After a rule change the page offers to rebuild the open months. */
class Accounts extends Component
{
    use WithActor;

    #[Locked]
    public ?int $editingId = null;

    // Untyped on purpose: a tampered payload reaches the action's validator, never a TypeError (a 500).
    public mixed $code = '';

    public mixed $name_en = '';

    public mixed $name_ar = '';

    public mixed $type = 'expense';

    /** @var mixed account ids in Rules::keys() order (rule keys contain dots, which Livewire would read as nesting) */
    public mixed $ruleAccounts = [];

    public bool $offerRebuild = false;

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerAdmin), 403);
        $this->loadRules();
    }

    public function edit(mixed $id): void
    {
        $account = $this->find($id);
        $this->resetErrorBag();
        $this->editingId = $account->id;
        $this->code = $account->code;
        $this->name_en = $account->name_en;
        $this->name_ar = $account->name_ar;
        $this->type = $account->type->value;
    }

    public function cancel(): void
    {
        $this->resetErrorBag();
        $this->reset('editingId', 'code', 'name_en', 'name_ar', 'type');
    }

    public function save(): void
    {
        $account = $this->editingId === null ? null : LedgerAccount::query()->findOrFail($this->editingId);
        app(SaveLedgerAccount::class)->handle($this->actor(), $account, ['code' => $this->code, 'name_en' => $this->name_en, 'name_ar' => $this->name_ar, 'type' => $this->type]);
        $this->cancel();
        Flux::toast(variant: 'success', text: __('Account saved.'));
    }

    public function toggle(mixed $id): void
    {
        $account = $this->find($id);
        app(SetLedgerAccountActive::class)->handle($this->actor(), $account, ! $account->active);
    }

    public function delete(mixed $id): void
    {
        app(DeleteLedgerAccount::class)->handle($this->actor(), $this->find($id));
        Flux::toast(variant: 'success', text: __('Account deleted.'));
    }

    public function saveRules(): void
    {
        $chosen = array_values(is_array($this->ruleAccounts) ? $this->ruleAccounts : []);
        $map = [];
        foreach (Rules::keys() as $i => $key) {
            $map[$key] = $chosen[$i] ?? null;
        }
        $this->offerRebuild = app(SaveLedgerRules::class)->handle($this->actor(), $map) || $this->offerRebuild;
        $this->loadRules();
        Flux::toast(variant: 'success', text: __('Rules saved.'));
    }

    public function rebuildOpen(): void
    {
        $first = LedgerSetting::current()->firstOpenDay();
        app(RebuildLedger::class)->handle($this->actor(), ($first ?? now('Asia/Bahrain'))->format('Y-m'), now('Asia/Bahrain')->format('Y-m'));
        $this->offerRebuild = false;
        Flux::toast(variant: 'success', text: __('Rebuild started.'));
    }

    private function find(mixed $id): LedgerAccount
    {
        abort_unless(is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) < 19), 404);

        return LedgerAccount::query()->findOrFail((int) $id);
    }

    private function loadRules(): void
    {
        $map = DB::table('ledger_rules')->pluck('ledger_account_id', 'key');
        $this->ruleAccounts = array_map(fn (string $key) => isset($map[$key]) ? (string) $map[$key] : '', Rules::keys());
    }

    public function render(): View
    {
        $keys = Rules::keys();

        return view('livewire.ledger.accounts', [
            'accounts' => LedgerAccount::query()->withExists('lines')->orderBy('code')->get(),
            'active' => LedgerAccount::query()->where('active', true)->orderBy('code')->get(),
            'types' => LedgerAccountType::cases(),
            'ruleKeys' => $keys,
            'ruleLabels' => array_combine($keys, array_map(fn (string $k) => Rules::label($k), $keys)),
        ])->title(__('Accounts and rules'));
    }
}
```

`resources/views/livewire/ledger/accounts.blade.php`:

```blade
<section class="w-full max-w-5xl space-y-8">
    <flux:heading size="xl" level="1">{{ __('Accounts and rules') }}</flux:heading>

    <div class="space-y-4">
        <flux:heading size="lg">{{ __('Chart of accounts') }}</flux:heading>
        <form wire:submit="save" class="grid gap-3 sm:grid-cols-5">
            <flux:input wire:model="code" :label="__('Code')" />
            <flux:input wire:model="name_en" :label="__('Name (English)')" />
            <flux:input wire:model="name_ar" :label="__('Name (Arabic)')" dir="rtl" />
            <flux:select wire:model="type" :label="__('Type')">
                @foreach ($types as $t)
                    <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex items-end gap-2">
                <flux:button type="submit" variant="primary">{{ $editingId ? __('Save') : __('Add account') }}</flux:button>
                @if ($editingId)
                    <flux:button wire:click="cancel">{{ __('Cancel') }}</flux:button>
                @endif
            </div>
        </form>
        <flux:error name="accounts" />

        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Code') }}</flux:table.column>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Type') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($accounts as $account)
                        <flux:table.row :key="'a-'.$account->id">
                            <flux:table.cell>{{ $account->code }}</flux:table.cell>
                            <flux:table.cell>{{ $account->name_en }} · <span dir="rtl">{{ $account->name_ar }}</span></flux:table.cell>
                            <flux:table.cell>{{ $account->type->label() }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$account->active ? 'green' : 'zinc'">{{ $account->active ? __('Active') : __('Inactive') }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="space-x-1 text-end">
                                <flux:button size="sm" wire:click="edit({{ $account->id }})">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" wire:click="toggle({{ $account->id }})">{{ $account->active ? __('Deactivate') : __('Activate') }}</flux:button>
                                @if (! $account->lines_exists && $account->system_key === null)
                                    <flux:button size="sm" variant="danger" wire:click="delete({{ $account->id }})" wire:confirm="{{ __('Delete this account?') }}">{{ __('Delete') }}</flux:button>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </div>

    <form wire:submit="saveRules" class="space-y-4">
        <flux:heading size="lg">{{ __('Posting rules') }}</flux:heading>
        <flux:text>{{ __('Each event posts to the account chosen here. A change applies to new postings; rebuild the open months to restate them.') }}</flux:text>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($ruleKeys as $i => $key)
                <flux:select wire:model="ruleAccounts.{{ $i }}" :label="$ruleLabels[$key]" wire:key="rule-{{ $i }}">
                    @foreach ($active as $account)
                        <flux:select.option :value="(string) $account->id">{{ $account->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endforeach
        </div>
        <flux:error name="rules" />
        <div class="flex flex-wrap gap-2">
            <flux:button type="submit" variant="primary">{{ __('Save rules') }}</flux:button>
            @if ($offerRebuild)
                <flux:button type="button" wire:click="rebuildOpen">{{ __('Rebuild the open months') }}</flux:button>
            @endif
        </div>
        <flux:error name="rebuild" />
    </form>
</section>
```

- [ ] **Step 8: Create the Setup page.** `app/Livewire/Ledger/Setup.php`:

```php
<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\SaveOpeningBalances;
use App\Actions\Ledger\SetLedgerStart;
use App\Enums\PermissionName;
use App\Livewire\Concerns\WithActor;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Spec §3 "Go-live" (plan ruling 6): Admin's start date and one opening balance per account. */
class Setup extends Component
{
    use WithActor;

    // Untyped on purpose: a tampered payload reaches the action, never a TypeError (a 500).
    public mixed $startDate = '';

    /** @var mixed account id => ['debit' => string, 'credit' => string] */
    public mixed $balances = [];

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerAdmin), 403);
        $this->startDate = LedgerSetting::current()->start_date?->toDateString() ?? '';
        $this->loadBalances();
    }

    public function setStart(): void
    {
        app(SetLedgerStart::class)->handle($this->actor(), $this->startDate);
        $this->loadBalances();
        Flux::toast(variant: 'success', text: __('Start date saved. The ledger is being rebuilt.'));
    }

    public function saveOpening(): void
    {
        app(SaveOpeningBalances::class)->handle($this->actor(), is_array($this->balances) ? $this->balances : []);
        $this->loadBalances();
        Flux::toast(variant: 'success', text: __('Opening balances saved.'));
    }

    private function loadBalances(): void
    {
        $opening = [];
        foreach (JournalLine::query()->whereIn('journal_id', Journal::query()->where('kind', 'opening')->select('id'))->get() as $line) {
            $opening[$line->ledger_account_id] = ($opening[$line->ledger_account_id] ?? 0) + Fils::fromDecimal($line->debit) - Fils::fromDecimal($line->credit);
        }
        $balances = [];
        foreach (LedgerAccount::query()->where('active', true)->orderBy('code')->pluck('id') as $id) {
            $n = $opening[$id] ?? 0;
            $balances[$id] = ['debit' => $n > 0 ? Fils::toDecimal($n) : '', 'credit' => $n < 0 ? Fils::toDecimal(-$n) : ''];
        }
        $this->balances = $balances;
    }

    public function render(): View
    {
        $difference = 0;
        foreach (is_array($this->balances) ? $this->balances : [] as $row) {
            foreach (['debit' => 1, 'credit' => -1] as $side => $sign) {
                $value = is_array($row) ? ($row[$side] ?? '') : '';
                if (is_string($value) && preg_match('/^\d{1,9}(\.\d{1,3})?$/', $value) === 1) {
                    $difference += $sign * Fils::fromDecimal($value);
                }
            }
        }

        return view('livewire.ledger.setup', [
            'settings' => LedgerSetting::current(),
            'accounts' => LedgerAccount::query()->where('active', true)->orderBy('code')->get(),
            'difference' => Fils::toDecimal($difference),
        ])->title(__('Start date and opening balances'));
    }
}
```

`resources/views/livewire/ledger/setup.blade.php`:

```blade
<section class="w-full max-w-3xl space-y-8">
    <flux:heading size="xl" level="1">{{ __('Start date and opening balances') }}</flux:heading>

    <form wire:submit="setStart" class="space-y-3">
        <flux:heading size="lg">{{ __('Start date') }}</flux:heading>
        <flux:text>{{ __('Nothing is posted before this date. It can move only while no month is locked; moving it rebuilds the ledger.') }}</flux:text>
        <div class="flex flex-wrap items-end gap-2">
            <x-date-input wire:model="startDate" :label="__('Start date')" />
            <flux:button type="submit" variant="primary">{{ __('Save start date') }}</flux:button>
        </div>
    </form>

    @if ($settings->start_date)
        <form wire:submit="saveOpening" class="space-y-3">
            <flux:heading size="lg">{{ __('Opening balances') }}</flux:heading>
            <flux:text>{{ __('One balance per account at the start date. Any difference goes to Opening balance equity.') }}</flux:text>
            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Account') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Debit') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Credit') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($accounts as $account)
                            <flux:table.row :key="'o-'.$account->id">
                                <flux:table.cell>{{ $account->label() }}</flux:table.cell>
                                <flux:table.cell><flux:input size="sm" wire:model.blur="balances.{{ $account->id }}.debit" inputmode="decimal" :aria-label="__('Debit')" /></flux:table.cell>
                                <flux:table.cell><flux:input size="sm" wire:model.blur="balances.{{ $account->id }}.credit" inputmode="decimal" :aria-label="__('Credit')" /></flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
            <flux:text>{{ __('Difference to Opening balance equity: :amount', ['amount' => $difference]) }}</flux:text>
            <flux:error name="balances" />
            <flux:button type="submit" variant="primary">{{ __('Save opening balances') }}</flux:button>
        </form>
    @endif
</section>
```

- [ ] **Step 9: Routes and the sidebar.**
  - Create `routes/ledger.php`:

```php
<?php

use App\Livewire\Ledger;
use Illuminate\Support\Facades\Route;

// General ledger (spec 2026-10-24, plan ruling 12). Each page re-checks its permission in mount(); the middleware answers 403 first.
Route::middleware(['auth:web'])->prefix('ledger')->name('ledger.')->group(function () {
    Route::livewire('accounts', Ledger\Accounts::class)->middleware('can:ledger.admin')->name('accounts');
    Route::livewire('setup', Ledger\Setup::class)->middleware('can:ledger.admin')->name('setup');
});
```

  - In `routes/web.php`, after `require __DIR__.'/property.php';` add:

```php

require __DIR__.'/ledger.php';
```

  - In `resources/views/layouts/app/sidebar.blade.php`, insert before the line `                @canany(['reports.operational', 'reports.financial'])`:

```blade
                @canany(['ledger.view', 'ledger.admin'])
                    <flux:sidebar.group :heading="__('Ledger')" class="grid">
                        @can('ledger.admin')
                            <flux:sidebar.item icon="list-bullet" :href="route('ledger.accounts')" :current="request()->routeIs('ledger.accounts')" wire:navigate>{{ __('Accounts and rules') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="flag" :href="route('ledger.setup')" :current="request()->routeIs('ledger.setup')" wire:navigate>{{ __('Start date and opening balances') }}</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany

```

- [ ] **Step 10: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Ledger": "الدفاتر",
    "Accounts and rules": "الحسابات والقواعد",
    "Start date and opening balances": "تاريخ البدء والأرصدة الافتتاحية",
    "Chart of accounts": "دليل الحسابات",
    "Name (English)": "الاسم (بالإنجليزية)",
    "Name (Arabic)": "الاسم (بالعربية)",
    "Add account": "إضافة حساب",
    "Name": "الاسم",
    "Inactive": "غير نشط",
    "Deactivate": "إيقاف",
    "Activate": "تفعيل",
    "Delete this account?": "حذف هذا الحساب؟",
    "Posting rules": "قواعد الترحيل",
    "Each event posts to the account chosen here. A change applies to new postings; rebuild the open months to restate them.": "يُرحَّل كل حدث إلى الحساب المختار هنا. ينطبق التغيير على الترحيلات الجديدة؛ أعد بناء الأشهر المفتوحة لتعديل السابقة.",
    "Save rules": "حفظ القواعد",
    "Rebuild the open months": "إعادة بناء الأشهر المفتوحة",
    "Account saved.": "تم حفظ الحساب.",
    "Account deleted.": "تم حذف الحساب.",
    "Rules saved.": "تم حفظ القواعد.",
    "Rebuild started.": "بدأت إعادة البناء.",
    "Use up to 20 letters, digits, dots or dashes.": "استخدم حتى 20 حرفاً أو رقماً أو نقطة أو شرطة.",
    "This account has postings, so its type cannot change.": "لهذا الحساب ترحيلات، لذلك لا يمكن تغيير نوعه.",
    "Posting rules use this account. Point them at another account first.": "قواعد الترحيل تستخدم هذا الحساب. وجّهها إلى حساب آخر أولاً.",
    "A default account cannot be deleted. Deactivate it instead.": "لا يمكن حذف حساب افتراضي. أوقفه بدلاً من ذلك.",
    "This account has postings. Deactivate it instead.": "لهذا الحساب ترحيلات. أوقفه بدلاً من ذلك.",
    "Choose an active account for every rule.": "اختر حساباً نشطاً لكل قاعدة.",
    "The start date can move only while no month is locked.": "لا يمكن تغيير تاريخ البدء إلا عندما لا يكون أي شهر مقفلاً.",
    "A manual journal is dated :date, before that start date.": "يوجد قيد يدوي بتاريخ :date، قبل تاريخ البدء هذا.",
    "Opening balances go on active accounts only.": "الأرصدة الافتتاحية على الحسابات النشطة فقط.",
    "Enter an amount with up to 3 decimals.": "أدخل مبلغاً بثلاث خانات عشرية كحد أقصى.",
    "Enter a debit or a credit, not both.": "أدخل مديناً أو دائناً، لا كليهما.",
    "Opening balances change only while no month is locked.": "لا تتغير الأرصدة الافتتاحية إلا عندما لا يكون أي شهر مقفلاً.",
    "Start date": "تاريخ البدء",
    "Nothing is posted before this date. It can move only while no month is locked; moving it rebuilds the ledger.": "لا يُرحَّل شيء قبل هذا التاريخ. يمكن تغييره فقط عندما لا يكون أي شهر مقفلاً، وتغييره يعيد بناء الدفاتر.",
    "Save start date": "حفظ تاريخ البدء",
    "One balance per account at the start date. Any difference goes to Opening balance equity.": "رصيد واحد لكل حساب في تاريخ البدء. يذهب أي فرق إلى حقوق ملكية الأرصدة الافتتاحية.",
    "Account": "الحساب",
    "Debit": "مدين",
    "Difference to Opening balance equity: :amount": "الفرق إلى حقوق ملكية الأرصدة الافتتاحية: :amount",
    "Save opening balances": "حفظ الأرصدة الافتتاحية",
    "Start date saved. The ledger is being rebuilt.": "تم حفظ تاريخ البدء. يجري إعادة بناء الدفاتر.",
    "Opening balances saved.": "تم حفظ الأرصدة الافتتاحية.",
```

- [ ] **Step 11: Run the tests.**

Run: `php artisan test tests/Feature/Ledger`
Expected: PASS.

- [ ] **Step 12: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Actions/Ledger app/Livewire/Ledger resources/views/livewire/ledger routes/ledger.php routes/web.php resources/views/layouts/app/sidebar.blade.php tests/Feature/Ledger/LedgerSetupTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
General ledger setup: Admin's chart of accounts and posting rules with a rebuild offer, start date that moves only while unlocked, opening balances through Opening balance equity

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Manual journals: post, view, reverse

**Files:**
- Create: `app/Actions/Ledger/PostManualJournal.php`, `app/Actions/Ledger/ReverseManualJournal.php`
- Create: `app/Livewire/Ledger/NewJournal.php`, `app/Livewire/Ledger/JournalShow.php`, `resources/views/livewire/ledger/new-journal.blade.php`, `resources/views/livewire/ledger/journal-show.blade.php`
- Modify: `app/Ledger/Sources.php` (`url()`), `app/Models/JournalLine.php` (dimension relations), `routes/ledger.php`, `resources/views/layouts/app/sidebar.blade.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/ManualJournalTest.php`

**Interfaces:**
- Consumes: `Poster::write()`, `LedgerSetting::firstOpenDay()`, `Sources::label()`, `ledgerFixture()`, `linesOf()`, `journalFor()`.
- Produces:
  - `PostManualJournal::handle(User $actor, mixed $data): Journal`: needs `ledger.manage`; `$data` holds `date`, `description` and `lines` (each `account_id`, `debit`, `credit`, `memo`); errors on `date`, `description`, `lines`, `lines.<i>.*`; audit `ledger.journal_posted`.
  - `PostManualJournal::openDate(LedgerSetting $settings, string $date, string $field): void`.
  - `ReverseManualJournal::handle(User $actor, Journal $journal, mixed $date): Journal`: needs `ledger.manage`; errors on `reverseDate`; audit `ledger.journal_reversed`.
  - `Sources::url(string $kind, ?string $type, ?int $id, ?int $paymentId, int $journalId): string` (plan ruling 9).
  - `JournalLine::customer()`, `owner()`, `building()`, `unit()`.
  - Routes `ledger.journals.create`, `ledger.journals.show` (`{journal}` numeric).

- [ ] **Step 1: Write the failing test** `tests/Feature/Ledger/ManualJournalTest.php`:

```php
<?php

use App\Actions\Ledger\PostManualJournal;
use App\Actions\Ledger\ReverseManualJournal;
use App\Livewire\Ledger\JournalShow;
use App\Livewire\Ledger\NewJournal;
use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
});

/** One manual journal line as the form sends it. */
function manualLine(string $code, string $debit = '', string $credit = '', string $memo = ''): array
{
    return ['account_id' => (string) LedgerAccount::query()->where('code', $code)->value('id'), 'debit' => $debit, 'credit' => $credit, 'memo' => $memo];
}

test('Finance posts a balanced manual journal on an open date; it is audited and never edited', function () {
    $f = $this->f;
    $journal = app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-19', 'description' => 'Bank charges October',
        'lines' => [manualLine('5190', '2.500', '', 'NBB fee'), manualLine('1010', '', '2.500')]]);

    expect($journal->kind)->toBe('manual')
        ->and($journal->created_by)->toBe($f['finance']->id)
        ->and(linesOf($journal))->toBe(['5190 2.500 0.000', '1010 0.000 2.500'])
        ->and($journal->lines()->orderBy('id')->value('memo'))->toBe('NBB fee')
        ->and(Activity::query()->where('event', 'ledger.journal_posted')->sole()->causer_id)->toBe($f['finance']->id)
        ->and(fn () => DB::table('journals')->where('id', $journal->id)->update(['description' => 'Changed']))->toThrow(QueryException::class)
        ->and(fn () => app(PostManualJournal::class)->handle($f['admin'], ['date' => '2026-10-19', 'description' => 'x', 'lines' => []]))->toThrow(AuthorizationException::class);
});

test('a manual journal must balance, have two lines and a description, and use an open date', function (array $lines, array $over, string $error) {
    LedgerSetting::current()->forceFill(['locked_until' => '2026-10-05'])->save();
    $data = ['date' => '2026-10-19', 'description' => 'Test', 'lines' => array_map(fn (array $l) => manualLine(...$l), $lines), ...$over];

    expect(fn () => app(PostManualJournal::class)->handle($this->f['finance'], $data))->toThrow(ValidationException::class, $error);
})->with([
    'unbalanced' => [[['5190', '1.000'], ['1010', '', '2.000']], [], 'does not balance'],
    'one line' => [[['5190', '1.000']], [], 'at least two lines'],
    'both sides on a line' => [[['5190', '1.000', '1.000'], ['1010', '', '1.000']], [], 'debit or a credit'],
    'no description' => [[['5190', '1.000'], ['1010', '', '1.000']], ['description' => ''], 'description'],
    'locked date' => [[['5190', '1.000'], ['1010', '', '1.000']], ['date' => '2026-10-05'], 'on or after 06/10/2026'],
    'future date' => [[['5190', '1.000'], ['1010', '', '1.000']], ['date' => '2026-10-21'], 'date'],
    'tampered lines' => [[], ['lines' => 'oops'], 'lines'],
]);

test('an inactive account takes no manual postings', function () {
    LedgerAccount::query()->where('code', '3100')->update(['active' => false]);

    expect(fn () => app(PostManualJournal::class)->handle($this->f['finance'], ['date' => '2026-10-19', 'description' => 'Test',
        'lines' => [manualLine('3100', '1.000'), manualLine('1010', '', '1.000')]]))->toThrow(ValidationException::class, 'active account');
});

test('a manual journal is reversed once, by its exact opposite; a reversal and an automatic journal never', function () {
    $f = $this->f;
    $journal = app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-19', 'description' => 'Bank charges',
        'lines' => [manualLine('5190', '2.500'), manualLine('1010', '', '2.500')]]);
    $reversal = app(ReverseManualJournal::class)->handle($f['finance'], $journal, '2026-10-20');

    expect(linesOf($reversal))->toBe(['5190 0.000 2.500', '1010 2.500 0.000'])
        ->and($reversal->reverses_journal_id)->toBe($journal->id)
        ->and($reversal->date->toDateString())->toBe('2026-10-20')
        ->and(Activity::query()->where('event', 'ledger.journal_reversed')->sole()->subject_id)->toBe($journal->id)
        ->and(fn () => app(ReverseManualJournal::class)->handle($f['finance'], $journal, '2026-10-20'))->toThrow(ValidationException::class, 'already reversed')
        ->and(fn () => app(ReverseManualJournal::class)->handle($f['finance'], $reversal, '2026-10-20'))->toThrow(ValidationException::class)
        ->and(fn () => app(ReverseManualJournal::class)->handle($f['finance'], journalFor('expense', $f['companyExpense']->id), '2026-10-20'))->toThrow(ValidationException::class);
});

test('the new-journal page posts and opens the journal; the journal page links to its source and reverses', function () {
    $f = $this->f;
    $page = Livewire::actingAs($f['finance'])->test(NewJournal::class)
        ->set('description', 'Petty cash top-up')
        ->set('lines.0', manualLine('1000', '20.000'))
        ->set('lines.1', manualLine('1010', '', '20.000'))
        ->call('addLine')
        ->call('save')->assertHasNoErrors();
    $journal = Journal::query()->where('kind', 'manual')->sole();
    $page->assertRedirect(route('ledger.journals.show', $journal->id));

    Livewire::actingAs($f['finance'])->test(JournalShow::class, ['journal' => (string) $journal->id])
        ->assertSee('Petty cash top-up')->call('reverse')->assertHasNoErrors();
    expect(Journal::query()->where('reverses_journal_id', $journal->id)->exists())->toBeTrue();

    $expense = journalFor('expense', $f['companyExpense']->id);
    Livewire::actingAs($f['admin'])->test(JournalShow::class, ['journal' => (string) $expense->id])
        ->assertSeeHtml(route('expenses.show', $f['companyExpense']->id))->assertDontSee('Reversal date');
    Livewire::actingAs($f['finance'])->test(JournalShow::class, ['journal' => '999999999'])->assertNotFound();
    Livewire::actingAs($f['finance'])->test(NewJournal::class)->set('lines', 'oops')->call('save')->assertHasErrors('lines');
    Livewire::actingAs($f['finance'])->test(NewJournal::class)->call('removeLine', ['x'])->assertHasNoErrors();
    Livewire::actingAs($f['admin'])->test(NewJournal::class)->assertForbidden();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Ledger/ManualJournalTest.php`
Expected: FAIL. `App\Actions\Ledger\PostManualJournal` does not exist.

- [ ] **Step 3: Create `app/Actions/Ledger/PostManualJournal.php`:**

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\Poster;
use App\Models\Journal;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec §4, §8 (plan ruling 5): Finance posts a balanced journal of at least two lines, with a description, on an open date. */
final class PostManualJournal
{
    /** @param  mixed  $data  date, description, lines: list of [account_id, debit, credit, memo] */
    public function handle(User $actor, mixed $data): Journal
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        $data = is_array($data) ? $data : [];
        $data['lines'] = array_values(array_filter(is_array($data['lines'] ?? null) ? $data['lines'] : [], fn ($l) => is_array($l)
            && (filled($l['account_id'] ?? null) || filled($l['debit'] ?? null) || filled($l['credit'] ?? null))));
        $v = Validator::make($data, [
            'date' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'description' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('ledger_accounts', 'id')->where('active', true)],
            'lines.*.debit' => ['nullable', 'string', Fils::rule()],
            'lines.*.credit' => ['nullable', 'string', Fils::rule()],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ], [
            'lines.required' => __('A journal needs at least two lines.'),
            'lines.min' => __('A journal needs at least two lines.'),
            'lines.*.account_id.exists' => __('Choose an active account.'),
        ])->validate();

        $lines = [];
        $debits = 0;
        $credits = 0;
        foreach ($v['lines'] as $i => $l) {
            $debit = filled($l['debit'] ?? null) ? Fils::fromDecimal((string) $l['debit']) : 0;
            $credit = filled($l['credit'] ?? null) ? Fils::fromDecimal((string) $l['credit']) : 0;
            if (($debit > 0) === ($credit > 0)) {
                throw ValidationException::withMessages(["lines.{$i}.debit" => __('Enter a debit or a credit on each line.')]);
            }
            $lines[] = ['account' => (int) $l['account_id'], 'amount' => $debit - $credit, 'customer_id' => null, 'owner_id' => null, 'building_id' => null,
                'unit_id' => null, 'memo' => filled($l['memo'] ?? null) ? trim((string) $l['memo']) : null];
            $debits += $debit;
            $credits += $credit;
        }
        if ($debits !== $credits) {
            throw ValidationException::withMessages(['lines' => __('The journal does not balance: debits :debits, credits :credits.', [
                'debits' => Fils::toDecimal($debits), 'credits' => Fils::toDecimal($credits)])]);
        }

        return DB::transaction(function () use ($actor, $v, $lines, $debits) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            self::openDate($settings, (string) $v['date'], 'date');
            $journal = Poster::write('manual', (string) $v['date'], trim((string) $v['description']), $lines, createdBy: $actor->id);
            Audit::log('ledger.journal_posted', $journal, properties: ['date' => $v['date'], 'total' => Fils::toDecimal($debits)], causer: $actor);

            return $journal;
        });
    }

    /** Throws unless $date is on or after the start date and after the lock (plan ruling 5). The caller holds the settings lock. */
    public static function openDate(LedgerSetting $settings, string $date, string $field): void
    {
        $first = $settings->firstOpenDay();
        if ($first === null) {
            throw ValidationException::withMessages([$field => __('Set the ledger start date first.')]);
        }
        if ($date < $first->toDateString()) {
            throw ValidationException::withMessages([$field => __('Choose a date on or after :date: earlier days are locked or before the start date.', ['date' => $first->format('d/m/Y')])]);
        }
    }
}
```

- [ ] **Step 4: Create `app/Actions/Ledger/ReverseManualJournal.php`:**

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\Poster;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Spec §4: a manual journal is corrected by its exact opposite, once, on an open date. Automatic journals follow their source. */
final class ReverseManualJournal
{
    public function handle(User $actor, Journal $journal, mixed $date): Journal
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        Validator::make(['reverseDate' => $date], [
            'reverseDate' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
        ])->validate();
        $date = (string) $date;

        return DB::transaction(function () use ($actor, $journal, $date) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            $journal = Journal::query()->with('lines')->findOrFail($journal->id);
            if ($journal->kind !== 'manual' || $journal->reverses_journal_id !== null) {
                throw ValidationException::withMessages(['reverseDate' => __('Only a manual journal that is not itself a reversal can be reversed.')]);
            }
            if (Journal::query()->where('reverses_journal_id', $journal->id)->exists()) {
                throw ValidationException::withMessages(['reverseDate' => __('This journal is already reversed.')]);
            }
            PostManualJournal::openDate($settings, $date, 'reverseDate');

            $lines = $journal->lines->map(fn (JournalLine $l) => ['account' => $l->ledger_account_id, 'amount' => Fils::fromDecimal($l->credit) - Fils::fromDecimal($l->debit),
                'customer_id' => $l->customer_id, 'owner_id' => $l->owner_id, 'building_id' => $l->building_id, 'unit_id' => $l->unit_id, 'memo' => $l->memo])->values()->all();
            $reversal = Poster::write('manual', $date, $journal->description, $lines, reverses: $journal->id, createdBy: $actor->id);
            Audit::log('ledger.journal_reversed', $journal, properties: ['reversal' => $reversal->id, 'date' => $date], causer: $actor);

            return $reversal;
        });
    }
}
```

- [ ] **Step 5: Source links and line relations.**
  - In `app/Ledger/Sources.php`, add after `label()`:

```php
    /** Where a journal's source opens (plan ruling 9): the record's own page; manual, opening and the rest open the journal page. */
    public static function url(string $kind, ?string $type, ?int $id, ?int $paymentId, int $journalId): string
    {
        if ($kind !== 'auto' || $type === null || $id === null) {
            return route('ledger.journals.show', $journalId);
        }

        return match ($type) {
            'invoice' => route('invoices.show', $id),
            'payment', 'payment_reversal' => route('payments.show', $id),
            'allocation' => $paymentId === null ? route('ledger.journals.show', $journalId) : route('payments.show', $paymentId),
            'expense', 'expense_reversal' => route('expenses.show', $id),
            'disbursement', 'disbursement_reversal' => route('disbursements.show', $id),
            'owner_payable', 'owner_payable_cancel' => route('owner-payables.index'),
            default => route('ledger.journals.show', $journalId),
        };
    }
```

  - In `app/Models/JournalLine.php`, add after `journal()`:

```php
    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Owner, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
```

- [ ] **Step 6: Create the two pages.** `app/Livewire/Ledger/NewJournal.php`:

```php
<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\PostManualJournal;
use App\Enums\PermissionName;
use App\Livewire\Concerns\WithActor;
use App\Models\LedgerAccount;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Spec §4: Finance enters a manual journal. Blank rows are dropped before posting, so error keys match the rows left. */
class NewJournal extends Component
{
    use WithActor;

    private const array BLANK = ['account_id' => '', 'debit' => '', 'credit' => '', 'memo' => ''];

    // Untyped on purpose: a tampered payload reaches the action's validator, never a TypeError (a 500).
    public mixed $date = '';

    public mixed $description = '';

    public mixed $lines = [];

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerManage), 403);
        $this->date = now('Asia/Bahrain')->toDateString();
        $this->lines = [self::BLANK, self::BLANK];
    }

    public function addLine(): void
    {
        $lines = $this->rows();
        if (count($lines) < 50) {
            $lines[] = self::BLANK;
        }
        $this->lines = $lines;
    }

    public function removeLine(mixed $index): void
    {
        $lines = $this->rows();
        if ((is_int($index) || (is_string($index) && ctype_digit($index) && strlen($index) < 4)) && isset($lines[(int) $index])) {
            unset($lines[(int) $index]);
        }
        $this->lines = array_values($lines);
    }

    public function save(): void
    {
        $this->lines = array_values(array_filter($this->rows(), fn (array $l) => filled($l['account_id'] ?? null) || filled($l['debit'] ?? null) || filled($l['credit'] ?? null)));
        $journal = app(PostManualJournal::class)->handle($this->actor(), ['date' => $this->date, 'description' => $this->description, 'lines' => $this->lines]);
        Flux::toast(variant: 'success', text: __('Journal posted.'));
        $this->redirectRoute('ledger.journals.show', ['journal' => $journal->id], navigate: true);
    }

    /** @return list<array<array-key, mixed>> the line rows, whatever the browser sent */
    private function rows(): array
    {
        return array_values(array_filter(is_array($this->lines) ? $this->lines : [], 'is_array'));
    }

    public function render(): View
    {
        $sum = function (string $side): string {
            $fils = 0;
            foreach ($this->rows() as $line) {
                $value = $line[$side] ?? '';
                if (is_string($value) && preg_match('/^\d{1,9}(\.\d{1,3})?$/', $value) === 1) {
                    $fils += Fils::fromDecimal($value);
                }
            }

            return Fils::toDecimal($fils);
        };

        return view('livewire.ledger.new-journal', [
            'rows' => $this->rows(),
            'accounts' => LedgerAccount::query()->where('active', true)->orderBy('code')->get(),
            'debits' => $sum('debit'),
            'credits' => $sum('credit'),
        ])->title(__('New manual journal'));
    }
}
```

`resources/views/livewire/ledger/new-journal.blade.php`:

```blade
<section class="w-full max-w-5xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('New manual journal') }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <x-date-input wire:model="date" :label="__('Date')" />
            <div class="sm:col-span-2"><flux:input wire:model="description" :label="__('Description')" /></div>
        </div>

        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Account') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Debit') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Credit') }}</flux:table.column>
                    <flux:table.column>{{ __('Memo') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($rows as $i => $line)
                        <flux:table.row :key="'l-'.$i">
                            <flux:table.cell>
                                <flux:select wire:model="lines.{{ $i }}.account_id" :aria-label="__('Account')">
                                    <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                                    @foreach ($accounts as $account)
                                        <flux:select.option :value="(string) $account->id">{{ $account->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </flux:table.cell>
                            <flux:table.cell><flux:input wire:model.blur="lines.{{ $i }}.debit" inputmode="decimal" :aria-label="__('Debit')" /></flux:table.cell>
                            <flux:table.cell><flux:input wire:model.blur="lines.{{ $i }}.credit" inputmode="decimal" :aria-label="__('Credit')" /></flux:table.cell>
                            <flux:table.cell><flux:input wire:model="lines.{{ $i }}.memo" :aria-label="__('Memo')" /></flux:table.cell>
                            <flux:table.cell><flux:button size="sm" wire:click="removeLine({{ $i }})">{{ __('Remove') }}</flux:button></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        <flux:text>{{ __('Debits :debits · Credits :credits', ['debits' => $debits, 'credits' => $credits]) }}</flux:text>
        <flux:error name="lines" />
        <div class="flex flex-wrap gap-2">
            <flux:button type="button" wire:click="addLine">{{ __('Add line') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('Post journal') }}</flux:button>
        </div>
    </form>
</section>
```

`app/Livewire/Ledger/JournalShow.php`:

```php
<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\ReverseManualJournal;
use App\Enums\PermissionName;
use App\Ledger\Sources;
use App\Livewire\Concerns\WithActor;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** One journal with its lines, a link to its source, and (manual, not yet reversed, Finance) the reversal form. */
class JournalShow extends Component
{
    use WithActor;

    #[Locked]
    public int $journalId;

    public mixed $reverseDate = '';

    public function mount(string $journal): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerView), 403);
        $this->journalId = Journal::query()->findOrFail(ctype_digit($journal) && strlen($journal) < 19 ? (int) $journal : 0)->id;
        $this->reverseDate = now('Asia/Bahrain')->toDateString();
    }

    public function reverse(): void
    {
        $reversal = app(ReverseManualJournal::class)->handle($this->actor(), Journal::query()->findOrFail($this->journalId), $this->reverseDate);
        Flux::toast(variant: 'success', text: __('Journal reversed.'));
        $this->redirectRoute('ledger.journals.show', ['journal' => $reversal->id], navigate: true);
    }

    public function render(): View
    {
        $journal = Journal::query()->with(['lines.account', 'lines.customer:id,name_en', 'lines.owner:id,name_en', 'lines.building:id,code', 'lines.unit:id,code'])
            ->findOrFail($this->journalId);
        $reversal = Journal::query()->where('reverses_journal_id', $journal->id)->value('id');
        $paymentId = $journal->source_type === 'allocation' ? DB::table('payment_allocations')->where('id', $journal->source_id)->value('payment_id') : null;

        return view('livewire.ledger.journal-show', [
            'journal' => $journal,
            'source' => Sources::label($journal->source_type ?? $journal->kind),
            'sourceUrl' => $journal->kind === 'auto' ? Sources::url($journal->kind, $journal->source_type, $journal->source_id, $paymentId === null ? null : (int) $paymentId, $journal->id) : null,
            'reversal' => $reversal === null ? null : (int) $reversal,
            'canReverse' => $journal->kind === 'manual' && $journal->reverses_journal_id === null && $reversal === null && $this->actor()->can(PermissionName::LedgerManage),
            'debits' => Fils::toDecimal((int) $journal->lines->sum(fn (JournalLine $l) => Fils::fromDecimal($l->debit))),
            'credits' => Fils::toDecimal((int) $journal->lines->sum(fn (JournalLine $l) => Fils::fromDecimal($l->credit))),
        ])->title(__('Journal :id', ['id' => $journal->id]));
    }
}
```

`resources/views/livewire/ledger/journal-show.blade.php`:

```blade
<section class="w-full max-w-5xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Journal :id', ['id' => $journal->id]) }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <flux:text>{{ __('Date') }}</flux:text>
            <div>{{ $journal->date->format('d/m/Y') }}</div>
        </div>
        <div>
            <flux:text>{{ __('Source') }}</flux:text>
            <div class="flex flex-wrap items-center gap-2">
                @if ($sourceUrl)
                    <flux:link :href="$sourceUrl" wire:navigate>{{ $source }}</flux:link>
                @else
                    {{ $source }}
                @endif
                @if ($journal->late_posting)
                    <flux:badge size="sm" color="amber">{{ __('Late posting') }}</flux:badge>
                @endif
            </div>
        </div>
        <div class="sm:col-span-2">
            <flux:text>{{ __('Description') }}</flux:text>
            <div>{{ $journal->description }}</div>
        </div>
        @if ($journal->reverses_journal_id)
            <flux:link :href="route('ledger.journals.show', $journal->reverses_journal_id)" wire:navigate>{{ __('Reverses journal :id', ['id' => $journal->reverses_journal_id]) }}</flux:link>
        @endif
        @if ($reversal)
            <flux:link :href="route('ledger.journals.show', $reversal)" wire:navigate>{{ __('Reversed by journal :id', ['id' => $reversal]) }}</flux:link>
        @endif
    </div>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Account') }}</flux:table.column>
                <flux:table.column>{{ __('Tenant') }}</flux:table.column>
                <flux:table.column>{{ __('Owner') }}</flux:table.column>
                <flux:table.column>{{ __('Building') }}</flux:table.column>
                <flux:table.column>{{ __('Unit') }}</flux:table.column>
                <flux:table.column>{{ __('Memo') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Debit') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Credit') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($journal->lines as $line)
                    <flux:table.row :key="'jl-'.$line->id">
                        <flux:table.cell>{{ $line->account->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $line->customer?->name_en }}</flux:table.cell>
                        <flux:table.cell>{{ $line->owner?->name_en }}</flux:table.cell>
                        <flux:table.cell>{{ $line->building?->code }}</flux:table.cell>
                        <flux:table.cell>{{ $line->unit?->code }}</flux:table.cell>
                        <flux:table.cell>{{ $line->memo }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $line->debit > 0 ? $line->debit : '' }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $line->credit > 0 ? $line->credit : '' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
                <flux:table.row class="font-semibold">
                    <flux:table.cell colspan="6">{{ __('Total') }}</flux:table.cell>
                    <flux:table.cell class="text-end tabular-nums">{{ $debits }}</flux:table.cell>
                    <flux:table.cell class="text-end tabular-nums">{{ $credits }}</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>
    </div>

    @if ($canReverse)
        <form wire:submit="reverse" class="flex flex-wrap items-end gap-2">
            <x-date-input wire:model="reverseDate" :label="__('Reversal date')" />
            <flux:button type="submit" variant="danger" wire:confirm="{{ __('Post the exact opposite of this journal?') }}">{{ __('Reverse') }}</flux:button>
        </form>
    @endif
</section>
```

- [ ] **Step 7: Routes and the sidebar.**
  - In `routes/ledger.php`, add inside the group after the `setup` route:

```php
    Route::livewire('journals/new', Ledger\NewJournal::class)->middleware('can:ledger.manage')->name('journals.create');
    Route::livewire('journals/{journal}', Ledger\JournalShow::class)->middleware('can:ledger.view')->whereNumber('journal')->name('journals.show');
```

  - In `resources/views/layouts/app/sidebar.blade.php`, after the line `                    <flux:sidebar.group :heading="__('Ledger')" class="grid">` insert:

```blade
                        @can('ledger.manage')
                            <flux:sidebar.item icon="pencil-square" :href="route('ledger.journals.create')" :current="request()->routeIs('ledger.journals.create')" wire:navigate>{{ __('New journal') }}</flux:sidebar.item>
                        @endcan
```

- [ ] **Step 8: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "New journal": "قيد جديد",
    "New manual journal": "قيد يدوي جديد",
    "Description": "الوصف",
    "Memo": "ملاحظة",
    "Debits :debits · Credits :credits": "المدين :debits · الدائن :credits",
    "Add line": "إضافة سطر",
    "Post journal": "ترحيل القيد",
    "Journal posted.": "تم ترحيل القيد.",
    "Journal :id": "القيد :id",
    "Source": "المصدر",
    "Late posting": "ترحيل متأخر",
    "Reverses journal :id": "يعكس القيد :id",
    "Reversed by journal :id": "معكوس بالقيد :id",
    "Reversal date": "تاريخ العكس",
    "Post the exact opposite of this journal?": "ترحيل عكس هذا القيد تماماً؟",
    "Reverse": "عكس",
    "Journal reversed.": "تم عكس القيد.",
    "A journal needs at least two lines.": "يحتاج القيد إلى سطرين على الأقل.",
    "Choose an active account.": "اختر حساباً نشطاً.",
    "Enter a debit or a credit on each line.": "أدخل مديناً أو دائناً في كل سطر.",
    "The journal does not balance: debits :debits, credits :credits.": "القيد غير متوازن: المدين :debits، الدائن :credits.",
    "Choose a date on or after :date: earlier days are locked or before the start date.": "اختر تاريخاً في :date أو بعده: الأيام السابقة مقفلة أو قبل تاريخ البدء.",
    "Only a manual journal that is not itself a reversal can be reversed.": "لا يُعكس إلا قيد يدوي ليس هو نفسه قيد عكس.",
    "This journal is already reversed.": "هذا القيد معكوس بالفعل.",
```

- [ ] **Step 9: Run the tests.**

Run: `php artisan test tests/Feature/Ledger`
Expected: PASS.

- [ ] **Step 10: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Actions/Ledger/PostManualJournal.php app/Actions/Ledger/ReverseManualJournal.php app/Livewire/Ledger/NewJournal.php app/Livewire/Ledger/JournalShow.php resources/views/livewire/ledger/new-journal.blade.php resources/views/livewire/ledger/journal-show.blade.php app/Ledger/Sources.php app/Models/JournalLine.php routes/ledger.php resources/views/layouts/app/sidebar.blade.php tests/Feature/Ledger/ManualJournalTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
General ledger manual journals: Finance posts balanced journals on open dates and reverses them once; journal page with dimensions and a link to the source

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Ledger health and the Ledger page (health card, lock, unlock, rebuild with progress)

**Files:**
- Create: `app/Ledger/LedgerHealth.php`, `app/Livewire/Ledger/Home.php`, `resources/views/livewire/ledger/home.blade.php`
- Modify: `app/Console/Commands/LedgerNightlyCommand.php`, `routes/ledger.php`, `resources/views/layouts/app/sidebar.blade.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/LedgerHealthTest.php`

**Interfaces:**
- Consumes: `Rules::load()`, `Poster::pendingCount()`, `OwnerLedger::balance(OwnerContract)`, `LockLedger`, `UnlockLedger`, `RebuildLedger`, `ledgerFixture()`.
- Produces:
  - `LedgerHealth::run(): list<array{check: string, ledger: int, app: int, money: bool}>` (plan ruling 8, in that order; `[]` before the start date).
  - `LedgerHealth::store(): void` (writes `ledger_settings.health` and `health_checked_at`).
  - Route `ledger.index` (`/ledger`); the Home page's public methods `checkNow()`, `lock()`, `unlock()`, `rebuild()` over `lockDate`, `unlockDate`, `fromMonth`, `toMonth`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Ledger/LedgerHealthTest.php`:

```php
<?php

use App\Ledger\LedgerHealth;
use App\Livewire\Ledger\Home;
use App\Models\Customer;
use App\Models\LedgerSetting;
use App\Models\Unit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
    $this->rows = function (): array {
        LedgerHealth::store();

        return collect(LedgerSetting::current()->health)->keyBy('check')->map(fn (array $r) => [$r['ledger'], $r['app']])->all();
    };
});

test('on the worked example every check matches, to the fil', function () {
    expect(($this->rows)())->toBe([
        'Trial balance' => [0, 0],
        'Records not posted' => [0, 0],
        'Tenant receivables' => [0, 0],
        'Tenant credit' => [0, 0],
        'Tenant deposits held' => [400_000, 400_000],
        'Owner payable' => [348_000, 348_000],
        'Vendor payable' => [80_000, 80_000],
        'Invoice lines with no owner contract' => [0, 0],
    ])->and(LedgerSetting::current()->health_checked_at)->not->toBeNull();
});

test('an unpaid managed charge is owed to the owner in the ledger and matches the statements once its uncollected net is added', function () {
    $f = $this->f;
    issuedInvoice($f['sara'], [['net' => '50.000', 'tax' => 'standard', 'type' => 'parking', 'au' => $f['saraAgreement']->agreementUnits->sole()]], '2026-10-20', $f['saraAgreement']);
    $rows = ($this->rows)();

    expect($rows['Tenant receivables'])->toBe([55_000, 55_000])
        ->and($rows['Owner payable'])->toBe([398_000, 398_000]);
});

test('a record written behind the actions shows as a difference until the nightly run posts it', function () {
    $f = $this->f;
    DB::table('payments')->insert(['number' => 'RCT-RAW-1', 'customer_id' => $f['omar']->id, 'received_on' => '2026-10-20', 'method' => 'cash', 'amount' => '25.000',
        'status' => 'confirmed', 'recorded_by' => $f['finance']->id, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $rows = ($this->rows)();

    expect($rows['Records not posted'])->toBe([1, 0])
        ->and($rows['Tenant credit'])->toBe([0, 25_000]);

    $this->artisan('rms:ledger:nightly')->assertExitCode(0);
    $rows = collect(LedgerSetting::current()->health)->keyBy('check');

    expect($rows->every(fn (array $r) => $r['ledger'] === $r['app']))->toBeTrue();
});

test('a unit with no owner contract is counted', function () {
    $f = $this->f;
    $unit = Unit::factory()->for($f['tower'])->create();
    $tenant = Customer::factory()->create();
    $agreement = activeAgreement(['customer_id' => $tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$unit]);
    issuedInvoice($tenant, [['net' => '100.000', 'au' => $agreement->agreementUnits->sole()]], '2026-10-20', $agreement);

    expect(($this->rows)()['Invoice lines with no owner contract'])->toBe([1, 0]);
});

test('the Ledger page shows the health card to Finance and Admin only, and its forms work', function () {
    $f = $this->f;
    Livewire::actingAs($f['finance'])->test(Home::class)
        ->call('checkNow')->assertSee('Owner payable')->assertSee('348.000')
        ->set('lockDate', ['x'])->call('lock')->assertHasErrors('lockDate')
        ->set('lockDate', '2026-10-10')->call('lock')->assertHasNoErrors()
        ->set('fromMonth', '2026-10')->set('toMonth', '2026-10')->call('rebuild')->assertHasNoErrors()
        ->assertSee('Rebuilt 11/10/2026 – 20/10/2026.');
    expect(LedgerSetting::current()->locked_until->toDateString())->toBe('2026-10-10');

    Livewire::actingAs($f['admin'])->test(Home::class)->set('unlockDate', '')->call('unlock')->assertHasNoErrors();
    expect(LedgerSetting::current()->locked_until)->toBeNull();

    Livewire::actingAs($f['admin'])->test(Home::class)->call('lock')->assertForbidden();
    Livewire::actingAs($f['management'])->test(Home::class)->assertForbidden();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Ledger/LedgerHealthTest.php`
Expected: FAIL. `App\Ledger\LedgerHealth` does not exist.

- [ ] **Step 3: Create `app/Ledger/LedgerHealth.php`:**

```php
<?php

namespace App\Ledger;

use App\Billing\OwnerLedger;
use App\Enums\OwnerContractType;
use App\Models\LedgerSetting;
use App\Models\OwnerContract;
use App\Support\Fils;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Spec §4 "Ledger health" (plan ruling 8): the ledger's balances against the app's own figures, tolerance 0. Each row
 * holds the ledger's figure and the app's, in fils (a count when money is false); a row is healthy when they are equal.
 * The app figures follow CustomerCredit, Customer::outstandingFils, the deposits-held report and OwnerLedger, adjusted
 * where the ledger accrues (deposits and managed charges are recognised when invoiced, spec §4).
 *
 * @phpstan-type Row array{check: string, ledger: int, app: int, money: bool}
 */
final class LedgerHealth
{
    /** Not held by an owner: no contract, a leased contract, or a managed one whose deposits the company holds. */
    private const string COMPANY_HELD = "NOT (c.type <=> 'managed' AND c.deposits_held_by <=> 'owner')";

    /** @return list<Row> empty until the start date is set */
    public static function run(): array
    {
        $settings = LedgerSetting::current();
        if ($settings->start_date === null) {
            return [];
        }
        $start = $settings->start_date->toDateString();
        $today = now('Asia/Bahrain')->toDateString();
        $rules = Rules::load();
        $balance = fn (string $key): int => self::fils(DB::table('journal_lines')->where('ledger_account_id', $rules->account($key))->sum(DB::raw('debit - credit')));

        return [
            ['check' => 'Trial balance', 'ledger' => self::fils(DB::table('journal_lines')->sum(DB::raw('debit - credit'))), 'app' => 0, 'money' => true],
            ['check' => 'Records not posted', 'ledger' => Poster::pendingCount(), 'app' => 0, 'money' => false],
            ['check' => 'Tenant receivables', 'ledger' => $balance('receivables'), 'app' => self::receivables(), 'money' => true],
            ['check' => 'Tenant credit', 'ledger' => -$balance('tenant_credit'), 'app' => self::credit(), 'money' => true],
            ['check' => 'Tenant deposits held', 'ledger' => -$balance('deposits_held'), 'app' => self::deposits(), 'money' => true],
            ['check' => 'Owner payable', 'ledger' => -$balance('owner_payable'), 'app' => self::owners($today), 'money' => true],
            ['check' => 'Vendor payable', 'ledger' => self::vendorLedger($rules), 'app' => self::vendorApp($start, $today), 'money' => true],
            ['check' => 'Invoice lines with no owner contract', 'ledger' => self::unattributed($start), 'app' => 0, 'money' => false],
        ];
    }

    public static function store(): void
    {
        LedgerSetting::current()->forceFill(['health' => self::run(), 'health_checked_at' => now()])->save();
    }

    /** Σ balance of issued invoices, credit notes left out (Customer::outstandingFils, every tenant). */
    private static function receivables(): int
    {
        return self::fils(DB::table('invoices')->where('status', 'issued')->where('type', '<>', 'credit_note')->sum('balance'));
    }

    /** CustomerCredit::filsFor, every tenant: confirmed payments − their allocations − paid credit refunds on them. */
    private static function credit(): int
    {
        return self::fils(DB::table('payments')->where('status', 'confirmed')->sum('amount'))
            - self::fils(DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')->where('p.status', 'confirmed')->sum('a.amount'))
            - self::fils(DB::table('disbursements as d')->join('payments as p', 'p.id', '=', 'd.source_id')->where('d.source_type', 'payment')
                ->where('d.purpose', 'credit_refund')->where('d.status', 'paid')->where('p.status', 'confirmed')->sum('d.amount'));
    }

    /** Deposits held by the company (the deposits-held report's movements) + what is still unpaid on its deposit lines. */
    private static function deposits(): int
    {
        return self::fils(DB::table('deposit_movements as m')->leftJoin('owner_contracts as c', 'c.id', '=', 'm.owner_contract_id')->whereRaw(self::COMPANY_HELD)->sum('m.amount'))
            + self::fils(self::depositLines()->whereRaw(self::COMPANY_HELD)->sum(DB::raw('l.total - l.allocated - l.credited')));
    }

    /**
     * The owner statements' balances (cash basis, OwnerLedger) + the net of managed charges billed but not yet collected
     * + unpaid owner-held deposits + leased rent due and not yet paid.
     */
    private static function owners(string $today): int
    {
        $owed = 0;
        foreach (OwnerContract::query()->where('type', OwnerContractType::Managed)->get() as $contract) {
            $owed += OwnerLedger::balance($contract);
        }
        $billed = self::fils(DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')->join('owner_contracts as c', 'c.id', '=', 'l.owner_contract_id')
            ->where('i.status', 'issued')->where('i.type', '<>', 'credit_note')->where('c.type', 'managed')->where('l.charge_type', '<>', 'deposit')->sum('l.net'));
        $credited = self::fils(DB::table('invoice_lines as cl')->join('invoices as cn', 'cn.id', '=', 'cl.invoice_id')
            ->join('invoice_lines as l', 'l.id', '=', 'cl.credited_line_id')->join('owner_contracts as c', 'c.id', '=', 'l.owner_contract_id')
            ->where('cn.status', 'issued')->where('cn.type', 'credit_note')->where('c.type', 'managed')->where('l.charge_type', '<>', 'deposit')->sum('cl.net'));
        $collected = self::fils(DB::table('payment_allocations as a')->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id')
            ->join('owner_contracts as c', 'c.id', '=', 'a.owner_contract_id')
            ->where('c.type', 'managed')->where('l.charge_type', '<>', 'deposit')->sum(DB::raw('a.amount - a.tax_amount')));
        $ownerDeposits = self::fils(self::depositLines()->where('c.type', 'managed')->where('c.deposits_held_by', 'owner')->sum(DB::raw('l.total - l.allocated - l.credited')));
        $leaseDue = self::fils(DB::table('owner_payables')->where('status', '<>', 'cancelled')->where('due_date', '<=', $today)->sum('amount'));
        $leasePaid = self::fils(DB::table('disbursements')->where('purpose', 'head_lease')->where('status', 'paid')->sum('amount'));

        return $owed + $billed - $credited - $collected + $ownerDeposits + $leaseDue - $leasePaid;
    }

    /** What expenses credited to Vendor payable (manual vendor payments left out, plan ruling 11). */
    private static function vendorLedger(Rules $rules): int
    {
        return -self::fils(DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.ledger_account_id', $rules->account('vendor_payable'))->whereIn('j.source_type', ['expense', 'expense_reversal'])
            ->sum(DB::raw('l.debit - l.credit')));
    }

    private static function vendorApp(string $start, string $today): int
    {
        return self::fils(DB::table('expenses')->whereBetween('expense_date', [$start, $today])->sum('total'))
            - self::fils(DB::table('expenses')->where('status', 'reversed')->whereBetween(DB::raw('DATE(reversed_at)'), [$start, $today])->sum('total'));
    }

    /** Plan ruling 2: issued lines on a unit that had no owner contract on their date, posted to company income. */
    private static function unattributed(string $start): int
    {
        return DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('i.status', 'issued')->where('i.type', '<>', 'credit_note')->where('i.issue_date', '>=', $start)
            ->whereNotNull('l.unit_id')->whereNull('l.owner_contract_id')->count();
    }

    private static function depositLines(): Builder
    {
        return DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')->leftJoin('owner_contracts as c', 'c.id', '=', 'l.owner_contract_id')
            ->where('i.status', 'issued')->where('i.type', '<>', 'credit_note')->where('l.charge_type', 'deposit');
    }

    private static function fils(mixed $sum): int
    {
        $value = (string) ($sum ?? '0');

        return Fils::fromDecimal($value === '' ? '0' : $value);
    }
}
```

- [ ] **Step 4: The nightly run stores health.** In `app/Console/Commands/LedgerNightlyCommand.php`:
  - add `use App\Ledger\LedgerHealth;` to the imports;
  - replace the docblock with `/** Plan rulings 1 and 8: posts what fell due (head-lease rent due today) or failed live, then stores Ledger health. */`;
  - replace `protected $description = 'Post pending ledger sources';` with `protected $description = 'Post pending ledger sources and check ledger health';`;
  - after `$this->info("Posted {$tried} pending ledger sources.");` add `LedgerHealth::store();`.

- [ ] **Step 5: Create the Ledger page.** `app/Livewire/Ledger/Home.php`:

```php
<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\LockLedger;
use App\Actions\Ledger\RebuildLedger;
use App\Actions\Ledger\UnlockLedger;
use App\Enums\PermissionName;
use App\Ledger\LedgerHealth;
use App\Livewire\Concerns\WithActor;
use App\Models\LedgerSetting;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** General ledger home (plan rulings 4, 5 and 8): Ledger health, the lock, and rebuilds with their progress. Finance and Admin. */
class Home extends Component
{
    use WithActor;

    // Untyped on purpose: a tampered payload (an array) reaches the action's validator, never a TypeError (a 500).
    public mixed $lockDate = '';

    public mixed $unlockDate = '';

    public mixed $fromMonth = '';

    public mixed $toMonth = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerView), 403);
        $this->fromMonth = $this->toMonth = now('Asia/Bahrain')->format('Y-m');
    }

    public function checkNow(): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerView), 403);
        LedgerHealth::store();
        Flux::toast(variant: 'success', text: __('Ledger health checked.'));
    }

    public function lock(): void
    {
        app(LockLedger::class)->handle($this->actor(), $this->lockDate);
        $this->lockDate = '';
        Flux::toast(variant: 'success', text: __('Locked.'));
    }

    public function unlock(): void
    {
        app(UnlockLedger::class)->handle($this->actor(), $this->unlockDate);
        $this->unlockDate = '';
        Flux::toast(variant: 'success', text: __('Unlocked.'));
    }

    public function rebuild(): void
    {
        app(RebuildLedger::class)->handle($this->actor(), $this->fromMonth, $this->toMonth);
        Flux::toast(variant: 'success', text: __('Rebuild started.'));
    }

    public function render(): View
    {
        $user = $this->actor();
        $settings = LedgerSetting::current();
        $show = fn (int $value, bool $money): string => $money ? Fils::toDecimal($value) : (string) $value;

        return view('livewire.ledger.home', [
            'settings' => $settings,
            'health' => array_map(fn (array $r) => [
                'label' => __($r['check']),
                'ledger' => $show($r['ledger'], $r['money']),
                'app' => $show($r['app'], $r['money']),
                'difference' => $show($r['ledger'] - $r['app'], $r['money']),
                'ok' => $r['ledger'] === $r['app'],
            ], $settings->health ?? []),
            'canManage' => $user->can(PermissionName::LedgerManage),
            'canAdmin' => $user->can(PermissionName::LedgerAdmin),
        ])->title(__('Ledger'));
    }
}
```

`resources/views/livewire/ledger/home.blade.php`:

```blade
<section class="w-full max-w-4xl space-y-8" @if ($settings->rebuilding()) wire:poll.2s @endif>
    <flux:heading size="xl" level="1">{{ __('Ledger') }}</flux:heading>

    <div class="space-y-1">
        <flux:text>{{ $settings->start_date ? __('Start date :date', ['date' => $settings->start_date->format('d/m/Y')]) : __('Not set up yet: Admin sets the start date.') }}</flux:text>
        <flux:text>{{ $settings->locked_until ? __('Locked up to :date', ['date' => $settings->locked_until->format('d/m/Y')]) : __('Nothing is locked.') }}</flux:text>
    </div>

    <flux:card class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg">{{ __('Ledger health') }}</flux:heading>
            <flux:button size="sm" wire:click="checkNow">{{ __('Check now') }}</flux:button>
        </div>
        <flux:text>{{ $settings->health_checked_at ? __('Last checked :when', ['when' => $settings->health_checked_at->format('d/m/Y H:i')]) : __('Not checked yet.') }}</flux:text>
        @if ($health !== [])
            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Check') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('In the ledger') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('In the app') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Difference') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($health as $i => $row)
                            <flux:table.row :key="'h-'.$i" :class="$row['ok'] ? '' : 'text-red-600 dark:text-red-400'">
                                <flux:table.cell>{{ $row['label'] }}</flux:table.cell>
                                <flux:table.cell class="text-end tabular-nums">{{ $row['ledger'] }}</flux:table.cell>
                                <flux:table.cell class="text-end tabular-nums">{{ $row['app'] }}</flux:table.cell>
                                <flux:table.cell class="text-end tabular-nums">{{ $row['difference'] }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </flux:card>

    @if ($canManage)
        <form wire:submit="lock" class="flex flex-wrap items-end gap-2">
            <x-date-input wire:model="lockDate" :label="__('Lock up to')" />
            <flux:button type="submit">{{ __('Lock') }}</flux:button>
        </form>
    @endif

    @if ($canAdmin && $settings->locked_until)
        <form wire:submit="unlock" class="space-y-1">
            <div class="flex flex-wrap items-end gap-2">
                <x-date-input wire:model="unlockDate" :label="__('Unlock back to')" />
                <flux:button type="submit" variant="danger">{{ __('Unlock') }}</flux:button>
            </div>
            <flux:text>{{ __('Leave empty to unlock everything.') }}</flux:text>
        </form>
    @endif

    @if ($canManage || $canAdmin)
        <form wire:submit="rebuild" class="space-y-2">
            <div class="flex flex-wrap items-end gap-2">
                <flux:input type="month" wire:model="fromMonth" :label="__('From month')" />
                <flux:input type="month" wire:model="toMonth" :label="__('To month')" />
                <flux:button type="submit" :disabled="$settings->rebuilding()">{{ __('Rebuild months') }}</flux:button>
            </div>
            <flux:error name="rebuild" />
            @if ($settings->rebuild_status === 'queued')
                <flux:text>{{ __('Rebuild waiting to start.') }}</flux:text>
            @elseif ($settings->rebuild_status === 'running')
                <flux:text>{{ __('Rebuilding: :done of :total records posted.', ['done' => $settings->rebuild_done, 'total' => $settings->rebuild_total]) }}</flux:text>
            @elseif ($settings->rebuild_status === 'done' && $settings->rebuild_from && $settings->rebuild_to)
                <flux:text>{{ __('Rebuilt :from – :to.', ['from' => $settings->rebuild_from->format('d/m/Y'), 'to' => $settings->rebuild_to->format('d/m/Y')]) }}</flux:text>
            @elseif ($settings->rebuild_status === 'failed')
                <flux:text class="text-red-600 dark:text-red-400">{{ __('The last rebuild failed: :message', ['message' => $settings->rebuild_message]) }}</flux:text>
            @endif
        </form>
    @endif
</section>
```

- [ ] **Step 6: Route and the sidebar.**
  - In `routes/ledger.php`, add as the group's first line:

```php
    Route::livewire('/', Ledger\Home::class)->middleware('can:ledger.view')->name('index');
```

  - In `resources/views/layouts/app/sidebar.blade.php`, after the line `                    <flux:sidebar.group :heading="__('Ledger')" class="grid">` insert:

```blade
                        @can('ledger.view')
                            <flux:sidebar.item icon="book-open" :href="route('ledger.index')" :current="request()->routeIs('ledger.index')" wire:navigate>{{ __('Ledger health') }}</flux:sidebar.item>
                        @endcan
```

- [ ] **Step 7: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Ledger health": "سلامة الدفاتر",
    "Trial balance": "ميزان المراجعة",
    "Records not posted": "سجلات غير مرحّلة",
    "Invoice lines with no owner contract": "بنود فواتير بلا عقد مالك",
    "Start date :date": "تاريخ البدء :date",
    "Not set up yet: Admin sets the start date.": "لم تُجهَّز بعد: يحدد المسؤول تاريخ البدء.",
    "Locked up to :date": "مقفلة حتى :date",
    "Nothing is locked.": "لا شيء مقفل.",
    "Check now": "افحص الآن",
    "Last checked :when": "آخر فحص :when",
    "Not checked yet.": "لم يُفحص بعد.",
    "Check": "الفحص",
    "In the ledger": "في الدفاتر",
    "In the app": "في النظام",
    "Difference": "الفرق",
    "Lock up to": "إقفال حتى",
    "Lock": "إقفال",
    "Unlock back to": "فتح حتى",
    "Leave empty to unlock everything.": "اتركه فارغاً لفتح كل الفترات.",
    "Unlock": "فتح",
    "From month": "من شهر",
    "To month": "إلى شهر",
    "Rebuild months": "إعادة بناء الأشهر",
    "Rebuild waiting to start.": "إعادة البناء بانتظار البدء.",
    "Rebuilding: :done of :total records posted.": "جارٍ إعادة البناء: رُحّل :done من :total سجلاً.",
    "Rebuilt :from – :to.": "أعيد البناء :from – :to.",
    "The last rebuild failed: :message": "فشلت آخر إعادة بناء: :message",
    "Ledger health checked.": "تم فحص سلامة الدفاتر.",
    "Locked.": "تم الإقفال.",
    "Unlocked.": "تم الفتح.",
```

- [ ] **Step 8: Run the tests.**

Run: `php artisan test tests/Feature/Ledger`
Expected: PASS.

- [ ] **Step 9: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Ledger/LedgerHealth.php app/Livewire/Ledger/Home.php resources/views/livewire/ledger/home.blade.php app/Console/Commands/LedgerNightlyCommand.php routes/ledger.php resources/views/layouts/app/sidebar.blade.php tests/Feature/Ledger/LedgerHealthTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
General ledger health: receivables, credit, deposits, owner and vendor payable, unposted records and unattributed lines checked nightly at tolerance 0; Ledger page with lock, unlock and rebuild progress

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Reports: trial balance, balance sheet, profit and loss, general ledger, journal list

**Files:**
- Create: `app/Ledger/Balances.php`
- Create: `app/Livewire/Reports/TrialBalanceReport.php`, `BalanceSheetReport.php`, `ProfitAndLossReport.php`, `GeneralLedgerReport.php`, `JournalListReport.php`
- Modify: `routes/ledger.php`, `app/Livewire/Reports/Index.php`, `resources/views/layouts/app/sidebar.blade.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/LedgerReportsTest.php`

**Interfaces:**
- Consumes: `ReportPage` (columns/rows/numeric/dateMode/buildingFilter/options; `export()` through `SafeCells`; static `sheet(User, array)`), `Sources::label()`, `Sources::url()`, `LedgerAccountType::sign()`, `LedgerAccount::name()`/`label()`, `ledgerFixture()`.
- Produces:
  - `Balances::net(?string $from, string $to): array<int, int>` (account id ⇒ Dr − Cr in fils).
  - `Balances::lines(string $from, string $to): Illuminate\Database\Query\Builder` (rows with `journal`, `date`, `kind`, `source_type`, `source_id`, `description`, `code`, `name_en`, `name_ar`, `ledger_account_id`, `debit`, `credit`, `payment_id`).
  - `Balances::url(stdClass $line): string`.
  - Routes `ledger.trial-balance`, `ledger.balance-sheet`, `ledger.profit-and-loss`, `ledger.general-ledger`, `ledger.journals`. Excel files `trial_balance-<to>.xlsx`, `balance_sheet-…`, `profit_and_loss-…`, `general_ledger-…`, `journal_list-…`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Ledger/LedgerReportsTest.php`:

```php
<?php

use App\Livewire\Reports\BalanceSheetReport;
use App\Livewire\Reports\GeneralLedgerReport;
use App\Livewire\Reports\Index;
use App\Livewire\Reports\JournalListReport;
use App\Livewire\Reports\ProfitAndLossReport;
use App\Livewire\Reports\TrialBalanceReport;
use App\Models\LedgerAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
});

test('the trial balance at 20/10/2026 lists the worked example, debits equal to credits, and downloads', function () {
    $f = $this->f;

    expect(TrialBalanceReport::sheet($f['finance'], ['to' => '2026-10-20'])['rows'])->toBe([
        ['1000', 'Cash on hand', '840.000', ''],
        ['1010', 'Bank', '', '300.000'],
        ['1020', 'Card clearing', '300.000', ''],
        ['2000', 'Tenant deposits held', '', '400.000'],
        ['2100', 'Owner payable', '', '348.000'],
        ['2200', 'Vendor payable', '', '80.000'],
        ['2400', 'VAT payable', '', '42.000'],
        ['4000', 'Rent income', '', '300.000'],
        ['4200', 'Management fee income', '', '20.000'],
        ['5000', 'Lease rent to owners', '300.000', ''],
        ['5100', 'Maintenance and repairs', '50.000', ''],
        ['', 'Total', '1490.000', '1490.000'],
    ])->and(TrialBalanceReport::sheet($f['finance'], ['to' => '2026-10-04'])['rows'])->toBe([
        ['1100', 'Tenant receivables', '1140.000', ''],
        ['2000', 'Tenant deposits held', '', '400.000'],
        ['2100', 'Owner payable', '', '700.000'],
        ['2400', 'VAT payable', '', '40.000'],
        ['4000', 'Rent income', '', '300.000'],
        ['5000', 'Lease rent to owners', '300.000', ''],
        ['', 'Total', '1440.000', '1440.000'],
    ]);

    Livewire::actingAs($f['finance'])->test(TrialBalanceReport::class)->set('to', '2026-10-20')
        ->assertSeeInOrder(['Cash on hand', '840.000', 'Total', '1490.000'])
        ->call('export')->assertFileDownloaded('trial_balance-2026-10-20.xlsx');
});

test('the balance sheet puts the current-year loss inside equity and balances', function () {
    expect(BalanceSheetReport::sheet($this->f['finance'], ['to' => '2026-10-20'])['rows'])->toBe([
        ['', 'Assets', ''],
        ['1000', 'Cash on hand', '840.000'],
        ['1010', 'Bank', '-300.000'],
        ['1020', 'Card clearing', '300.000'],
        ['', 'Total assets', '840.000'],
        ['', 'Liabilities', ''],
        ['2000', 'Tenant deposits held', '400.000'],
        ['2100', 'Owner payable', '348.000'],
        ['2200', 'Vendor payable', '80.000'],
        ['2400', 'VAT payable', '42.000'],
        ['', 'Total liabilities', '870.000'],
        ['', 'Equity', ''],
        ['', "Earlier years' profit", '0.000'],
        ['', 'Current-year profit', '-30.000'],
        ['', 'Total equity', '-30.000'],
        ['', 'Total liabilities and equity', '840.000'],
    ]);
});

test('profit and loss for October comes from the ledger', function () {
    expect(ProfitAndLossReport::sheet($this->f['finance'], ['from' => '2026-10-01', 'to' => '2026-10-20'])['rows'])->toBe([
        ['', 'Income', ''],
        ['4000', 'Rent income', '300.000'],
        ['4200', 'Management fee income', '20.000'],
        ['', 'Total income', '320.000'],
        ['', 'Expenses', ''],
        ['5000', 'Lease rent to owners', '300.000'],
        ['5100', 'Maintenance and repairs', '50.000'],
        ['', 'Total expenses', '350.000'],
        ['', 'Profit for the period', '-30.000'],
    ]);
});

test('the general ledger for Owner payable runs from opening to closing, each line linking to its source', function () {
    $f = $this->f;
    $account = (string) LedgerAccount::query()->where('code', '2100')->value('id');
    $rows = GeneralLedgerReport::sheet($f['finance'], ['from' => '2026-10-01', 'to' => '2026-10-20', 'account' => $account])['rows'];

    expect(array_map(fn (array $r) => [$r[0], $r[1], $r[3], $r[4], $r[5]], $rows))->toBe([
        ['', '', '', '', '0.000'],
        ['01/10/2026', 'Invoice', '', '400.000', '-400.000'],
        ['01/10/2026', 'Head-lease rent due', '', '300.000', '-700.000'],
        ['08/10/2026', 'Expense', '30.000', '', '-670.000'],
        ['10/10/2026', 'Payment out', '300.000', '', '-370.000'],
        ['15/10/2026', 'Owner charge', '22.000', '', '-348.000'],
        ['', '', '', '', '-348.000'],
    ])->and($rows[2][2])->toBe("{$f['leased']->number} 01/10/2026–31/10/2026");

    Livewire::actingAs($f['finance'])->test(GeneralLedgerReport::class)->set('account', $account)->set('from', '2026-10-01')->set('to', '2026-10-20')
        ->assertSeeHtml(route('invoices.show', $f['saraRent']->id))
        ->assertSeeHtml(route('disbursements.show', $f['headLease']->id))
        ->assertSeeHtml(route('expenses.show', $f['ownerExpense']->id))
        ->call('export')->assertFileDownloaded('general_ledger-2026-10-20.xlsx');
});

test('the journal list filters by source and account and totals its lines', function () {
    $f = $this->f;
    $expenses = JournalListReport::sheet($f['finance'], ['from' => '2026-10-01', 'to' => '2026-10-20', 'source' => 'expense', 'account' => 'all'])['rows'];
    $cash = (string) LedgerAccount::query()->where('code', '1000')->value('id');
    $onCash = JournalListReport::sheet($f['finance'], ['from' => '2026-10-01', 'to' => '2026-10-20', 'source' => 'all', 'account' => $cash])['rows'];

    expect($expenses)->toHaveCount(5)
        ->and($expenses[0][2])->toBe('Expense')
        ->and($expenses[4])->toBe(['', '', '', '', 'Total', '80.000', '80.000'])
        ->and($onCash[array_key_last($onCash)])->toBe(['', '', '', '', 'Total', '900.000', '60.000']);
});

test('the ledger reports are for Finance and Admin only, and are listed with the other reports', function () {
    $f = $this->f;
    Livewire::actingAs($f['management'])->test(TrialBalanceReport::class)->assertForbidden();
    Livewire::actingAs($f['admin'])->test(BalanceSheetReport::class)->assertSee('Balance sheet');
    Livewire::actingAs($f['finance'])->test(Index::class)->assertSee('Trial balance')->assertSee('Journal list');
    Livewire::actingAs($f['management'])->test(Index::class)->assertDontSee('Trial balance');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Ledger/LedgerReportsTest.php`
Expected: FAIL. `App\Livewire\Reports\TrialBalanceReport` does not exist.

- [ ] **Step 3: Create `app/Ledger/Balances.php`:**

```php
<?php

namespace App\Ledger;

use App\Support\Fils;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/** The ledger reports' two queries (plan ruling 9): balances per account, and lines in date order with what their source link needs. */
final class Balances
{
    /** @return array<int, int> ledger account id => Σ debit − Σ credit in fils, journals dated $from..$to ($from null = from the beginning) */
    public static function net(?string $from, string $to): array
    {
        $net = [];
        foreach (DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->when($from, fn (Builder $q, string $f) => $q->where('j.date', '>=', $f))->where('j.date', '<=', $to)
            ->groupBy('l.ledger_account_id')->selectRaw('l.ledger_account_id AS account, SUM(l.debit) - SUM(l.credit) AS net')->get() as $row) {
            $net[(int) $row->account] = Fils::fromDecimal((string) $row->net);
        }

        return $net;
    }

    /** Journal lines dated $from..$to with their journal and account, oldest first. */
    public static function lines(string $from, string $to): Builder
    {
        return DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->leftJoin('payment_allocations as pa', fn ($join) => $join->on('pa.id', '=', 'j.source_id')->where('j.source_type', '=', 'allocation'))
            ->whereBetween('j.date', [$from, $to])
            ->orderBy('j.date')->orderBy('j.id')->orderBy('l.id')
            ->select(['j.id AS journal', 'j.date', 'j.kind', 'j.source_type', 'j.source_id', 'j.description', 'a.code', 'a.name_en', 'a.name_ar',
                'l.ledger_account_id', 'l.debit', 'l.credit', 'pa.payment_id']);
    }

    /** A line's click-through (plan ruling 9). */
    public static function url(stdClass $line): string
    {
        return Sources::url((string) $line->kind, $line->source_type === null ? null : (string) $line->source_type, $line->source_id === null ? null : (int) $line->source_id,
            $line->payment_id === null ? null : (int) $line->payment_id, (int) $line->journal);
    }
}
```

- [ ] **Step 4: Create the five reports.** `app/Livewire/Reports/TrialBalanceReport.php`:

```php
<?php

namespace App\Livewire\Reports;

use App\Enums\PermissionName;
use App\Ledger\Balances;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\LedgerAccount;
use App\Reports\Packages\PackageReport;
use App\Support\Fils;
use Livewire\Component;

/** General ledger spec §6: every account's balance at a date, as a debit or a credit; the two totals are equal. */
class TrialBalanceReport extends Component implements PackageReport
{
    use ReportPage;

    protected function title(): string
    {
        return __('Trial balance');
    }

    protected function permission(): bool
    {
        return $this->actor()->can(PermissionName::LedgerView);
    }

    protected function dateMode(): string
    {
        return 'single';
    }

    protected function buildingFilter(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['code' => __('Code'), 'account' => __('Account'), 'debit' => __('Debit'), 'credit' => __('Credit')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['debit', 'credit'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $net = Balances::net(null, $this->to);
        $rows = [];
        $debits = 0;
        $credits = 0;
        foreach (LedgerAccount::query()->orderBy('code')->get() as $account) {
            $n = $net[$account->id] ?? 0;
            if ($n === 0) {
                continue;
            }
            $rows[] = ['code' => $account->code, 'account' => $account->name(), 'debit' => $n > 0 ? Fils::toDecimal($n) : '', 'credit' => $n < 0 ? Fils::toDecimal(-$n) : ''];
            $debits += max(0, $n);
            $credits += max(0, -$n);
        }
        $rows[] = ['code' => '', 'account' => __('Total'), 'debit' => Fils::toDecimal($debits), 'credit' => Fils::toDecimal($credits), '_kind' => 'total'];

        return $rows;
    }
}
```

`app/Livewire/Reports/BalanceSheetReport.php`:

```php
<?php

namespace App\Livewire\Reports;

use App\Enums\LedgerAccountType;
use App\Enums\PermissionName;
use App\Ledger\Balances;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\LedgerAccount;
use App\Reports\Packages\PackageReport;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Livewire\Component;

/**
 * General ledger spec §6: assets, liabilities and equity at a date. No year-end closing journals: equity shows the
 * profit of earlier years and of the current year, so the two sides always agree.
 */
class BalanceSheetReport extends Component implements PackageReport
{
    use ReportPage;

    protected function title(): string
    {
        return __('Balance sheet');
    }

    protected function permission(): bool
    {
        return $this->actor()->can(PermissionName::LedgerView);
    }

    protected function dateMode(): string
    {
        return 'single';
    }

    protected function buildingFilter(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['code' => __('Code'), 'account' => __('Account'), 'amount' => __('Amount')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['amount'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $accounts = LedgerAccount::query()->orderBy('code')->get();
        $net = Balances::net(null, $this->to);
        $before = Balances::net(null, CarbonImmutable::parse($this->to)->startOfYear()->subDay()->toDateString());
        $profit = function (array $balances) use ($accounts): int {
            $sum = 0;
            foreach ($accounts as $account) {
                if (in_array($account->type, [LedgerAccountType::Income, LedgerAccountType::Expense], true)) {
                    $sum -= $balances[$account->id] ?? 0;
                }
            }

            return $sum;
        };
        $earlier = $profit($before);
        $current = $profit($net) - $earlier;

        $rows = [];
        $totals = [];
        foreach ([
            [LedgerAccountType::Asset, __('Assets'), __('Total assets')],
            [LedgerAccountType::Liability, __('Liabilities'), __('Total liabilities')],
            [LedgerAccountType::Equity, __('Equity'), __('Total equity')],
        ] as [$type, $heading, $label]) {
            $rows[] = ['code' => '', 'account' => $heading, 'amount' => '', '_kind' => 'total'];
            $sum = 0;
            foreach ($accounts->where('type', $type) as $account) {
                $amount = $type->sign() * ($net[$account->id] ?? 0);
                if ($amount === 0) {
                    continue;
                }
                $rows[] = ['code' => $account->code, 'account' => $account->name(), 'amount' => Fils::toDecimal($amount)];
                $sum += $amount;
            }
            if ($type === LedgerAccountType::Equity) {
                $rows[] = ['code' => '', 'account' => __("Earlier years' profit"), 'amount' => Fils::toDecimal($earlier)];
                $rows[] = ['code' => '', 'account' => __('Current-year profit'), 'amount' => Fils::toDecimal($current)];
                $sum += $earlier + $current;
            }
            $rows[] = ['code' => '', 'account' => $label, 'amount' => Fils::toDecimal($sum), '_kind' => 'total'];
            $totals[$type->value] = $sum;
        }
        $rows[] = ['code' => '', 'account' => __('Total liabilities and equity'), 'amount' => Fils::toDecimal($totals['liability'] + $totals['equity']), '_kind' => 'total'];

        return $rows;
    }
}
```

`app/Livewire/Reports/ProfitAndLossReport.php`:

```php
<?php

namespace App\Livewire\Reports;

use App\Enums\LedgerAccountType;
use App\Enums\PermissionName;
use App\Ledger\Balances;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\LedgerAccount;
use App\Reports\Packages\PackageReport;
use App\Support\Fils;
use Livewire\Component;

/** General ledger spec §6: income and expenses for a period, from the ledger (the existing income statement stays). */
class ProfitAndLossReport extends Component implements PackageReport
{
    use ReportPage;

    protected function title(): string
    {
        return __('Profit and loss');
    }

    protected function permission(): bool
    {
        return $this->actor()->can(PermissionName::LedgerView);
    }

    protected function buildingFilter(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['code' => __('Code'), 'account' => __('Account'), 'amount' => __('Amount')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['amount'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $accounts = LedgerAccount::query()->orderBy('code')->get();
        $net = Balances::net($this->from, $this->to);
        $rows = [];
        $totals = [];
        foreach ([
            [LedgerAccountType::Income, __('Income'), __('Total income')],
            [LedgerAccountType::Expense, __('Expenses'), __('Total expenses')],
        ] as [$type, $heading, $label]) {
            $rows[] = ['code' => '', 'account' => $heading, 'amount' => '', '_kind' => 'total'];
            $sum = 0;
            foreach ($accounts->where('type', $type) as $account) {
                $amount = $type->sign() * ($net[$account->id] ?? 0);
                if ($amount === 0) {
                    continue;
                }
                $rows[] = ['code' => $account->code, 'account' => $account->name(), 'amount' => Fils::toDecimal($amount)];
                $sum += $amount;
            }
            $rows[] = ['code' => '', 'account' => $label, 'amount' => Fils::toDecimal($sum), '_kind' => 'total'];
            $totals[$type->value] = $sum;
        }
        $rows[] = ['code' => '', 'account' => __('Profit for the period'), 'amount' => Fils::toDecimal($totals['income'] - $totals['expense']), '_kind' => 'total'];

        return $rows;
    }
}
```

`app/Livewire/Reports/GeneralLedgerReport.php`:

```php
<?php

namespace App\Livewire\Reports;

use App\Enums\PermissionName;
use App\Ledger\Balances;
use App\Ledger\Sources;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\LedgerAccount;
use App\Reports\Packages\PackageReport;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Component;

/** General ledger spec §6: one account for a period: the opening balance, each line linking to its source, a running Dr − Cr balance. */
class GeneralLedgerReport extends Component implements PackageReport
{
    use ReportPage;

    #[Url]
    public string $account = '';

    public function mount(): void
    {
        $this->account = $this->account !== '' ? $this->account : (string) (LedgerAccount::query()->orderBy('code')->value('id') ?? '');
    }

    protected function title(): string
    {
        return __('General ledger');
    }

    protected function permission(): bool
    {
        return $this->actor()->can(PermissionName::LedgerView);
    }

    protected function buildingFilter(): bool
    {
        return false;
    }

    /** @return array<string, array<array-key, string>> */
    protected function options(): array
    {
        return ['account' => LedgerAccount::query()->orderBy('code')->get()->mapWithKeys(fn (LedgerAccount $a) => [$a->id => $a->label()])->all()];
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['date' => __('Date'), 'source' => __('Source'), 'reference' => __('Reference'), 'debit' => __('Debit'), 'credit' => __('Credit'), 'balance' => __('Balance')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['debit', 'credit', 'balance'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $account = (int) $this->account;
        $balance = Balances::net(null, CarbonImmutable::parse($this->from)->subDay()->toDateString())[$account] ?? 0;
        $rows = [['date' => '', 'source' => '', 'reference' => __('Opening balance'), 'debit' => '', 'credit' => '', 'balance' => Fils::toDecimal($balance), '_kind' => 'total']];

        foreach (Balances::lines($this->from, $this->to)->where('l.ledger_account_id', $account)->get() as $line) {
            $debit = Fils::fromDecimal((string) $line->debit);
            $credit = Fils::fromDecimal((string) $line->credit);
            $balance += $debit - $credit;
            $rows[] = [
                '_url' => Balances::url($line),
                'date' => CarbonImmutable::parse((string) $line->date)->format('d/m/Y'),
                'source' => Sources::label((string) ($line->source_type ?? $line->kind)),
                'reference' => (string) $line->description,
                'debit' => $debit > 0 ? Fils::toDecimal($debit) : '',
                'credit' => $credit > 0 ? Fils::toDecimal($credit) : '',
                'balance' => Fils::toDecimal($balance),
            ];
        }
        $rows[] = ['date' => '', 'source' => '', 'reference' => __('Closing balance'), 'debit' => '', 'credit' => '', 'balance' => Fils::toDecimal($balance), '_kind' => 'total'];

        return $rows;
    }
}
```

`app/Livewire/Reports/JournalListReport.php`:

```php
<?php

namespace App\Livewire\Reports;

use App\Enums\PermissionName;
use App\Ledger\Balances;
use App\Ledger\Sources;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\LedgerAccount;
use App\Reports\Packages\PackageReport;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Component;

/** General ledger spec §6: every journal's lines for a period, filtered by source and account, each linking to its source. */
class JournalListReport extends Component implements PackageReport
{
    use ReportPage;

    #[Url]
    public string $source = 'all';

    #[Url]
    public string $account = 'all';

    protected function title(): string
    {
        return __('Journal list');
    }

    protected function permission(): bool
    {
        return $this->actor()->can(PermissionName::LedgerView);
    }

    protected function buildingFilter(): bool
    {
        return false;
    }

    /** @return array<string, array<array-key, string>> */
    protected function options(): array
    {
        $sources = ['all' => __('All sources'), 'manual' => Sources::label('manual'), 'opening' => Sources::label('opening')];
        foreach (array_keys(Sources::TYPES) as $type) {
            $sources[$type] = Sources::label($type);
        }

        return [
            'source' => $sources,
            'account' => ['all' => __('All accounts')] + LedgerAccount::query()->orderBy('code')->get()->mapWithKeys(fn (LedgerAccount $a) => [$a->id => $a->label()])->all(),
        ];
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['date' => __('Date'), 'journal' => __('Journal'), 'source' => __('Source'), 'description' => __('Description'), 'account' => __('Account'),
            'debit' => __('Debit'), 'credit' => __('Credit')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['debit', 'credit'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $query = Balances::lines($this->from, $this->to)
            ->when($this->account !== 'all', fn ($q) => $q->where('l.ledger_account_id', (int) $this->account))
            ->when(in_array($this->source, ['manual', 'opening'], true), fn ($q) => $q->where('j.kind', $this->source))
            ->when(! in_array($this->source, ['all', 'manual', 'opening'], true), fn ($q) => $q->where('j.source_type', $this->source));
        $rows = [];
        $debits = 0;
        $credits = 0;
        foreach ($query->get() as $line) {
            $debit = Fils::fromDecimal((string) $line->debit);
            $credit = Fils::fromDecimal((string) $line->credit);
            $debits += $debit;
            $credits += $credit;
            $rows[] = [
                '_url' => Balances::url($line),
                'date' => CarbonImmutable::parse((string) $line->date)->format('d/m/Y'),
                'journal' => (string) $line->journal,
                'source' => Sources::label((string) ($line->source_type ?? $line->kind)),
                'description' => (string) $line->description,
                'account' => $line->code.' '.(app()->getLocale() === 'ar' ? $line->name_ar : $line->name_en),
                'debit' => $debit > 0 ? Fils::toDecimal($debit) : '',
                'credit' => $credit > 0 ? Fils::toDecimal($credit) : '',
            ];
        }
        $rows[] = ['date' => '', 'journal' => '', 'source' => '', 'description' => '', 'account' => __('Total'), 'debit' => Fils::toDecimal($debits), 'credit' => Fils::toDecimal($credits), '_kind' => 'total'];

        return $rows;
    }
}
```

- [ ] **Step 5: Routes, the reports page and the sidebar.**
  - In `routes/ledger.php`, add `use App\Livewire\Reports;` to the imports and, inside the group after the `journals.show` route:

```php
    Route::livewire('trial-balance', Reports\TrialBalanceReport::class)->middleware('can:ledger.view')->name('trial-balance');
    Route::livewire('balance-sheet', Reports\BalanceSheetReport::class)->middleware('can:ledger.view')->name('balance-sheet');
    Route::livewire('profit-and-loss', Reports\ProfitAndLossReport::class)->middleware('can:ledger.view')->name('profit-and-loss');
    Route::livewire('general-ledger', Reports\GeneralLedgerReport::class)->middleware('can:ledger.view')->name('general-ledger');
    Route::livewire('journals', Reports\JournalListReport::class)->middleware('can:ledger.view')->name('journals');
```

  - In `app/Livewire/Reports/Index.php`, in `catalogue()`, after the `'financial' => [ … ],` entry add:

```php
            'ledger' => [
                ['route' => 'ledger.trial-balance', 'label' => __('Trial balance'), 'icon' => 'scale', 'about' => __('Every account\'s balance at a date; debits equal credits.')],
                ['route' => 'ledger.balance-sheet', 'label' => __('Balance sheet'), 'icon' => 'building-library', 'about' => __('Assets, liabilities and equity at a date, with this year\'s profit.')],
                ['route' => 'ledger.profit-and-loss', 'label' => __('Profit and loss'), 'icon' => 'presentation-chart-line', 'about' => __('Income and expenses from the ledger for a period.')],
                ['route' => 'ledger.general-ledger', 'label' => __('General ledger'), 'icon' => 'book-open', 'about' => __('One account\'s postings for a period, with a running balance.')],
                ['route' => 'ledger.journals', 'label' => __('Journal list'), 'icon' => 'queue-list', 'about' => __('Every journal and its lines, by date, source or account.')],
            ],
```

  and in `render()` replace `            __('Financial') => $user->can('reports.financial') ? self::catalogue()['financial'] : [],` with:

```php
            __('Financial') => $user->can('reports.financial') ? self::catalogue()['financial'] : [],
            __('Ledger') => $user->can('ledger.view') ? self::catalogue()['ledger'] : [],
```

  - In `resources/views/layouts/app/sidebar.blade.php`, after the line `                            <flux:sidebar.item icon="book-open" :href="route('ledger.index')" :current="request()->routeIs('ledger.index')" wire:navigate>{{ __('Ledger health') }}</flux:sidebar.item>` insert:

```blade
                            <flux:sidebar.item icon="scale" :href="route('ledger.trial-balance')" :current="request()->routeIs('ledger.trial-balance')" wire:navigate>{{ __('Trial balance') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="book-open" :href="route('ledger.general-ledger')" :current="request()->routeIs('ledger.general-ledger')" wire:navigate>{{ __('General ledger') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="queue-list" :href="route('ledger.journals')" :current="request()->routeIs('ledger.journals')" wire:navigate>{{ __('Journal list') }}</flux:sidebar.item>
```

- [ ] **Step 6: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Balance sheet": "الميزانية العمومية",
    "Profit and loss": "الأرباح والخسائر",
    "General ledger": "دفتر الأستاذ العام",
    "Journal list": "قائمة القيود",
    "Amount": "المبلغ",
    "Assets": "الأصول",
    "Liabilities": "الالتزامات",
    "Total assets": "إجمالي الأصول",
    "Total liabilities": "إجمالي الالتزامات",
    "Total equity": "إجمالي حقوق الملكية",
    "Earlier years' profit": "أرباح السنوات السابقة",
    "Current-year profit": "ربح السنة الحالية",
    "Total liabilities and equity": "إجمالي الالتزامات وحقوق الملكية",
    "Total income": "إجمالي الإيرادات",
    "Total expenses": "إجمالي المصروفات",
    "Profit for the period": "ربح الفترة",
    "Reference": "المرجع",
    "Journal": "القيد",
    "All sources": "كل المصادر",
    "All accounts": "كل الحسابات",
    "Every account's balance at a date; debits equal credits.": "رصيد كل حساب في تاريخ معين؛ المدين يساوي الدائن.",
    "Assets, liabilities and equity at a date, with this year's profit.": "الأصول والالتزامات وحقوق الملكية في تاريخ معين، مع ربح هذه السنة.",
    "Income and expenses from the ledger for a period.": "الإيرادات والمصروفات من الدفاتر لفترة معينة.",
    "One account's postings for a period, with a running balance.": "ترحيلات حساب واحد لفترة معينة، مع رصيد متراكم.",
    "Every journal and its lines, by date, source or account.": "كل قيد وبنوده، حسب التاريخ أو المصدر أو الحساب.",
```

- [ ] **Step 7: Run the tests.**

Run: `php artisan test tests/Feature/Ledger tests/Feature/Reports tests/Feature/ReportPackages`
Expected: PASS. `ReadyMade::REPORTS` still has 16 entries; the ledger reports are reached through the ledger routes and the "Journal lines" subject (Task 10).

- [ ] **Step 8: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Ledger/Balances.php app/Livewire/Reports/TrialBalanceReport.php app/Livewire/Reports/BalanceSheetReport.php app/Livewire/Reports/ProfitAndLossReport.php app/Livewire/Reports/GeneralLedgerReport.php app/Livewire/Reports/JournalListReport.php routes/ledger.php app/Livewire/Reports/Index.php resources/views/layouts/app/sidebar.blade.php tests/Feature/Ledger/LedgerReportsTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
General ledger reports: trial balance, balance sheet with current-year profit, profit and loss, general ledger with running balance and source links, journal list; Excel through SafeCells

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: The "Journal lines" report-builder subject, the access and Arabic sweep, and the full suite

**Files:**
- Create: `app/Reports/Builder/Subjects/JournalLines.php`
- Modify: `app/Reports/Builder/Registry.php`, `tests/Feature/ReportBuilder/SubjectQueriesTest.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/JournalLinesSubjectTest.php`, `tests/Feature/Ledger/LedgerAccessTest.php`

**Interfaces:**
- Consumes: `Subject`, `Field`, `Registry`, `runReport()`, `Sources::TYPES`, `Sources::label()`, `LedgerAccountType`, every ledger route from Tasks 6–9, `ledgerFixture()`.
- Produces: the subject `journal_lines` (13th; permission `ledger.view`; period field `date`; building filter `journal_lines.building_id`).

- [ ] **Step 1: Write the failing tests.** `tests/Feature/Ledger/JournalLinesSubjectTest.php`:

```php
<?php

use App\Reports\Builder\Registry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
});

test('the Journal lines subject reports the ledger to ledger.view holders, with dimensions and totals', function () {
    $f = $this->f;
    $ownerPayable = runReport($f['finance'], ['subject' => 'journal_lines', 'columns' => ['date', 'account_code', 'owner', 'debit', 'credit'],
        'filters' => [['field' => 'account_code', 'op' => 'is', 'value' => '2100']], 'totals' => ['debit' => 'sum', 'credit' => 'sum']]);
    $tower = runReport($f['admin'], ['subject' => 'journal_lines', 'columns' => ['account_code', 'debit', 'credit'], 'building_id' => $f['tower']->id,
        'filters' => [['field' => 'source', 'op' => 'in', 'value' => ['expense']]], 'totals' => ['debit' => 'sum']]);

    expect($ownerPayable->rowCount)->toBe(5)
        ->and($ownerPayable->grandTotals['debit:sum'])->toBe('352.000')
        ->and($ownerPayable->grandTotals['credit:sum'])->toBe('700.000')
        ->and($tower->rowCount)->toBe(4)
        ->and($tower->grandTotals['debit:sum'])->toBe('80.000')
        ->and(array_keys(Registry::forUser($f['finance'])))->toContain('journal_lines')
        ->and(array_keys(Registry::forUser($f['management'])))->not->toContain('journal_lines');
});
```

`tests/Feature/Ledger/LedgerAccessTest.php`:

```php
<?php

use App\Enums\RoleName;
use App\Models\Journal;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
});

test('only Finance and Admin reach the ledger pages, each to its own', function () {
    $f = $this->f;
    $journal = Journal::query()->orderBy('id')->firstOrFail();
    $pages = ['ledger.index' => [], 'ledger.trial-balance' => [], 'ledger.balance-sheet' => [], 'ledger.profit-and-loss' => [], 'ledger.general-ledger' => [],
        'ledger.journals' => [], 'ledger.journals.show' => ['journal' => $journal->id], 'ledger.journals.create' => [], 'ledger.accounts' => [], 'ledger.setup' => []];
    $adminOnly = ['ledger.accounts', 'ledger.setup'];
    $financeOnly = ['ledger.journals.create'];
    $leasing = matrixUser(RoleName::Leasing, $f['tower']);

    foreach ($pages as $name => $parameters) {
        $this->actingAs($f['management'])->get(route($name, $parameters))->assertForbidden();
        $this->actingAs($leasing)->get(route($name, $parameters))->assertForbidden();
        $this->actingAs($f['finance'])->get(route($name, $parameters))->assertStatus(in_array($name, $adminOnly, true) ? 403 : 200);
        $this->actingAs($f['admin'])->get(route($name, $parameters))->assertStatus(in_array($name, $financeOnly, true) ? 403 : 200);
    }
    $this->actingAs($f['finance'])->get('/ledger/journals/abc')->assertNotFound();
});

test('every string the ledger shows has one Arabic translation, and ar.json has no duplicate keys', function () {
    $json = (string) file_get_contents(lang_path('ar.json'));
    $ar = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    preg_match_all('/^\s*"((?:[^"\\\\]|\\\\.)*)"\s*:/m', $json, $keys);
    $files = [
        ...glob(app_path('Ledger/*.php')), ...glob(app_path('Actions/Ledger/*.php')), ...glob(app_path('Livewire/Ledger/*.php')),
        ...glob(resource_path('views/livewire/ledger/*.blade.php')),
        app_path('Enums/LedgerAccountType.php'), app_path('Reports/Builder/Subjects/JournalLines.php'),
        app_path('Livewire/Reports/TrialBalanceReport.php'), app_path('Livewire/Reports/BalanceSheetReport.php'), app_path('Livewire/Reports/ProfitAndLossReport.php'),
        app_path('Livewire/Reports/GeneralLedgerReport.php'), app_path('Livewire/Reports/JournalListReport.php'),
    ];
    $missing = [];
    foreach ($files as $file) {
        preg_match_all('/__\(\s*([\'"])((?:(?!\1)[^\\\\]|\\\\.)*)\1/', (string) file_get_contents($file), $m);
        foreach ($m[2] as $key) {
            $key = stripslashes($key);
            if (! array_key_exists($key, $ar)) {
                $missing[] = basename($file).': '.$key;
            }
        }
    }

    expect($missing)->toBe([])
        ->and(count($keys[1]))->toBe(count(array_unique($keys[1])));
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Ledger/JournalLinesSubjectTest.php tests/Feature/Ledger/LedgerAccessTest.php`
Expected: FAIL. The subject `journal_lines` is unknown ("You cannot report on this subject."), and the Arabic test lists the subject file's missing keys.

- [ ] **Step 3: Create `app/Reports/Builder/Subjects/JournalLines.php`:**

```php
<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\LedgerAccountType;
use App\Enums\PermissionName;
use App\Ledger\Sources;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** General ledger spec §6: journal lines for saved reports and packages. The ledger is company-wide, so ledger.view sees every building (plan ruling 12). */
final class JournalLines extends Subject
{
    public function key(): string
    {
        return 'journal_lines';
    }

    public function label(): string
    {
        return __('Journal lines');
    }

    public function group(): string
    {
        return __('Ledger');
    }

    public function permission(): PermissionName
    {
        return PermissionName::LedgerView;
    }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->leftJoin('customers', 'customers.id', '=', 'journal_lines.customer_id')
            ->leftJoin('owners', 'owners.id', '=', 'journal_lines.owner_id')
            ->leftJoin('buildings', 'buildings.id', '=', 'journal_lines.building_id')
            ->leftJoin('units', 'units.id', '=', 'journal_lines.unit_id')
            ->when($buildingId, fn (Builder $q, int $b) => $q->where('journal_lines.building_id', $b));
    }

    protected function define(CarbonImmutable $asAt): array
    {
        $sources = ['manual' => Sources::label('manual'), 'opening' => Sources::label('opening')];
        foreach (array_keys(Sources::TYPES) as $type) {
            $sources[$type] = Sources::label($type);
        }

        return [
            Field::date('date', __('Date'), __('Journal'), 'journals.date'),
            Field::number('journal', __('Journal'), __('Journal'), 'journals.id'),
            Field::enum('kind', __('Kind'), __('Journal'), 'journals.kind', ['auto' => __('Automatic'), 'manual' => Sources::label('manual'), 'opening' => Sources::label('opening')]),
            Field::enum('source', __('Source'), __('Journal'), 'COALESCE(journals.source_type, journals.kind)', $sources),
            Field::text('description', __('Description'), __('Journal'), 'journals.description'),
            Field::boolean('late', __('Late posting'), __('Journal'), 'journals.late_posting'),
            Field::text('account_code', __('Account code'), __('Account'), 'ledger_accounts.code'),
            Field::text('account', __('Account'), __('Account'), 'ledger_accounts.name_en'),
            Field::enum('account_type', __('Account type'), __('Account'), 'ledger_accounts.type', Field::optionsFrom(LedgerAccountType::class)),
            Field::money('debit', __('Debit'), __('Amounts'), 'journal_lines.debit'),
            Field::money('credit', __('Credit'), __('Amounts'), 'journal_lines.credit'),
            Field::money('net', __('Debit − credit'), __('Amounts'), '(journal_lines.debit - journal_lines.credit)'),
            Field::text('tenant', __('Tenant'), __('Dimensions'), 'customers.name_en'),
            Field::text('owner', __('Owner'), __('Dimensions'), 'owners.name_en'),
            Field::text('building', __('Building'), __('Dimensions'), 'buildings.code'),
            Field::text('unit', __('Unit'), __('Dimensions'), 'units.code'),
            Field::text('memo', __('Memo'), __('Journal'), 'journal_lines.memo'),
        ];
    }

    public function defaultColumns(): array
    {
        return ['date', 'journal', 'account_code', 'account', 'debit', 'credit', 'description'];
    }

    public function periodFields(): array
    {
        return ['date'];
    }

    public function defaultSort(): array
    {
        return ['field' => 'date', 'dir' => 'asc'];
    }
}
```

- [ ] **Step 4: Register it.** In `app/Reports/Builder/Registry.php`, replace `/** The 12 subjects (spec §4.2), in picker order. */` with `/** The 13 subjects (spec §4.2, plus the general ledger's Journal lines), in picker order. */`, and replace `                Subjects\Expenses::class, Subjects\PaymentsOut::class, Subjects\DepositsHeld::class,` with:

```php
                Subjects\Expenses::class, Subjects\PaymentsOut::class, Subjects\DepositsHeld::class,
                Subjects\JournalLines::class,
```

- [ ] **Step 5: Keep "every field of every subject compiles" covering it.** In `tests/Feature/ReportBuilder/SubjectQueriesTest.php`, after `    $this->admin = matrixUser(RoleName::Management, $this->building);` add:

```php
    $this->admin->givePermissionTo('ledger.view'); // the Journal lines subject needs it; Management has no ledger access by default
```

- [ ] **Step 6: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Journal lines": "بنود القيود",
    "Kind": "طبيعة القيد",
    "Automatic": "تلقائي",
    "Account code": "رمز الحساب",
    "Account type": "نوع الحساب",
    "Debit − credit": "المدين − الدائن",
    "Dimensions": "الأبعاد",
    "Amounts": "المبالغ",
```

- [ ] **Step 7: Run the ledger and builder tests.**

Run: `php artisan test tests/Feature/Ledger tests/Feature/ReportBuilder tests/Feature/ReportPackages`
Expected: PASS.

- [ ] **Step 8: Run the full suite, serially.**

Run: `php artisan test`
Expected: PASS, the whole suite on `rms_test`, one run at a time. Watch especially `IntegrityCheckTest` (67 triggers), `RolesAndPermissionsTest` (30 permissions), `HeadlessTest` (16 ready-made reports, unchanged), `Concurrency/*` (ledger reference tables kept by `exceptTables`), and the billing, payment, deposit and disbursement suites (posting runs after commit and needs no start date to stay silent).

- [ ] **Step 9: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Reports/Builder/Subjects/JournalLines.php app/Reports/Builder/Registry.php tests/Feature/ReportBuilder/SubjectQueriesTest.php tests/Feature/Ledger/JournalLinesSubjectTest.php tests/Feature/Ledger/LedgerAccessTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
General ledger: Journal lines report-builder subject for saved reports and packages; access sweep (Finance and Admin only) and Arabic check

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-review notes (done while writing)

- **Spec coverage:**
  - §1 purpose: trial balance, balance sheet, profit and loss and general ledger (Task 9); manual journals (Task 7); locking (Tasks 5 and 8); staff record money exactly as before, since posting hangs off model events (Task 2). Company books with Owner payable as a liability (ruling 3), go-live with opening balances (Task 6), one account per method (ruling 10). Out of scope stays out: no bank reconciliation, export, budgets, several banks per method, or trust accounting.
  - §2 approach: derived, rebuildable journals tagged with their source (`Sources`, `Poster`, the unique `(source_type, source_id)`); posted on create and reverse (model events, ruling 1); unlocked months rebuilt (Task 5); manual journals separate and never rebuilt (`kind`, the trigger, the `kind = auto` delete); locked months frozen (triggers).
  - §3 accounts and setup:
    - chart with code, EN/AR names, type and active; the seeded defaults (Task 1, ruling 10);
    - Admin adds, renames and deactivates; an account with lines cannot be deleted (FK, `DeleteLedgerAccount`); a deactivated account takes no postings (`Poster::write`, `Rules::load`, manual validation) (Task 6);
    - posting rules: events, methods and categories on one screen, with a rebuild offer (Task 6);
    - go-live: start date and opening balances balancing through Opening balance equity; nothing before the start; the start moves only while nothing is locked (Task 6, ruling 6);
    - permissions (Task 1, ruling 12; swept in Task 10).
  - §4 what is posted: every row of the spec's table is a row of ruling 3, tested in Tasks 2–4. The bullets:
    - balanced before saving (`Poster::write`);
    - a manual journal needs ≥2 lines and a description, is never edited (trigger) and is corrected by reversal (Task 7);
    - Ledger health nightly on a card for Finance and Admin (Task 8).
  - §5 rebuild and locking:
    - rebuild by Finance or Admin of unlocked months from the start date, in the background with progress, audited (Task 5);
    - "Locked up to" set by Finance, with nothing posted, rebuilt or changed on or before it (triggers, Task 5);
    - late postings on the first open day, marked (Task 5);
    - unlock Admin-only and audited (Task 5).
  - §6 reports: all five with Excel, dd/mm/yyyy and 3 dp (Task 9); the general ledger has its opening balance, click-through and running balance; the journal list filters by date, account and source; the report builder has the "Journal lines" subject (Task 10).
  - §7 tables: as specified (Task 1). Additions, each stated in a ruling:
    - `ledger_settings`: `health`, `health_checked_at` and the `rebuild_*` columns;
    - `ledger_rules` as key ⇒ account.
    - `EXPECTED_TRIGGERS` gains only the six ledger triggers (67).
  - §8 safety:
    - Finance and Admin only, and every action re-checks;
    - manual input validated (balanced, active accounts, open date);
    - tampered input gives messages or 404s (Tasks 6–8 tests);
    - idempotent posting and rebuild; a failure rolls back only that journal and shows in Ledger health (ruling 1, Task 2 test);
    - money through `Fils`.
  - §9 testing, each bullet to a file:
    - each event, managed and leased, reversals and credit notes exactly opposite: `InvoicePostingTest`, `ReceiptPostingTest`, `MoneyOutPostingTest`;
    - rebuild identical to live, manual journals survive: `LockAndRebuildTest`;
    - locked months never change, late postings on the first open day, unlock Admin-only: `LockAndRebuildTest`, `LedgerStorageTest`;
    - opening balances via Opening balance equity, nothing before the start: `LedgerSetupTest`, `InvoicePostingTest`;
    - Ledger health on a worked example: `LedgerHealthTest`;
    - reports against the worked example, Excel, the builder subject: `LedgerReportsTest`, `JournalLinesSubjectTest`;
    - permissions: `RolesAndPermissionsTest`, `LedgerAccessTest`.
- **Spec ambiguities ruled on:**
  - Posting runs after the money record commits, from model events, not in its transaction. That is §8's "a failure rolls back that source's journal only", and §2 rejects inline posting (ruling 1).
  - A credit note is posted as its own `invoice` source, line by line as the credited line (ruling 3).
  - "Vendor paid" has no source in the app; vendor payments are manual journals, and the vendor health row checks posting completeness (ruling 11).
  - Deposits and managed charges accrue on the invoice (spec §4), while the app's deposits-held and owner balances are cash-based. The health rows add back what is unpaid (ruling 8).
  - A unit with no owner contract on the date falls back to company income, counted in health (ruling 2).
  - "Other" payments out go to Other payments out (5190 Other expenses); owner opening balances after the start date go to Opening balance equity (ruling 3).
  - Expense VAT is posted gross (ruling 14).
  - A payable cancelled before its due date never posts; its cancellation date is `updated_at` (ruling 3).
  - Allocations made with the receipt carry the receipt's date (ruling 3, row 10).
  - Three permissions, not the existing ones (ruling 12).
  - Ledger health lives on the Ledger page, not the dashboard (Task 8).
  - A rebuild picks whole months, clamped to open days (ruling 4).
  - Progress and health are stored on the settings row (ruling 4).
  - The ledger reports are not added to `ReadyMade`; packages reach the ledger through the "Journal lines" subject (ruling 9).
- **Placeholder scan:** none. Every code step has its full code, every edit names its exact anchor text, and every run step has its command and expected result.
- **Type consistency:**
  - `Line` = `array{account: int, amount: int, customer_id: ?int, owner_id: ?int, building_id: ?int, unit_id: ?int, memo: ?string}` is the same in `Sources`, `Poster::write`, `SetLedgerStart::moveOpening`, `SaveOpeningBalances`, `PostManualJournal`, `ReverseManualJournal` and the Task 5 and 6 tests.
  - `Poster::write(string, string, string, list<Line>, ?string, ?int, bool, ?int, ?int)` is called with named `createdBy:` and `reverses:` in Tasks 5–7.
  - `Sources::pending(string, string, string)`, `Sources::build(string, int, Rules)`, `Sources::label(string)` and `Sources::url(string, ?string, ?int, ?int, int)` are the same in Tasks 2–10.
  - `Rules::load()`, `->account(string)`, `Rules::keys()` and `Rules::label(string)` are the same in Tasks 2, 6, 8 and 10.
  - `LedgerSetting::current()`, `firstOpenDay()` and `rebuilding()` are the same in Tasks 1, 2 and 5–8.
  - The action signatures (`LockLedger`, `UnlockLedger`, `RebuildLedger::handle(User, mixed, mixed)` and `::queue(LedgerSetting, string, string, User)`, `SetLedgerStart`, `SaveOpeningBalances`, `PostManualJournal::handle(User, mixed)` and `::openDate(LedgerSetting, string, string)`, `ReverseManualJournal::handle(User, Journal, mixed)`) are the same between their tasks and their callers (the Home, Accounts, Setup, NewJournal and JournalShow pages).
  - Error keys match the Livewire properties: `lockDate`, `unlockDate`, `fromMonth`/`toMonth`/`rebuild`, `startDate`, `balances.<id>.debit`, `date`/`description`/`lines`, `reverseDate`, `code`/`name_en`/`name_ar`/`type`, `rules`, `accounts`.
  - Pest helpers: `ledgerStart(string)`, `journalFor(string, int)`, `linesOf(?Journal)`, `postedLines(string, int)`, `ledgerBalances(?string)` (Task 2) and `ledgerFixture()` (Task 4) are used with those signatures in Tasks 5–10. Test-file helpers have unique global names: `storageJournal`, `ledgerSnapshot`, `manualLine`.
  - Routes: `ledger.{index, accounts, setup, journals.create, journals.show, trial-balance, balance-sheet, profit-and-loss, general-ledger, journals}`.
  - Source types: `invoice`, `payment`, `payment_reversal`, `allocation`, `expense`, `expense_reversal`, `disbursement`, `disbursement_reversal`, `owner_charge`, `owner_payable`, `owner_payable_cancel`. They are the same in `TYPES`, `REVERSES`, `label()`, `url()`, `WATCH`, the health vendor row and the tests.
- **Worked example arithmetic** (`ledgerFixture()`; Dr +, Cr −; BHD):
  - 01/10 Sara's rent: 1100 +440.000, 2100 −400.000 (managed, net), 2400 −40.000 (10% of 400).
  - 01/10 Sara's deposit: 1100 +400.000, 2000 −400.000 (company-held).
  - 01/10 Omar's rent: 1100 +300.000, 4000 −300.000 (leased, exempt).
  - 01/10 (posted 10/10) the October payable: 5000 +300.000, 2100 −300.000.
  - 05/10 Sara's 900.000 cash: 1000 +900.000, 2300 −900.000. Her allocations (rent 440, deposit 400, oldest first, same day): 2300 +840.000, 1100 −840.000, leaving 60.000 credit.
  - 06/10 Omar's 300.000 card: 1020 +300.000, 2300 −300.000. His allocation: 2300 +300.000, 1100 −300.000.
  - 08/10 expenses: 5100 +50.000, 2200 −50.000; 2100 +30.000, 2200 −30.000.
  - 10/10 head lease: 2100 +300.000, 1010 −300.000.
  - 15/10 fee: 2100 +22.000, 4200 −20.000, 2400 −2.000.
  - 18/10 refund: 2300 +60.000, 1000 −60.000.
  - **Balances:**
    - 1000 = 900 − 60 = 840.000 Dr;
    - 1010 = 300.000 Cr;
    - 1020 = 300.000 Dr;
    - 1100 = 440 + 400 + 300 − 840 − 300 = 0;
    - 2000 = 400.000 Cr;
    - 2100 = −400 − 300 + 30 + 300 + 22 = −348.000, i.e. 348.000 Cr;
    - 2200 = 80.000 Cr;
    - 2300 = −900 + 840 − 300 + 300 + 60 = 0;
    - 2400 = 42.000 Cr;
    - 4000 = 300.000 Cr;
    - 4200 = 20.000 Cr;
    - 5000 = 300.000 Dr;
    - 5100 = 50.000 Dr.
  - **Trial balance:** debits 840 + 300 + 300 + 50 = 1490.000; credits 300 + 400 + 348 + 80 + 42 + 300 + 20 = 1490.000.
  - **Profit and loss:** income 300 + 20 = 320.000; expenses 300 + 50 = 350.000; profit −30.000.
  - **Balance sheet:** assets 840 − 300 + 300 = 840.000; liabilities 400 + 348 + 80 + 42 = 870.000; equity −30.000; 870 − 30 = 840.000.
  - **Trial balance at 04/10:** only the 01/10 journals are dated on or before it, so 1100 1140.000 Dr and 5000 300.000 Dr against 2000 400.000, 2100 700.000, 2400 40.000 and 4000 300.000 Cr; 1440.000 each side. The payable is dated its due date, not its posting day.
  - **General ledger, 2100:** 0 → −400 → −700 → −670 → −370 → −348.
  - **Health:**
    - receivables: ledger 0 = Σ balance 0.
    - credit: payments 900 + 300, less allocations 840 + 300, less the refund 60, = 0.
    - deposits: the received movement 400, plus 0 unpaid, = 400.000.
    - owner payable: `OwnerLedger` (collection 440 − 40, less the expense 30, less the fee 22) = 348; plus billed net 400, less collected net 400; plus payable due 300, less paid 300; = 348.000.
    - vendor: 50 + 30 = 80.000.
  - **With the unpaid parking charge** (50.000 + 5.000 VAT, Task 8):
    - receivables: 55 = 55;
    - owner payable: ledger 348 + 50 = 398; app 348 + (450 − 400) = 398.
  - **Journal lines subject** on 2100: five lines, debits 30 + 300 + 22 = 352.000, credits 400 + 300 = 700.000; on Tower's expense lines, debits 50 + 30 = 80.000.
