# Ledger Money Screens Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the three most common non-tenant money movements their own screens so they post to the general ledger automatically: paying vendors (a "Vendor bill" payment out that pays recorded expenses), cash and bank movements (transfers, bank charges, bank interest), and VAT returns (payment to or refund from NBR). After this, manual journals are for corrections and year-end only.

**Architecture:** Vendor bills are ordinary payments out (`disbursements`) with payee type `vendor` and purpose `vendor_bill`, linked to the expenses they pay through a new write-once table `disbursement_expenses`. They go through the existing approval, cheque, mark-paid and reversal flow, and post through the existing `disbursement` and `disbursement_reversal` source types. Cash movements and VAT returns are two new write-once tables. A reversal is its own row pointing at the original (`reverses_id`), and each table posts through two new source types in `App\Ledger\Sources`, so the existing `Poster` posts them after commit, idempotently, with late posting. A migration adds the `is_money` account flag and the Bank charges and Bank interest income accounts and rules, additively. Two new Livewire pages live under `routes/ledger.php`.

**Tech Stack:** Laravel 13, PHP 8.5, MySQL, Livewire 4 + Flux (free), Pest, spatie/laravel-permission, `App\Audit\Audit`, `App\Support\Fils`, `App\Ledger\{Poster, Sources, Rules, Balances, LedgerHealth}`, `App\Actions\Ledger\PostManualJournal::openDate()/activeAccounts()`, `<x-date-input>`.

**Spec:** `docs/superpowers/specs/2026-10-28-ledger-money-screens-design.md`. Read it first; this plan argues from it. Where the plan and the spec differ, a ruling says why. The general ledger plan (`docs/superpowers/plans/2026-10-24-general-ledger.md`) explains `Poster`, `Sources` and the worked example this plan extends.

## Global Constraints

- Spec §1, out of scope: partial payment of an expense; bank reconciliation; VAT return forms or filing to NBR; approval for cash and bank movements or VAT returns.
- Spec §5: "Finance (`ledger.manage`) records and reverses; `ledger.view` (Finance, Admin) views." "Audited; nothing dated on or before the ledger lock; dates within start date … today." "BHD 3 dp through `Fils`; tampered input gives a message, never a 500; EN/AR."
- Actions are the only write path, and each one re-checks permission.
- New money tables get immutability triggers, the way existing ones do. `EXPECTED_TRIGGERS` goes from 67 to **73** (+2 `disbursement_expenses`, +2 `cash_movements`, +2 `vat_returns`). `cheques_guard` is replaced, not added.
- Money is exact through `Fils`, never floats. Timezone is Asia/Bahrain. Dates show as dd/mm/yyyy.
- All strings go through `__()` with entries in ar.json, inserted after `"Credit refund reversal": "عكس استرداد رصيد",`, with no duplicate keys. Every key listed below was checked as absent from `lang/ar.json` and from the other tasks. Say "tenant", never "customer", in UI copy.
- Tampered Livewire input gives a message or a 404, never a 500. Internals are private or static. Ids use `#[Locked]`.
- Exports go through SafeCells. This plan adds no export; the new journals reach Excel through the existing ledger reports.
- Ledger postings go through the existing Poster after commit and must be idempotent. Respect the lock: nothing is dated on or before `locked_until`, and late posting rules apply.
- Tests run serially on rms_test, never two at once.
- Gates: Pint `--dirty`; `phpstan clear-result-cache`, then phpstan `--memory-limit=2G` with 0 errors; `npm run build`.
- The PHP binary is /c/Users/ababy/.config/herd/bin/php85/php.exe (written `php` below).
- Never run migrate:fresh on the dev DB `rms`. Never change MySQL global settings. The dev DB is migrated with `php artisan migrate --database=migrator --force`.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never commit `.superpowers/`.
- No visual polish. Pages are plain Flux, consistent with the existing ledger pages; a design pass comes later.
- Blade: do not mix inline `@php(...)` with `@php … @endphp` blocks in one file.

## Rulings made while planning

1. **Vendor payee.**
   - `PayeeType::Vendor` (`vendor`) joins `owner` and `customer`; for it `disbursements.payee_id` is a `vendors.id`. A CHECK makes `payee_type = 'vendor'` exactly when `purpose = 'vendor_bill'`, so the purpose `other` stays owner-or-tenant (`RecordDisbursement` validates the same).
   - `Disbursement::payee()` now returns `Customer|Owner|Vendor`, and `payeeName()` gives the name (`name_en`, or a vendor's `name`). Every `payee()->name_en` caller switches to it: the two approval summaries and the Payments out index and show views. The Payments out list and the "Payments out" report-builder subject now show `owner` and `vendor` rows to everyone with `finance.view`, and tenant rows only for visible tenants. Before, the tenant filter did not check `payee_type`.
   - **Approval:** a vendor bill always starts `pending_approval` and goes to Management through the existing `ApprovalAction::PaymentOut` handler, like a payment out with no approved source. An expense is a cost, not a payout limit, so there is no "within limit" shortcut. After approval, Finance records it as paid on the payment's page (`PayDisbursement`), where the cheque details are taken. A rejected bill frees its expenses. A paid bill is reversed through the existing `RequestDisbursementReversal` → `PaymentOutReversal`, which needs no change: a vendor has no source to reopen.
   - **Cheques:** `cheques` gains `vendor_id` (nullable FK to vendors). `cheques_party_chk` now reads: an issued cheque has exactly one of customer, owner or vendor. `cheques_guard` is re-created with `vendor_id` in its "party never changes" clause. `MarkDisbursementPaid` fills `vendor_id` for a vendor payee. `Cheque::visibleTo` lets vendor cheques through, as it does owner cheques. Issued cheques have no print layout in the app, so nothing else changes.
2. **One active payment per expense: a lock, not a unique key.**
   - `disbursement_expenses (id, disbursement_id, expense_id, created_at)` is write-once (two triggers), with a unique key on `(disbursement_id, expense_id)` and an index on `(expense_id, disbursement_id)`. A unique key on `expense_id` alone would block paying an expense again after a rejection or reversal. Keeping an "active" flag on the link rows would mean updating write-once rows. So whether a link is active is derived from `disbursements.status IN ('pending_approval', 'approved', 'paid')` (`Disbursement::ACTIVE`). Rejected and reversed are final statuses, so a link never comes back to life.
   - `RecordDisbursement::vendorBill()` locks the chosen `expenses` rows `FOR UPDATE` in id order, then reads their active links with a locking read, and refuses any expense already on one. Two bills for the same expense therefore run one after the other, and the second sees the first. `ReverseExpense` takes the same expense-row lock and refuses an expense on an active payment ("Reject or reverse that payment out first"). Otherwise a paid bill would pay a reversed expense, and Vendor payable would go negative.
   - The payment total is the sum of the full expense totals (spec §2: no partial payment). It is computed by the action, never typed. A reversed expense, an expense the user cannot see (`Expense::visibleTo`), or an unknown id is refused.
   - `PayDisbursement` refuses a vendor bill's `paid_on` before its latest expense date. A bill can therefore never be paid before the start date when one of its expenses is dated after it, which ruling 5 relies on.
3. **Paid status is derived and shown.**
   - `Expense::disbursements()` (belongs-to-many through `disbursement_expenses`), `Expense::activePayment(): ?Disbursement` (the one active payment, if any) and the scope `Expense::unpaid()` (recorded and on no active payment: what a new bill may pay).
   - An expense shows **Paid · PO-…** (linked) when its payment is paid, **In payment · …** (linked) while the payment waits or is approved, **Unpaid** otherwise, and "—" when reversed. A reversal or a rejection makes it unpaid again, with no write to the expense: expenses are immutable through `expenses_guard`, which is untouched. This shows on the Expenses list (a new "Payment" column) and on the expense page.
4. **Vendor-bill postings use the existing disbursement source.** `Sources::disbursement()` gains one arm: purpose `vendor_bill` debits `vendor_payable` (amount) and credits `method.<method>` (amount). Source type `disbursement`, dated `disbursements.paid_on`, eligible while `paid` or `reversed`. Its reversal is source type `disbursement_reversal`, dated `DATE(reversed_at)`, the exact opposite through `REVERSES`. Both are unchanged in `TYPES`. A waiting, approved or rejected bill posts nothing. Lines carry no dimensions: a bill can span buildings, and the expense journals already carry them.
5. **The Vendor payable health row now measures unpaid bills.**
   - **Ledger:** Vendor payable Cr − Dr on journals with source type `expense`, `expense_reversal`, `disbursement` or `disbursement_reversal`. Opening and manual journals are left out. They have no expense behind them, so the app cannot check them.
   - **App:** expenses dated from the start date to today that are recorded and not on a **paid** vendor bill (waiting and approved bills are still unpaid), minus the bills from before the start date that sit in the opening balances. For those, only what happened from the start date counts: − totals of those reversed from the start date, − totals of those on vendor bills paid from the start date (paid or reversed since), + totals of those on vendor bills reversed from the start date. This mirrors the postings exactly. Every expense's journal and every bill's journal falls in the window together, because a bill is never paid before its latest expense (ruling 2) and an expense on an active bill cannot be reversed.
   - **Removed:** general ledger plan ruling 11's "Finance records vendor payments as manual journals" is superseded (Task 4 amends that plan). The existing dev data has one manual vendor journal (Dr Vendor payable, Cr Bank). It stays as it is. The health row leaves manual journals out, so the row stays equal. Its expense shows Unpaid until Finance reverses that journal and pays the expense through Payments out.
   - **Worked numbers** (`ledgerFixture()`, start 01/10/2026, at 20/10/2026; "Lobby lights" 50.000 and "T1 tap" 30.000, both 08/10):
     - nothing paid: ledger 80.000 = app 80.000;
     - both paid on one bill 20/10: ledger 80 − 80 = 0 = app 0;
     - that bill reversed: ledger 0 + 80 = 80.000 = app 80.000;
     - only "Lobby lights" paid: ledger 30.000 = app 30.000. A second, waiting bill for "T1 tap" leaves it 30.000; rejecting that bill also leaves 30.000. A manual journal Dr 2200 30.000 / Cr 1010 30.000 leaves the row 30.000 = 30.000, while the account itself shows 0;
     - "September power" 40.000 dated 20/09 (before the start; never posted) paid 20/10: ledger 80 − 40 = 40.000; app 80 (both in-window bills unpaid) − 40 (a pre-start bill paid from the start) = 40.000. Reversed: ledger 40 + 40 = 80.000; app 80 − 40 + 40 = 80.000.
6. **Money accounts.** `ledger_accounts.is_money` (boolean, default false) is a migration column, set true for system keys `cash`, `bank` and `card_clearing` (1000, 1010, 1020) where their type is still asset. The CHECK `ledger_accounts_money_chk` allows `is_money = 1` only on `type = 'asset'`. Admin sets or clears it on any asset account from Accounts and rules (`SaveLedgerAccount` takes `is_money`; a missing value keeps the current one; a non-asset type is refused with a message). Cash movements accept only active money accounts. Methods still map to accounts through the posting rules, as before.
7. **The two new accounts and rules.**

   | Code | Account | Arabic | Type | system_key | Rule key |
   |---|---|---|---|---|---|
   | 5180 | Bank charges | الرسوم المصرفية | expense | bank_charges | bank_charges |
   | 4300 | Bank interest income | إيرادات الفوائد البنكية | income | bank_interest | bank_interest |

   - `Rules::EVENTS` gains `bank_charges => 'Bank charges'` and `bank_interest => 'Bank interest income'`, so they show on the rules screen and `SaveLedgerRules` requires them. In `Rules::type()`, `bank_interest` is Income and `bank_charges` falls to the default, Expense.
   - The migration inserts them **additively**, so the dev DB, production and RefreshDatabase all get them. An account is inserted only when no account has its `system_key`. If the code is taken on an installed chart, the next free number is used (5181, …). A rule is inserted only when its key is missing. That gives 26 accounts and 27 rules on a fresh database. `TestCase::$exceptTables` already keeps `ledger_accounts` and `ledger_rules`.
8. **Cash and bank movements.**
   - Table `cash_movements`: `kind` (`transfer | bank_charge | bank_interest`), `from_account_id`, `to_account_id` (nullable FKs to ledger_accounts), `amount` decimal(12,3) > 0, `date`, `reference` (required, 100), `note` (nullable, 1000), `reverses_id` (nullable, **unique**, FK to cash_movements), `created_by`, timestamps.
   - CHECK `cash_movements_accounts_chk`: a transfer has two different accounts; a bank charge has only `from`; bank interest has only `to`. Triggers `cash_movements_no_update` and `cash_movements_no_delete`.
   - **Postings** (`Sources::cashMovement`; source type `cash_movement`, own date `t.date`, eligible `t.reverses_id IS NULL`; description = reference):
     - transfer: Dr `to`, Cr `from`;
     - bank charge: Dr rule `bank_charges`, Cr `from`;
     - bank interest: Dr `to`, Cr rule `bank_interest`.
   - **Reversal:** a new row copying the original's kind, accounts, amount and reference, with its own `date` (on an open day, on or after the original's) and the reason as `note`, `reverses_id` = the original. It posts as source type `cash_movement_reversal` (own date `t.date`, eligible `t.reverses_id IS NOT NULL`): the exact opposite of the original's lines (`Sources::REVERSAL_ROWS`). Once only: the unique `reverses_id`, checked under a row lock first. A reversal is never itself reversed. `App\Actions\Ledger\ReverseMoneyEntry` reverses both cash movements and VAT returns.
   - `RecordCashMovement` refuses the same account twice ("Choose two different money accounts."), and any account that is not an active money account.
9. **VAT returns.**
   - Table `vat_returns`: `period_from`, `period_to` (CHECK `period_to >= period_from`), `direction` (`payment | refund`), `method` (`bank_transfer | cheque | cash`, the payout methods), `amount` > 0, `date`, `reference` (required), `note`, `reverses_id` (nullable, unique, self-FK), `created_by`, timestamps. Triggers `vat_returns_no_update` and `vat_returns_no_delete`.
   - **Period figure** (`Balances::vatDue(from, to): int`, fils): − Σ(debit − credit) on the account the `vat_payable` rule points at, over journals dated in the period. It leaves out journals of source type `vat_return` and `vat_return_reversal` (spec) and the **opening** journal: that is earlier periods' VAT carried in, not activity in the period. Manual journals count. A late posting counts in the period it lands in, like every ledger report.
   - **Payment versus refund:** the action takes the figure under the settings lock. A figure ≥ 0 records a `payment` (Dr VAT payable, Cr `method.<method>`); a figure < 0 records a `refund` (Dr `method.<method>`, Cr VAT payable). The amount is prefilled with |figure| on the page and may be edited (> 0). The list shows the difference.
   - **Overlap:** refused when a return that is not a reversal and has no reversal has `period_from <= new.to AND period_to >= new.from`. The check runs after taking the `ledger_settings` row lock, which every ledger writer takes, so two returns cannot race.
   - The period must end by today; it may start before the ledger start (its figure is then 0 and Finance types the amount). The payment `date` is on an open day.
   - **Reversal:** the same as ruling 8, source type `vat_return_reversal`. The reversed return then frees its period.
   - **The list** shows each return (not reversal rows): period, ledger figure (live), paid or refunded and amount, difference = figure − (amount for a payment, −amount for a refund), and "—" for the difference once reversed.
10. **Dates and the lock.**
    - Both new actions lock `ledger_settings` first, then call `PostManualJournal::openDate()`: on or after the start date, after `locked_until`, and (validator) not after today.
    - After saving the row, still inside the transaction, they call the new `Sources::assertPostable($type, $id, $field)`. It builds the journal the record will post and refuses, with a message, a rule that has no active account or an inactive account, so the record never stands without its journal.
    - `Poster` posts after commit. If a lock lands between commit and posting, `Poster::post` posts late on the first open day (unchanged).
    - Vendor bills keep the payment-out date rules (`paid_on` ≤ today, not before the latest expense). A bill paid in a locked month posts late, as every payment out does.
11. **Pages and routes.**
    - `routes/ledger.php` gains `ledger.cash-movements` (`/ledger/cash-movements`, `App\Livewire\Ledger\CashMovements`) and `ledger.vat-returns` (`/ledger/vat-returns`, `App\Livewire\Ledger\VatReturns`). Both have `can:ledger.view` middleware and re-check in `mount()`; the record and reverse forms show only with `ledger.manage`, and the actions re-check it. The sidebar's `@can('ledger.view')` block gets "Cash and bank movements" and "VAT returns" after "Journal list".
    - `Sources::label()` names the four new types. `Sources::url()` opens the movement or return list for them; vendor bills already open the payment out.
    - **Payments out create form:** "Pay to" gains **Vendor**. Choosing a vendor lists, as ticks, its unpaid work-order expenses (`work_orders.vendor_id`, `expense_id`) first, then those added by hand from "Add another unpaid expense" (the newest 200 other unpaid expenses the user can see). The amount and reason fields are replaced by the running total of the ticked expenses. The reason sent for approval is built from the vendor and the expenses. The button stays "Send for approval".
    - The payment page lists a vendor bill's expenses with links. Its payee reads "(vendor)".
12. **Stored text.** A cash movement's and a VAT return's journal description is its reference (a return adds its period, "NBR-Q4 01/10/2026–20/10/2026"). A vendor bill's is its PO number, as for every payment out. Audit events: `ledger.account_saved` (now with `is_money`), `ledger.cash_movement_recorded`, `ledger.vat_return_recorded`, `ledger.money_entry_reversed`. Payments out keep their activity log, and approvals their audit.

**Worked example** (`ledgerFixture()` + `vendorBillFixture()`, VAT 10%, start 01/10/2026, as at 20/10/2026), the figures Task 7 asserts. All of these are dated 20/10:

- The vendor bill for "Lobby lights" 50.000 and "T1 tap" 30.000, by bank transfer: 2200 Dr 80.000, 1010 Cr 80.000.
- Transfer "DEP-1", Cash on hand → Bank, 500.000: 1010 Dr 500.000, 1000 Cr 500.000.
- Bank charge "CHG-OCT", from Bank, 2.500: 5180 Dr 2.500, 1010 Cr 2.500.
- Bank interest "INT-OCT", to Bank, 1.200: 1010 Dr 1.200, 4300 Cr 1.200.
- VAT return 01/10–20/10: the figure is 40.000 (Sara's rent) + 2.000 (the fee) = 42.000, a payment. Paid 42.000 by bank transfer, "NBR-Q4": 2400 Dr 42.000, 1010 Cr 42.000.

**Trial balance:**

- Cash on hand 340.000 Dr;
- Bank 76.700 Dr;
- Card clearing 300.000 Dr;
- Tenant deposits held 400.000 Cr;
- Owner payable 348.000 Cr;
- Rent income 300.000 Cr;
- Management fee income 20.000 Cr;
- Bank interest income 1.200 Cr;
- Lease rent to owners 300.000 Dr;
- Maintenance and repairs 50.000 Dr;
- Bank charges 2.500 Dr;
- totals 1069.200 = 1069.200.

Vendor payable and VAT payable are 0.

**Profit and loss** 01/10–20/10: income 321.200, expenses 352.500, profit −31.300. **Balance sheet:** assets 716.700; liabilities 748.000; current-year profit −31.300; liabilities and equity 716.700. **Health:** every row equal; Vendor payable 0 = 0.

## File Structure

```
database/migrations/2026_12_28_000100_create_ledger_money_tables.php  NEW  checks, cheques.vendor_id, disbursement_expenses, is_money, 2 accounts + rules, cash_movements, vat_returns, 6 triggers (Task 1)
app/Models/LedgerAccount.php, app/Ledger/Rules.php                    MOD  is_money; bank_charges, bank_interest (Task 1)
app/Actions/Ledger/SaveLedgerAccount.php, app/Livewire/Ledger/Accounts.php + view  MOD  money flag (Task 1)
app/Integrity/IntegrityCheck.php                                      MOD  EXPECTED_TRIGGERS 73 (Task 1)
app/Enums/PayeeType.php, app/Enums/DisbursementPurpose.php            MOD  Vendor, VendorBill (Task 2)
app/Models/Disbursement.php, Expense.php, Cheque.php                  MOD  payeeName, expenses, ACTIVE; disbursements, activePayment, unpaid; vendor cheques (Task 2)
app/Actions/Disbursements/RecordDisbursement.php                      MOD  vendor_bill (Task 2)
app/Actions/Disbursements/PayDisbursement.php, MarkDisbursementPaid.php  MOD  paid_on ≥ latest expense; cheque vendor_id (Task 2)
app/Actions/Expenses/ReverseExpense.php                               MOD  refused while on an active payment (Task 2)
app/Approvals/PaymentOut.php, PaymentOutReversal.php                  MOD  payeeName (Task 2)
app/Livewire/Disbursements/Index.php + index/show views               MOD  payee name, vendor rows (Task 2)
app/Reports/Builder/Subjects/PaymentsOut.php                          MOD  vendor payee (Task 2)
app/Ledger/Sources.php                                                MOD  vendor_bill arm (Task 2); cash_movement(+_reversal), assertPostable (Task 5); vat_return(+_reversal) (Task 6)
tests/Pest.php                                                        MOD  vendorBillFixture() (Task 2)
app/Livewire/Disbursements/Create.php, Show.php + views               MOD  vendor form, expense list (Task 3)
app/Livewire/Expenses/Index.php, Show.php + views                     MOD  Payment column / row (Task 3)
app/Ledger/LedgerHealth.php                                           MOD  vendor row (Task 4)
docs/superpowers/plans/2026-10-24-general-ledger.md                  MOD  ruling 11 superseded (Task 4)
app/Enums/CashMovementKind.php, app/Models/CashMovement.php           NEW  (Task 5)
app/Actions/Ledger/RecordCashMovement.php, ReverseMoneyEntry.php      NEW  (Task 5; ReverseMoneyEntry widened in Task 6)
app/Livewire/Ledger/CashMovements.php + view                          NEW  (Task 5)
app/Models/VatReturn.php, app/Actions/Ledger/RecordVatReturn.php      NEW  (Task 6)
app/Livewire/Ledger/VatReturns.php + view                             NEW  (Task 6)
app/Ledger/Balances.php                                               MOD  vatDue (Task 6)
app/Ledger/Poster.php                                                 MOD  WATCH (Tasks 5, 6)
routes/ledger.php, resources/views/layouts/app/sidebar.blade.php      MOD  (Tasks 5, 6)
lang/ar.json                                                          MOD  (Tasks 1, 2, 3, 5, 6)
tests/Feature/Ledger/LedgerStorageTest.php                            MOD  counts 26 / 27 / 73 (Task 1)
tests/Feature/Ledger/MoneyScreensStorageTest.php                      NEW  (Task 1)
tests/Feature/Disbursements/VendorBillTest.php                        NEW  (Task 2)
tests/Feature/Disbursements/VendorBillScreensTest.php                 NEW  (Task 3)
tests/Feature/Ledger/VendorPayableHealthTest.php                      NEW  (Task 4)
tests/Feature/Ledger/CashMovementTest.php                             NEW  (Task 5)
tests/Feature/Ledger/VatReturnTest.php                                NEW  (Task 6)
tests/Feature/Ledger/MoneyScreensWorkedExampleTest.php                NEW  (Task 7)
tests/Feature/Ledger/LedgerAccessTest.php                             MOD  new pages, sweep (Task 7)
```

---

### Task 1: Storage: vendor payees, the expense link, money accounts, the two new accounts, cash movements, VAT returns and six triggers

**Files:**
- Create: `database/migrations/2026_12_28_000100_create_ledger_money_tables.php`
- Modify: `app/Models/LedgerAccount.php`, `app/Ledger/Rules.php`, `app/Actions/Ledger/SaveLedgerAccount.php`, `app/Livewire/Ledger/Accounts.php`, `resources/views/livewire/ledger/accounts.blade.php`, `app/Integrity/IntegrityCheck.php`, `tests/Feature/Ledger/LedgerStorageTest.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/MoneyScreensStorageTest.php`

**Interfaces:**
- Consumes: `IntegrityCheck::EXPECTED_TRIGGERS` (67), `rms_trigger_count()`, the ledger chart (rulings 6–7), `maintenanceVendor()`, `matrixUser()`.
- Produces:
  - Columns `disbursements.payee_type IN (owner, customer, vendor)`, `purpose` adds `vendor_bill`, CHECK `disbursements_vendor_chk`; `cheques.vendor_id`; `ledger_accounts.is_money`.
  - Tables `disbursement_expenses (id, disbursement_id, expense_id, created_at)`, `cash_movements`, `vat_returns` (rulings 2, 8, 9), each write-once.
  - Accounts `5180 Bank charges` (`bank_charges`) and `4300 Bank interest income` (`bank_interest`); rules `bank_charges`, `bank_interest`.
  - `LedgerAccount::$is_money` (bool, fillable). `SaveLedgerAccount::handle(User, ?LedgerAccount, array{code, name_en, name_ar, type, is_money?})`; error key `is_money`.
  - `Rules::EVENTS` has `bank_charges`, `bank_interest`; `Rules::type('bank_interest') === Income`.
  - `IntegrityCheck::EXPECTED_TRIGGERS = 73`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Ledger/MoneyScreensStorageTest.php`:

```php
<?php

use App\Actions\Ledger\SaveLedgerAccount;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Livewire\Ledger\Accounts;
use App\Models\Building;
use App\Models\Expense;
use App\Models\LedgerAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('the two new accounts, their rules and the money flag are there, and the trigger count is 73', function () {
    $charges = LedgerAccount::query()->where('system_key', 'bank_charges')->firstOrFail();
    $interest = LedgerAccount::query()->where('system_key', 'bank_interest')->firstOrFail();

    expect([$charges->code, $charges->type->value, $charges->name_en, $charges->name_ar])->toBe(['5180', 'expense', 'Bank charges', 'الرسوم المصرفية'])
        ->and([$interest->code, $interest->type->value, $interest->name_en])->toBe(['4300', 'income', 'Bank interest income'])
        ->and(DB::table('ledger_rules')->where('key', 'bank_charges')->value('ledger_account_id'))->toBe($charges->id)
        ->and(DB::table('ledger_rules')->where('key', 'bank_interest')->value('ledger_account_id'))->toBe($interest->id)
        ->and(LedgerAccount::query()->where('is_money', true)->orderBy('code')->pluck('code')->all())->toBe(['1000', '1010', '1020'])
        ->and(IntegrityCheck::EXPECTED_TRIGGERS)->toBe(73)
        ->and((int) DB::selectOne('SELECT rms_trigger_count() AS n')->n)->toBe(73);
});

test('cash movements, VAT returns and the expense links are write-once', function () {
    $user = User::factory()->create();
    $cash = LedgerAccount::query()->where('code', '1000')->value('id');
    $bank = LedgerAccount::query()->where('code', '1010')->value('id');
    $stamp = ['created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()];
    $movement = DB::table('cash_movements')->insertGetId(['kind' => 'transfer', 'from_account_id' => $cash, 'to_account_id' => $bank, 'amount' => '5.000',
        'date' => '2026-10-20', 'reference' => 'T-1', ...$stamp]);
    $return = DB::table('vat_returns')->insertGetId(['period_from' => '2026-07-01', 'period_to' => '2026-09-30', 'direction' => 'payment', 'method' => 'bank_transfer',
        'amount' => '5.000', 'date' => '2026-10-20', 'reference' => 'NBR-1', ...$stamp]);
    $out = DB::table('disbursements')->insertGetId(['payee_type' => 'vendor', 'payee_id' => maintenanceVendor()->id, 'purpose' => 'vendor_bill', 'amount' => '5.000',
        'method' => 'cash', 'status' => 'pending_approval', ...$stamp]);
    DB::table('disbursement_expenses')->insert(['disbursement_id' => $out, 'expense_id' => Expense::factory()->create()->id]);
    $other = Expense::factory()->create()->id;

    foreach (['cash_movements' => $movement, 'vat_returns' => $return] as $table => $id) {
        expect(fn () => DB::table($table)->where('id', $id)->update(['reference' => 'X']))->toThrow(QueryException::class, 'write-once')
            ->and(fn () => DB::table($table)->where('id', $id)->delete())->toThrow(QueryException::class, 'cannot be deleted');
    }
    expect(fn () => DB::table('disbursement_expenses')->where('disbursement_id', $out)->update(['expense_id' => $other]))->toThrow(QueryException::class, 'write-once')
        ->and(fn () => DB::table('disbursement_expenses')->where('disbursement_id', $out)->delete())->toThrow(QueryException::class, 'cannot be deleted');
});

test('the database refuses a vendor payee off a vendor bill, a transfer to its own account and a money flag off an asset', function () {
    $user = User::factory()->create();
    $bank = LedgerAccount::query()->where('code', '1010')->value('id');
    $row = ['payee_type' => 'vendor', 'payee_id' => maintenanceVendor()->id, 'purpose' => 'other', 'amount' => '5.000', 'method' => 'cash', 'status' => 'pending_approval',
        'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()];

    expect(fn () => DB::table('disbursements')->insert($row))->toThrow(QueryException::class, 'disbursements_vendor_chk')
        ->and(fn () => DB::table('disbursements')->insert([...$row, 'payee_type' => 'owner', 'purpose' => 'vendor_bill']))->toThrow(QueryException::class, 'disbursements_vendor_chk')
        ->and(fn () => DB::table('cash_movements')->insert(['kind' => 'transfer', 'from_account_id' => $bank, 'to_account_id' => $bank, 'amount' => '5.000', 'date' => '2026-10-20',
            'reference' => 'T', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class, 'cash_movements_accounts_chk')
        ->and(fn () => DB::table('ledger_accounts')->where('code', '4000')->update(['is_money' => true]))->toThrow(QueryException::class, 'ledger_accounts_money_chk');
});

test('Admin flags an asset account as money; another type is refused; an edit without the flag keeps it', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = matrixUser(RoleName::Admin, Building::factory()->create());

    Livewire::actingAs($admin)->test(Accounts::class)
        ->set('code', '1030')->set('name_en', 'Petty cash')->set('name_ar', 'النثرية')->set('type', 'asset')->set('is_money', true)
        ->call('save')->assertHasNoErrors()
        ->set('code', '5900')->set('name_en', 'Odd')->set('name_ar', 'غريب')->set('type', 'expense')->set('is_money', true)
        ->call('save')->assertHasErrors('is_money')
        ->set('is_money', ['x'])->call('save')->assertHasErrors('is_money');
    $petty = LedgerAccount::query()->where('code', '1030')->sole();
    $bank = LedgerAccount::query()->where('code', '1010')->sole();
    app(SaveLedgerAccount::class)->handle($admin, $bank, ['code' => '1010', 'name_en' => 'Main bank', 'name_ar' => $bank->name_ar, 'type' => 'asset']);

    expect($petty->is_money)->toBeTrue()
        ->and(LedgerAccount::query()->where('code', '5900')->exists())->toBeFalse()
        ->and($bank->fresh()->is_money)->toBeTrue()
        ->and($bank->fresh()->name_en)->toBe('Main bank');

    Livewire::actingAs($admin)->test(Accounts::class)->call('edit', $petty->id)->assertSet('is_money', true);
});
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php artisan test tests/Feature/Ledger/MoneyScreensStorageTest.php`
Expected: FAIL. No account has system key `bank_charges`, and table `cash_movements` does not exist.

- [ ] **Step 3: The migration** `database/migrations/2026_12_28_000100_create_ledger_money_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger money screens spec §2–§5 (plan rulings 1–9): vendor payees and vendor bills on payments out, issued cheques to a
 * vendor, the expenses a vendor bill pays, money accounts, Bank charges and Bank interest income with their rules, cash and
 * bank movements, and VAT returns. Six triggers keep the three new tables write-once (EXPECTED_TRIGGERS 67 → 73); the
 * cheques guard is replaced, not added. The accounts and rules are inserted additively, so an installed chart keeps Admin's edits.
 */
return new class extends Migration
{
    /** system_key => [preferred code, English, Arabic, type, rule key] (plan ruling 7) */
    private const array ACCOUNTS = [
        'bank_charges' => ['5180', 'Bank charges', 'الرسوم المصرفية', 'expense', 'bank_charges'],
        'bank_interest' => ['4300', 'Bank interest income', 'إيرادات الفوائد البنكية', 'income', 'bank_interest'],
    ];

    private const array TRIGGERS = ['cash_movements_no_delete', 'cash_movements_no_update', 'disbursement_expenses_no_delete', 'disbursement_expenses_no_update',
        'vat_returns_no_delete', 'vat_returns_no_update'];

    public function up(): void
    {
        // Plan ruling 1: a vendor is a payee only of a vendor bill. One statement: atomic.
        DB::statement(<<<'SQL'
            ALTER TABLE disbursements
                DROP CHECK disbursements_payee_chk,
                DROP CHECK disbursements_purpose_chk,
                ADD CONSTRAINT disbursements_payee_chk CHECK (payee_type IN ('owner', 'customer', 'vendor')),
                ADD CONSTRAINT disbursements_purpose_chk CHECK (purpose IN ('owner_remittance', 'head_lease', 'deposit_refund', 'credit_refund', 'other', 'vendor_bill')),
                ADD CONSTRAINT disbursements_vendor_chk CHECK ((payee_type = 'vendor') = (purpose = 'vendor_bill'))
            SQL);

        // Plan ruling 1: an issued cheque goes to exactly one of a tenant, an owner or a vendor.
        Schema::table('cheques', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('owner_id')->constrained()->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE cheques DROP CHECK cheques_party_chk, ADD CONSTRAINT cheques_party_chk CHECK (
                (direction = 'received' AND customer_id IS NOT NULL AND owner_id IS NULL AND vendor_id IS NULL)
                OR (direction = 'issued' AND (customer_id IS NOT NULL) + (owner_id IS NOT NULL) + (vendor_id IS NOT NULL) = 1))
            SQL);
        DB::unprepared('DROP TRIGGER IF EXISTS cheques_guard');
        DB::unprepared(<<<'DDL'
            CREATE TRIGGER cheques_guard BEFORE UPDATE ON cheques FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.direction = 'received' AND (
                        (OLD.status = 'held' AND NEW.status IN ('deposited', 'returned', 'cancelled'))
                        OR (OLD.status = 'deposited' AND NEW.status IN ('cleared', 'bounced'))
                        OR (OLD.status = 'cleared' AND NEW.status = 'bounced')
                        OR (OLD.status = 'bounced' AND NEW.status IN ('replaced', 'returned'))))
                    OR (OLD.direction = 'issued' AND OLD.status = 'issued' AND NEW.status IN ('cleared', 'cancelled'))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: status change not allowed';
                END IF;
                IF NOT (NEW.direction <=> OLD.direction AND NEW.customer_id <=> OLD.customer_id AND NEW.owner_id <=> OLD.owner_id
                    AND NEW.vendor_id <=> OLD.vendor_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: the party never changes';
                END IF;
                IF OLD.status NOT IN ('held', 'issued') AND NOT (NEW.cheque_no <=> OLD.cheque_no AND NEW.bank_name <=> OLD.bank_name
                    AND NEW.cheque_date <=> OLD.cheque_date AND NEW.amount <=> OLD.amount AND NEW.agreement_id <=> OLD.agreement_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: terms are frozen once deposited';
                END IF;
                IF OLD.direction = 'issued' AND NOT (NEW.amount <=> OLD.amount AND NEW.cheque_no <=> OLD.cheque_no) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: an issued cheque matches its payment out';
                END IF;
                IF OLD.status NOT IN ('held', 'deposited') AND NOT (NEW.invoice_id <=> OLD.invoice_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: the target is fixed once the cheque has left held and deposited';
                END IF;
                IF (OLD.payment_id IS NOT NULL AND NOT (NEW.payment_id <=> OLD.payment_id))
                    OR (OLD.disbursement_id IS NOT NULL AND NOT (NEW.disbursement_id <=> OLD.disbursement_id))
                    OR (OLD.replaced_by_cheque_id IS NOT NULL AND NOT (NEW.replaced_by_cheque_id <=> OLD.replaced_by_cheque_id)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: payment and replacement are set once';
                END IF;
            END
            DDL);

        // Plan ruling 2: which expenses a vendor bill pays. Write-once; whether a link is active comes from the bill's status.
        Schema::create('disbursement_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disbursement_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['disbursement_id', 'expense_id']);
            $table->index(['expense_id', 'disbursement_id']);
        });
        DB::unprepared("CREATE TRIGGER disbursement_expenses_no_update BEFORE UPDATE ON disbursement_expenses FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'disbursement_expenses are write-once'");
        DB::unprepared("CREATE TRIGGER disbursement_expenses_no_delete BEFORE DELETE ON disbursement_expenses FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'disbursement_expenses cannot be deleted'");

        // Plan ruling 6: money accounts are assets.
        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->boolean('is_money')->default(false)->after('system_key');
        });
        DB::table('ledger_accounts')->whereIn('system_key', ['cash', 'bank', 'card_clearing'])->where('type', 'asset')->update(['is_money' => true]);
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_money_chk CHECK (is_money = 0 OR type = 'asset')");

        // Plan ruling 7: added only where missing; a code already used on an installed chart moves to the next free number.
        $now = now();
        foreach (self::ACCOUNTS as $key => [$code, $en, $ar, $type, $rule]) {
            $id = DB::table('ledger_accounts')->where('system_key', $key)->value('id');
            if ($id === null) {
                while (DB::table('ledger_accounts')->where('code', $code)->exists()) {
                    $code = (string) ((int) $code + 1);
                }
                $id = DB::table('ledger_accounts')->insertGetId(['code' => $code, 'name_en' => $en, 'name_ar' => $ar, 'type' => $type, 'system_key' => $key,
                    'is_money' => false, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            if (! DB::table('ledger_rules')->where('key', $rule)->exists()) {
                DB::table('ledger_rules')->insert(['key' => $rule, 'ledger_account_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // Plan ruling 8. A reversal is its own row (reverses_id, unique: once).
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->foreignId('from_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->decimal('amount', 12, 3);
            $table->date('date');
            $table->string('reference', 100);
            $table->string('note', 1000)->nullable();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('cash_movements')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['date', 'id']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE cash_movements
                ADD CONSTRAINT cash_movements_kind_chk CHECK (kind IN ('transfer', 'bank_charge', 'bank_interest')),
                ADD CONSTRAINT cash_movements_amount_chk CHECK (amount > 0),
                ADD CONSTRAINT cash_movements_accounts_chk CHECK (
                    (kind = 'transfer' AND from_account_id IS NOT NULL AND to_account_id IS NOT NULL AND from_account_id <> to_account_id)
                    OR (kind = 'bank_charge' AND from_account_id IS NOT NULL AND to_account_id IS NULL)
                    OR (kind = 'bank_interest' AND from_account_id IS NULL AND to_account_id IS NOT NULL))
            SQL);
        DB::unprepared("CREATE TRIGGER cash_movements_no_update BEFORE UPDATE ON cash_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cash_movements are write-once: correct one by reversal'");
        DB::unprepared("CREATE TRIGGER cash_movements_no_delete BEFORE DELETE ON cash_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cash_movements cannot be deleted'");

        // Plan ruling 9.
        Schema::create('vat_returns', function (Blueprint $table) {
            $table->id();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('direction', 10);
            $table->string('method', 20);
            $table->decimal('amount', 12, 3);
            $table->date('date');
            $table->string('reference', 100);
            $table->string('note', 1000)->nullable();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('vat_returns')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['period_from', 'period_to']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE vat_returns
                ADD CONSTRAINT vat_returns_direction_chk CHECK (direction IN ('payment', 'refund')),
                ADD CONSTRAINT vat_returns_method_chk CHECK (method IN ('bank_transfer', 'cheque', 'cash')),
                ADD CONSTRAINT vat_returns_amount_chk CHECK (amount > 0),
                ADD CONSTRAINT vat_returns_period_chk CHECK (period_to >= period_from)
            SQL);
        DB::unprepared("CREATE TRIGGER vat_returns_no_update BEFORE UPDATE ON vat_returns FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'vat_returns are write-once: correct one by reversal'");
        DB::unprepared("CREATE TRIGGER vat_returns_no_delete BEFORE DELETE ON vat_returns FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'vat_returns cannot be deleted'");
    }

    public function down(): void
    {
        // Restoring the old disbursement and cheque checks and the old cheques_guard is not supported; roll back with migrate:fresh.
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('vat_returns');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('disbursement_expenses');
        DB::statement('ALTER TABLE ledger_accounts DROP CHECK ledger_accounts_money_chk');
        Schema::table('ledger_accounts', fn (Blueprint $table) => $table->dropColumn('is_money'));
    }
};
```

- [ ] **Step 4: The model and the rules.**
  - In `app/Models/LedgerAccount.php`:
    - add ` * @property bool $is_money` after ` * @property string|null $system_key`;
    - replace `protected $fillable = ['code', 'name_en', 'name_ar', 'type'];` with `protected $fillable = ['code', 'name_en', 'name_ar', 'type', 'is_money'];`;
    - replace `return ['type' => LedgerAccountType::class, 'active' => 'boolean'];` with `return ['type' => LedgerAccountType::class, 'active' => 'boolean', 'is_money' => 'boolean'];`.
  - In `app/Ledger/Rules.php`, in `EVENTS` after `'other_payments_out' => 'Other payments out',` add:

```php
        'bank_charges' => 'Bank charges',          // ledger money screens plan ruling 7
        'bank_interest' => 'Bank interest income',
```

    and in `type()` replace `in_array($key, ['rent_income', 'other_income', 'fee_income'], true) => LedgerAccountType::Income,` with:

```php
            in_array($key, ['rent_income', 'other_income', 'fee_income', 'bank_interest'], true) => LedgerAccountType::Income,
```

    and the default's comment `// category.*, lease_rent, other_payments_out` with `// category.*, lease_rent, other_payments_out, bank_charges`.

- [ ] **Step 5: Admin sets the flag.**
  - Replace `app/Actions/Ledger/SaveLedgerAccount.php` with:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\LedgerAccountType;
use App\Enums\PermissionName;
use App\Ledger\Rules;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Spec §3: Admin adds and renames accounts. Once an account has postings its type stays (the reports would change shape).
 * Ledger money screens plan ruling 6: Admin marks asset accounts as money accounts; a save without the flag keeps it.
 */
final class SaveLedgerAccount
{
    /** @param  array<string, mixed>  $data  code, name_en, name_ar, type, is_money (optional) */
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
            'is_money' => ['nullable', 'boolean'],
        ], ['code.regex' => __('Use up to 20 letters, digits, dots or dashes.')])->validate();

        return DB::transaction(function () use ($actor, $account, $v) {
            $account = $account === null ? new LedgerAccount : LedgerAccount::query()->lockForUpdate()->findOrFail($account->id);
            $old = $account->exists ? ['code' => $account->code, 'name_en' => $account->name_en, 'name_ar' => $account->name_ar, 'type' => $account->type->value,
                'is_money' => $account->is_money] : [];
            if ($account->exists && $account->type->value !== $v['type'] && $account->lines()->exists()) {
                throw ValidationException::withMessages(['type' => __('This account has postings, so its type cannot change.')]);
            }
            if ($account->exists && $account->type->value !== $v['type']) {
                foreach (DB::table('ledger_rules')->where('ledger_account_id', $account->id)->pluck('key') as $key) {
                    if (Rules::type((string) $key)->value !== $v['type']) {
                        throw ValidationException::withMessages(['type' => __('A posting rule uses this account and needs type :type. Point the rule at another account first.', ['type' => Rules::type((string) $key)->label()])]);
                    }
                }
            }
            $isMoney = isset($v['is_money']) ? (bool) $v['is_money'] : ($account->exists && $account->is_money);
            if ($isMoney && $v['type'] !== LedgerAccountType::Asset->value) {
                throw ValidationException::withMessages(['is_money' => __('Only an asset account can be a money account.')]);
            }
            $new = ['code' => trim((string) $v['code']), 'name_en' => trim((string) $v['name_en']), 'name_ar' => trim((string) $v['name_ar']), 'type' => (string) $v['type'],
                'is_money' => $isMoney];
            $account->fill($new)->save();
            Audit::log('ledger.account_saved', $account, $old, $new, causer: $actor);

            return $account;
        });
    }
}
```

  - In `app/Livewire/Ledger/Accounts.php`:
    - after `public mixed $type = 'expense';` add a blank line and `public mixed $is_money = false;`;
    - in `edit()`, after `$this->type = $account->type->value;` add `$this->is_money = $account->is_money;`;
    - in `cancel()`, replace `$this->reset('editingId', 'code', 'name_en', 'name_ar', 'type');` with `$this->reset('editingId', 'code', 'name_en', 'name_ar', 'type', 'is_money');`;
    - in `save()`, replace `['code' => $this->code, 'name_en' => $this->name_en, 'name_ar' => $this->name_ar, 'type' => $this->type]` with `['code' => $this->code, 'name_en' => $this->name_en, 'name_ar' => $this->name_ar, 'type' => $this->type, 'is_money' => $this->is_money]`.
  - In `resources/views/livewire/ledger/accounts.blade.php`:
    - replace `<form wire:submit="save" class="grid gap-3 sm:grid-cols-5">` with `<form wire:submit="save" class="grid gap-3 sm:grid-cols-6">`;
    - after the type `</flux:select>` (the one closing `wire:model="type"`), add:

```blade
            <div class="flex items-end"><flux:checkbox wire:model="is_money" :label="__('Money account')" /></div>
```

    - after `<flux:error name="type" />` add `<flux:error name="is_money" />`;
    - replace `<flux:table.cell>{{ $account->type->label() }}</flux:table.cell>` with `<flux:table.cell>{{ $account->type->label() }}{{ $account->is_money ? ' · '.__('Money account') : '' }}</flux:table.cell>`.

- [ ] **Step 6: The trigger count and the storage test's counts.**
  - In `app/Integrity/IntegrityCheck.php`, replace the `EXPECTED_TRIGGERS` line with:

```php
    public const int EXPECTED_TRIGGERS = 73; // 38 at the end of M3a + 2 (disbursements) + 8 (deposit settlements) + 1 (invoice line charge) + 3 (amendments; the two insert triggers are replaced, not added) + 1 (credit source) + 2 (owner payables) + 2 (owner charges) + 2 (owner statements) + 2 (payment tenders) + 6 (ledger journals and journal lines) + 6 (disbursement expenses, cash movements, VAT returns; cheques_guard replaced, not added)
```

  - In `tests/Feature/Ledger/LedgerStorageTest.php`:
    - replace `expect(LedgerAccount::query()->count())->toBe(24)` with `expect(LedgerAccount::query()->count())->toBe(26)`;
    - replace `->and(DB::table('ledger_rules')->count())->toBe(25)` with `->and(DB::table('ledger_rules')->count())->toBe(27)`;
    - replace `->and(IntegrityCheck::EXPECTED_TRIGGERS)->toBe(67)` with `->and(IntegrityCheck::EXPECTED_TRIGGERS)->toBe(73)`;
    - replace `->and((int) DB::selectOne('SELECT rms_trigger_count() AS n')->n)->toBe(67);` with `->and((int) DB::selectOne('SELECT rms_trigger_count() AS n')->n)->toBe(73);`.

- [ ] **Step 7: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Bank charges": "الرسوم المصرفية",
    "Bank interest income": "إيرادات الفوائد البنكية",
    "Money account": "حساب نقدي",
    "Only an asset account can be a money account.": "حساب الأصول وحده يمكن أن يكون حساباً نقدياً.",
```

- [ ] **Step 8: Migrate the dev DB.**

Run: `php artisan migrate --database=migrator --force`
Expected: `2026_12_28_000100_create_ledger_money_tables ... DONE`. The dev chart already has 24 accounts. If Admin added one with code 5180 or 4300, the new account takes the next free code.

- [ ] **Step 9: Run the tests.**

Run: `php artisan test tests/Feature/Ledger/MoneyScreensStorageTest.php tests/Feature/Ledger/LedgerStorageTest.php tests/Feature/Ledger/LedgerSetupTest.php tests/Feature/Integrity/IntegrityCheckTest.php tests/Feature/Disbursements/DisbursementTriggersTest.php tests/Feature/Cheques`
Expected: PASS. IntegrityCheckTest sees 73. The cheque and disbursement trigger tests still pass with the re-created guard and the widened checks.

- [ ] **Step 10: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add database/migrations/2026_12_28_000100_create_ledger_money_tables.php app/Models/LedgerAccount.php app/Ledger/Rules.php app/Actions/Ledger/SaveLedgerAccount.php app/Livewire/Ledger/Accounts.php resources/views/livewire/ledger/accounts.blade.php app/Integrity/IntegrityCheck.php tests/Feature/Ledger/LedgerStorageTest.php tests/Feature/Ledger/MoneyScreensStorageTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
Ledger money screens storage: vendor payees, the expense link, money accounts, Bank charges and Bank interest income, cash movements and VAT returns, six triggers (73)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Vendor bills on payments out: the payee, the expense link, approval, paying, cheques, reversal and the posting

**Files:**
- Modify: `app/Enums/PayeeType.php`, `app/Enums/DisbursementPurpose.php`, `app/Models/Disbursement.php`, `app/Models/Expense.php`, `app/Models/Cheque.php`, `app/Actions/Disbursements/RecordDisbursement.php`, `app/Actions/Disbursements/PayDisbursement.php`, `app/Actions/Disbursements/MarkDisbursementPaid.php`, `app/Actions/Expenses/ReverseExpense.php`, `app/Approvals/PaymentOut.php`, `app/Approvals/PaymentOutReversal.php`, `app/Livewire/Disbursements/Index.php`, `resources/views/livewire/disbursements/index.blade.php`, `resources/views/livewire/disbursements/show.blade.php`, `app/Reports/Builder/Subjects/PaymentsOut.php`, `app/Ledger/Sources.php`, `tests/Pest.php`, `lang/ar.json`
- Test: `tests/Feature/Disbursements/VendorBillTest.php`

**Interfaces:**
- Consumes: Task 1's tables and checks; `RequestApproval::handle(User, Model, ApprovalAction, ?string)`; `DecideApproval::handle(User, Approval, bool, ?string)`; `ledgerFixture()`, `maintenanceVendor()`, `maintenanceRequest()`, `workOrderFor()`, `fixtureBanks()`.
- Produces:
  - `PayeeType::Vendor` (`vendor`); `DisbursementPurpose::VendorBill` (`vendor_bill`).
  - `Disbursement::ACTIVE` = `['pending_approval', 'approved', 'paid']`; `Disbursement::payee(): Customer|Owner|Vendor`; `Disbursement::payeeName(): string`; `Disbursement::expenses(): BelongsToMany<Expense>`.
  - `Expense::disbursements(): BelongsToMany<Disbursement>`; `Expense::activePayment(): ?Disbursement`; scope `Expense::query()->unpaid()`.
  - `RecordDisbursement::handle(User, ['purpose' => 'vendor_bill', 'payee_type' => 'vendor', 'payee_id' => int, 'expense_ids' => list<int|string>, 'method' => string, 'reference'?, 'notes'?])`: a `pending_approval` disbursement. Error keys `payee_id`, `expense_ids`.
  - `PayDisbursement` refuses a vendor bill's `paid_on` before its latest expense (error key `paid_on`). `ReverseExpense` refuses an expense on an active payment (key `reason`).
  - Postings: `disbursement` for a paid vendor bill is `['2200 <amount> Dr', '<method account> <amount> Cr']`; `disbursement_reversal` is the opposite.
  - Pest `vendorBillFixture(): array`: `ledgerFixture()`'s keys plus `vendor` (Fix-It Co) and `workOrder` (on T1, completed 08/10, its expense `companyExpense`).

- [ ] **Step 1: The fixture.** In `tests/Pest.php` append:

```php
/**
 * The general ledger worked example plus a vendor (ledger money screens plan rulings 2 and 5): "Fix-It Co" did the "Lobby
 * lights" job (the fixture's company expense, 50.000) as a work order on T1, completed 08/10. "T1 tap" (30.000) has no
 * work order. Seed RolesAndPermissionsSeeder and fake the local disk first. Ends on 20/10/2026 10:00.
 *
 * @return array<string, mixed>
 */
function vendorBillFixture(): array
{
    $f = ledgerFixture();
    $vendor = maintenanceVendor();
    $workOrder = workOrderFor($f['admin'], maintenanceRequest($f['admin'], $f['t1']), $vendor);
    $workOrder->forceFill(['status' => 'completed', 'completed_on' => '2026-10-08', 'expense_id' => $f['companyExpense']->id])->save();

    return [...$f, 'vendor' => $vendor, 'workOrder' => $workOrder];
}
```

- [ ] **Step 2: Write the failing test** `tests/Feature/Disbursements/VendorBillTest.php`:

```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\PayDisbursement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\Expenses\ReverseExpense;
use App\Models\Approval;
use App\Models\Expense;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    $this->f = vendorBillFixture();
    $this->bill = fn (mixed $ids, array $data = []) => app(RecordDisbursement::class)->handle($this->f['finance'], ['purpose' => 'vendor_bill', 'payee_type' => 'vendor',
        'payee_id' => $this->f['vendor']->id, 'expense_ids' => $ids, 'method' => 'bank_transfer', ...$data]);
    $this->decide = fn (bool $approve) => app(DecideApproval::class)->handle($this->f['management'], Approval::query()->latest('id')->firstOrFail(), $approve,
        $approve ? null : 'Not this one');
});

test('a vendor bill pays the full totals of the ticked expenses and waits for Management', function () {
    $f = $this->f;
    $out = ($this->bill)([(string) $f['ownerExpense']->id, $f['companyExpense']->id]);

    expect($out->status->value)->toBe('pending_approval')
        ->and([$out->payee_type->value, $out->purpose->value, $out->amount])->toBe(['vendor', 'vendor_bill', '80.000'])
        ->and($out->payeeName())->toBe('Fix-It Co')
        ->and($out->expenses()->orderBy('expenses.id')->pluck('expenses.id')->all())->toBe([$f['companyExpense']->id, $f['ownerExpense']->id])
        ->and(Approval::query()->latest('id')->firstOrFail()->reason)->toContain('Lobby lights (50.000)')
        ->and(Expense::query()->unpaid()->count())->toBe(0)
        ->and($f['companyExpense']->fresh()->activePayment()?->id)->toBe($out->id)
        ->and(journalFor('disbursement', $out->id))->toBeNull();
});

test('an expense is on one active payment out at a time; a rejection frees it', function () {
    $f = $this->f;
    $first = ($this->bill)([$f['companyExpense']->id]);

    expect(fn () => ($this->bill)([$f['companyExpense']->id, $f['ownerExpense']->id]))->toThrow(ValidationException::class, 'Lobby lights is already on payment out');

    ($this->decide)(false);

    expect($first->fresh()->status->value)->toBe('rejected')
        ->and(journalFor('disbursement', $first->id))->toBeNull()
        ->and(Expense::query()->unpaid()->whereKey($f['companyExpense']->id)->exists())->toBeTrue()
        ->and(($this->bill)([$f['companyExpense']->id])->status->value)->toBe('pending_approval');
});

test('paying posts Vendor payable against the payout account and marks the expenses paid; a reversal posts the opposite and unpays them', function () {
    $f = $this->f;
    $out = ($this->bill)([$f['companyExpense']->id, $f['ownerExpense']->id]);
    ($this->decide)(true);

    expect(journalFor('disbursement', $out->id))->toBeNull()
        ->and(fn () => app(PayDisbursement::class)->handle($f['finance'], $out->fresh(), ['method' => 'bank_transfer', 'paid_on' => '2026-10-07']))
        ->toThrow(ValidationException::class, 'latest expense (08/10/2026)');

    $paid = app(PayDisbursement::class)->handle($f['finance'], $out->fresh(), ['method' => 'cheque', 'paid_on' => '2026-10-20', 'cheque_no' => '000777',
        'bank_name' => 'NBB', 'cheque_date' => '2026-10-20']);

    expect($paid->status->value)->toBe('paid')
        ->and([$paid->cheque->vendor_id, $paid->cheque->owner_id, $paid->cheque->customer_id])->toBe([$f['vendor']->id, null, null])
        ->and($f['companyExpense']->fresh()->activePayment()?->status->value)->toBe('paid')
        ->and(postedLines('disbursement', $paid->id))->toBe(['2200 80.000 0.000', '1010 0.000 80.000'])
        ->and(journalFor('disbursement', $paid->id)->date->toDateString())->toBe('2026-10-20')
        ->and(fn () => app(ReverseExpense::class)->handle($f['finance'], $f['companyExpense'], 'Duplicate'))
        ->toThrow(ValidationException::class, 'Reject or reverse that payment out first');

    app(DecideApproval::class)->handle($f['management'], app(RequestDisbursementReversal::class)->handle($f['finance'], $paid, 'Cheque lost'), true);

    expect($paid->fresh()->status->value)->toBe('reversed')
        ->and($paid->fresh()->cheque->status->value)->toBe('cancelled')
        ->and($f['companyExpense']->fresh()->activePayment())->toBeNull()
        ->and(Expense::query()->unpaid()->count())->toBe(2)
        ->and(postedLines('disbursement_reversal', $paid->id))->toBe(['2200 0.000 80.000', '1010 80.000 0.000']);
});

test('wrong or tampered input gives a message, never a 500', function () {
    $f = $this->f;

    expect(fn () => ($this->bill)([]))->toThrow(ValidationException::class, 'Tick at least one expense')
        ->and(fn () => ($this->bill)('oops'))->toThrow(ValidationException::class)
        ->and(fn () => ($this->bill)([['x']]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->bill)([999999]))->toThrow(ValidationException::class, 'Choose expenses from the list')
        ->and(fn () => ($this->bill)([$f['companyExpense']->id], ['payee_id' => 999999]))->toThrow(ValidationException::class, 'Choose the vendor')
        ->and(fn () => ($this->bill)([$f['companyExpense']->id], ['payee_type' => 'owner']))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordDisbursement::class)->handle($f['finance'], ['purpose' => 'other', 'payee_type' => 'vendor', 'payee_id' => $f['vendor']->id,
            'amount' => '5', 'method' => 'cash', 'reason' => 'Key cutting']))->toThrow(ValidationException::class);

    app(ReverseExpense::class)->handle($f['finance'], $f['ownerExpense'], 'Entered twice');

    expect(fn () => ($this->bill)([$f['ownerExpense']->id]))->toThrow(ValidationException::class, 'T1 tap is reversed');
});
```

- [ ] **Step 3: Run it to see it fail.**

Run: `php artisan test tests/Feature/Disbursements/VendorBillTest.php`
Expected: FAIL. `"vendor_bill"` is not a valid purpose (the selected purpose is invalid).

- [ ] **Step 4: The enums.**
  - In `app/Enums/PayeeType.php`, after `case Customer = 'customer';` add:

```php
    case Vendor = 'vendor'; // ledger money screens plan ruling 1: only with purpose vendor_bill; payee_id is a vendors.id
```

  - In `app/Enums/DisbursementPurpose.php`, after `case Other = 'other';` add:

```php
    case VendorBill = 'vendor_bill'; // ledger money screens: pays recorded expenses in full
```

- [ ] **Step 5: The models.**
  - In `app/Models/Disbursement.php`:
    - add `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` after `use Illuminate\Database\Eloquent\Relations\BelongsTo;`;
    - add ` * @property-read \Illuminate\Database\Eloquent\Collection<int, Expense> $expenses` after ` * @property CarbonImmutable|null $reversed_at`;
    - after `public const string SOURCE_STATEMENT = 'owner_statement';` add:

```php

    /** Ledger money screens plan ruling 2: the statuses in which a vendor bill holds its expenses. */
    public const array ACTIVE = ['pending_approval', 'approved', 'paid'];
```

    - replace the whole `payee()` method with:

```php
    public function payee(): Customer|Owner|Vendor
    {
        return match ($this->payee_type) {
            PayeeType::Customer => Customer::withTrashed()->findOrFail($this->payee_id),
            PayeeType::Owner => Owner::withTrashed()->findOrFail($this->payee_id),
            PayeeType::Vendor => Vendor::withTrashed()->findOrFail($this->payee_id),
        };
    }

    /** The payee's name: a tenant's or owner's English name, or a vendor's name. */
    public function payeeName(): string
    {
        $payee = $this->payee();

        return $payee instanceof Vendor ? $payee->name : $payee->name_en;
    }

    /** @return BelongsToMany<Expense, $this> ledger money screens plan ruling 2: what a vendor bill pays */
    public function expenses(): BelongsToMany
    {
        return $this->belongsToMany(Expense::class, 'disbursement_expenses');
    }
```

  - In `app/Models/Expense.php`:
    - add `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` after `use Illuminate\Database\Eloquent\Relations\BelongsTo;`;
    - add ` * @property string $description` after ` * @property ExpenseCategory $category`;
    - after the `recorder()` method add:

```php
    /** @return BelongsToMany<Disbursement, $this> ledger money screens plan ruling 2: the vendor bills it was ever on */
    public function disbursements(): BelongsToMany
    {
        return $this->belongsToMany(Disbursement::class, 'disbursement_expenses');
    }

    /** Ledger money screens plan ruling 3: the one vendor bill waiting, approved or paid that holds it, if any. */
    public function activePayment(): ?Disbursement
    {
        return $this->disbursements()->whereIn('disbursements.status', Disbursement::ACTIVE)->first();
    }

    /**
     * Ledger money screens plan ruling 2: recorded and on no vendor bill that is waiting, approved or paid; what a new
     * vendor bill may pay.
     *
     * @param  Builder<Expense>  $query
     */
    #[Scope]
    protected function unpaid(Builder $query): void
    {
        $query->where('expenses.status', ExpenseStatus::Recorded->value)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('disbursement_expenses as de')->join('disbursements as d', 'd.id', '=', 'de.disbursement_id')
                ->whereColumn('de.expense_id', 'expenses.id')->whereIn('d.status', Disbursement::ACTIVE));
    }
```

  - In `app/Models/Cheque.php`:
    - add ` * @property int|null $vendor_id` after ` * @property int|null $customer_id`;
    - in `visibleTo()`, replace `->orWhereNotNull('owner_id'));` with `->orWhereNotNull('owner_id')->orWhereNotNull('vendor_id'));`.

- [ ] **Step 6: Recording a vendor bill.** Replace `app/Actions/Disbursements/RecordDisbursement.php` with:

```php
<?php

namespace App\Actions\Disbursements;

use App\Actions\Approvals\RequestApproval;
use App\Billing\OwnerLedger;
use App\Enums\ApprovalAction;
use App\Enums\DepositSettlementStatus;
use App\Enums\DisbursementMethod;
use App\Enums\DisbursementPurpose;
use App\Enums\DisbursementStatus;
use App\Enums\ExpenseStatus;
use App\Enums\OwnerPayableStatus;
use App\Enums\OwnerStatementStatus;
use App\Enums\PayeeType;
use App\Enums\PaymentStatus;
use App\Models\Bank;
use App\Models\Customer;
use App\Models\DepositSettlement;
use App\Models\Disbursement;
use App\Models\Expense;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\OwnerPayable;
use App\Models\OwnerStatement;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Spec §7.5. With a source (a payment holding credit; a deposit settlement; a head-lease payable): paid at once, within the
 * source's limit. Without one (purpose other) and for a vendor bill (ledger money screens plan ruling 1): pending →
 * Management (§8.3 item 9) → approved → PayDisbursement.
 */
final class RecordDisbursement
{
    public function __construct(private MarkDisbursementPaid $paid, private RequestApproval $request) {}

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, array $data): Disbursement
    {
        if (! $actor->can('create', Disbursement::class)) {
            throw new AuthorizationException;
        }

        $today = now('Asia/Bahrain')->toDateString();
        $vendorBill = ($data['purpose'] ?? null) === DisbursementPurpose::VendorBill->value;
        // A source-less payment out (purpose other) and a vendor bill get their cheque details when Finance pays them, not now.
        $chequeNow = ($data['method'] ?? null) === DisbursementMethod::Cheque->value
            && in_array($data['purpose'] ?? null, [DisbursementPurpose::CreditRefund->value, DisbursementPurpose::DepositRefund->value, DisbursementPurpose::HeadLease->value, DisbursementPurpose::OwnerRemittance->value], true);
        $v = Validator::make($data, [
            'purpose' => ['required', Rule::in([DisbursementPurpose::CreditRefund->value, DisbursementPurpose::DepositRefund->value, DisbursementPurpose::HeadLease->value, DisbursementPurpose::OwnerRemittance->value, DisbursementPurpose::Other->value, DisbursementPurpose::VendorBill->value])],
            'amount' => [Rule::requiredIf(! $vendorBill), 'nullable', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'], // a vendor bill's total is its expenses'
            'method' => ['required', Rule::enum(DisbursementMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'paid_on' => ['required_unless:purpose,other,vendor_bill', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'payment_id' => ['required_if:purpose,credit_refund', 'nullable', 'integer', Rule::exists('payments', 'id')],
            'deposit_settlement_id' => ['required_if:purpose,deposit_refund', 'nullable', 'integer', Rule::exists('deposit_settlements', 'id')],
            'owner_payable_id' => ['required_if:purpose,head_lease', 'nullable', 'integer', Rule::exists('owner_payables', 'id')],
            'owner_statement_id' => ['required_if:purpose,owner_remittance', 'nullable', 'integer', Rule::exists('owner_statements', 'id')],
            'payee_type' => ['required_if:purpose,other,vendor_bill', 'nullable', Rule::in($vendorBill ? [PayeeType::Vendor->value] : [PayeeType::Owner->value, PayeeType::Customer->value])],
            'payee_id' => ['required_if:purpose,other,vendor_bill', 'nullable', 'integer'],
            'expense_ids' => ['required_if:purpose,vendor_bill', 'nullable', 'array', 'max:200'],
            'expense_ids.*' => ['integer'],
            'cheque_no' => [Rule::requiredIf($chequeNow), 'nullable', 'string', 'max:30'],
            'bank_name' => [Rule::requiredIf($chequeNow), 'nullable', 'string', 'max:100', Bank::rule()],
            'cheque_date' => [Rule::requiredIf($chequeNow), 'nullable', 'date_format:Y-m-d'],
            'reason' => ['required_if:purpose,other', 'nullable', 'string', 'max:2000'],
        ], ['expense_ids.required_if' => __('Tick at least one expense.')])->validate();

        $amount = filled($v['amount'] ?? null) ? Fils::fromDecimal((string) $v['amount']) : 0;

        // The first-lock row's id (spec §7.2: the customer, or the owner contract) is read before the transaction, so
        // the first read inside it is that locking read. A plain read first would fix the InnoDB snapshot and hide later
        // commits from the limit checks below. These ids never change on their rows.
        $lockId = (int) match ($v['purpose']) {
            DisbursementPurpose::CreditRefund->value => Payment::query()->whereKey($v['payment_id'])->value('customer_id'),
            DisbursementPurpose::DepositRefund->value => DepositSettlement::query()->join('agreements', 'agreements.id', '=', 'deposit_settlements.agreement_id')
                ->where('deposit_settlements.id', $v['deposit_settlement_id'])->value('agreements.customer_id'),
            DisbursementPurpose::HeadLease->value => OwnerPayable::query()->whereKey($v['owner_payable_id'])->value('owner_contract_id'),
            DisbursementPurpose::OwnerRemittance->value => OwnerStatement::query()->whereKey($v['owner_statement_id'])->value('owner_contract_id'),
            default => 0,
        };

        return DB::transaction(function () use ($actor, $v, $amount, $lockId) {
            return match ($v['purpose']) {
                DisbursementPurpose::CreditRefund->value => $this->creditRefund($actor, $v, $amount, $lockId),
                DisbursementPurpose::DepositRefund->value => $this->depositRefund($actor, $v, $amount, $lockId),
                DisbursementPurpose::HeadLease->value => $this->headLease($actor, $v, $amount, $lockId),
                DisbursementPurpose::OwnerRemittance->value => $this->remittance($actor, $v, $amount, $lockId),
                DisbursementPurpose::VendorBill->value => $this->vendorBill($actor, $v),
                default => $this->other($actor, $v, $amount),
            };
        }, attempts: 3);
    }

    /** @param  array<string, mixed>  $v */
    private function creditRefund(User $actor, array $v, int $amount, int $customerId): Disbursement
    {
        $customer = Customer::query()->lockForUpdate()->findOrFail($customerId); // first lock (spec §7.2)
        if (! Customer::visibleTo($actor)->whereKey($customer->id)->exists()) {
            throw new AuthorizationException;
        }
        $payment = Payment::query()->lockForUpdate()->findOrFail((int) $v['payment_id']);

        if ($payment->status !== PaymentStatus::Confirmed || $amount > $payment->unallocatedFils()) {
            throw ValidationException::withMessages(['amount' => __('At most :c BHD of this payment is credit that can be refunded.', ['c' => Fils::toDecimal(max(0, $payment->unallocatedFils()))])]);
        }
        if ((string) $v['paid_on'] < $payment->received_on->toDateString()) {
            throw ValidationException::withMessages(['paid_on' => __('The refund cannot be paid before the payment was received (:d).', ['d' => $payment->received_on->format('d/m/Y')])]);
        }

        $out = (new Disbursement)->forceFill([
            'payee_type' => PayeeType::Customer,
            'payee_id' => $customer->id,
            'purpose' => DisbursementPurpose::CreditRefund,
            'amount' => Fils::toDecimal($amount),
            'method' => $v['method'],
            'source_type' => Disbursement::SOURCE_PAYMENT,
            'source_id' => $payment->id,
            'status' => DisbursementStatus::Approved, // within its source's limit (spec §7.5): straight on to paid
            'notes' => $v['notes'] ?? null,
            'created_by' => $actor->id,
        ]);
        $out->save();
        $this->paid->handle($out, [
            'method' => $v['method'], 'paid_on' => $v['paid_on'], 'reference' => $v['reference'] ?? null,
            ...array_intersect_key($v, array_flip(['cheque_no', 'bank_name', 'cheque_date'])),
        ], $actor);

        return $out->refresh();
    }

    /** @param  array<string, mixed>  $v */
    private function depositRefund(User $actor, array $v, int $amount, int $customerId): Disbursement
    {
        $customer = Customer::query()->lockForUpdate()->findOrFail($customerId); // first lock
        $settlement = DepositSettlement::query()->lockForUpdate()->with('agreement')->findOrFail((int) $v['deposit_settlement_id']);
        if (! $actor->can('view', $settlement)) {
            throw new AuthorizationException;
        }

        $left = $settlement->refundFils() - $settlement->refundedFils();
        if ($settlement->status !== DepositSettlementStatus::Approved || $amount > $left) {
            throw ValidationException::withMessages(['amount' => __('At most :c BHD of this settlement is left to refund.', ['c' => Fils::toDecimal(max(0, $left))])]);
        }

        $out = (new Disbursement)->forceFill([
            'payee_type' => PayeeType::Customer,
            'payee_id' => $customer->id,
            'purpose' => DisbursementPurpose::DepositRefund,
            'amount' => Fils::toDecimal($amount),
            'method' => $v['method'],
            'source_type' => Disbursement::SOURCE_SETTLEMENT,
            'source_id' => $settlement->id,
            'status' => DisbursementStatus::Approved,
            'notes' => $v['notes'] ?? null,
            'created_by' => $actor->id,
        ]);
        $out->save();
        $this->paid->handle($out, [...array_intersect_key($v, array_flip(['cheque_no', 'bank_name', 'cheque_date'])), 'method' => $v['method'], 'paid_on' => $v['paid_on'], 'reference' => $v['reference'] ?? null], $actor);

        return $out->refresh();
    }

    /** @param  array<string, mixed>  $v */
    private function headLease(User $actor, array $v, int $amount, int $contractId): Disbursement
    {
        $contract = OwnerContract::query()->lockForUpdate()->findOrFail($contractId); // owner-side first lock (spec §7.2)
        if (! OwnerContract::visibleTo($actor)->whereKey($contract->id)->exists()) {
            throw new AuthorizationException;
        }
        $payable = OwnerPayable::query()->lockForUpdate()->findOrFail((int) $v['owner_payable_id']);
        if ($payable->status === OwnerPayableStatus::Cancelled) {
            throw ValidationException::withMessages(['owner_payable_id' => __('This head-lease payment was cancelled.')]);
        }

        // Spec §7.5: paid exactly once, for its amount. Anything else — already paid, another payment of it waiting,
        // a different amount — goes to Management (plan ruling 4).
        $waiting = Disbursement::query()->where('source_type', Disbursement::SOURCE_PAYABLE)->where('source_id', $payable->id)
            ->whereIn('status', [DisbursementStatus::PendingApproval, DisbursementStatus::Approved])->exists();
        $within = $payable->status === OwnerPayableStatus::Scheduled && ! $waiting && $amount === Fils::fromDecimal($payable->amount);

        $out = (new Disbursement)->forceFill([
            'payee_type' => PayeeType::Owner,
            'payee_id' => $contract->owner_id, // copied from the source (spec §7.5)
            'purpose' => DisbursementPurpose::HeadLease,
            'amount' => Fils::toDecimal($amount),
            'method' => $v['method'],
            'reference' => $v['reference'] ?? null,
            'source_type' => Disbursement::SOURCE_PAYABLE,
            'source_id' => $payable->id,
            'status' => $within ? DisbursementStatus::Approved : DisbursementStatus::PendingApproval,
            'reason' => $within ? null : __('Head-lease payment :c for :from – :to (:due BHD, :status) outside its limit.', [
                'c' => $contract->number, 'from' => $payable->period_start->format('d/m/Y'), 'to' => $payable->period_end->format('d/m/Y'),
                'due' => $payable->amount, 'status' => $payable->status->label(),
            ]),
            'notes' => $v['notes'] ?? null,
            'created_by' => $actor->id,
        ]);
        $out->save();

        if (! $within) {
            $this->request->handle($actor, $out, ApprovalAction::PaymentOut, (string) $out->reason);

            return $out; // its cheque details are taken when Finance pays it
        }

        $this->paid->handle($out, [...array_intersect_key($v, array_flip(['cheque_no', 'bank_name', 'cheque_date'])), 'method' => $v['method'], 'paid_on' => $v['paid_on'], 'reference' => $v['reference'] ?? null], $actor);

        return $out->refresh();
    }

    /** @param  array<string, mixed>  $v */
    private function remittance(User $actor, array $v, int $amount, int $contractId): Disbursement
    {
        $contract = OwnerContract::query()->lockForUpdate()->findOrFail($contractId); // owner-side first lock (spec §7.5)
        if (! OwnerContract::visibleTo($actor)->whereKey($contract->id)->exists()) {
            throw new AuthorizationException;
        }
        $statement = OwnerStatement::query()->lockForUpdate()->findOrFail((int) $v['owner_statement_id']);
        if ($statement->status !== OwnerStatementStatus::Finalised) {
            throw ValidationException::withMessages(['owner_statement_id' => __('Only a finalised statement can be remitted.')]);
        }

        $left = OwnerLedger::remittableFils($contract);
        $within = $amount <= $left;

        $out = (new Disbursement)->forceFill([
            'payee_type' => PayeeType::Owner,
            'payee_id' => $contract->owner_id, // copied from the source
            'purpose' => DisbursementPurpose::OwnerRemittance,
            'amount' => Fils::toDecimal($amount),
            'method' => $v['method'],
            'reference' => $v['reference'] ?? null,
            'source_type' => Disbursement::SOURCE_STATEMENT,
            'source_id' => $statement->id,
            'status' => $within ? DisbursementStatus::Approved : DisbursementStatus::PendingApproval,
            'reason' => $within ? null : __('Remittance of :a BHD for :c is above what can be remitted now (:l BHD).', [
                'a' => Fils::toDecimal($amount), 'c' => $contract->number, 'l' => Fils::toDecimal($left),
            ]),
            'notes' => $v['notes'] ?? null,
            'created_by' => $actor->id,
        ]);
        $out->save();

        if (! $within) {
            $this->request->handle($actor, $out, ApprovalAction::PaymentOut, (string) $out->reason);

            return $out;
        }

        $this->paid->handle($out, [...array_intersect_key($v, array_flip(['cheque_no', 'bank_name', 'cheque_date'])), 'method' => $v['method'], 'paid_on' => $v['paid_on'], 'reference' => $v['reference'] ?? null], $actor);

        return $out->refresh();
    }

    /**
     * Ledger money screens spec §2 (plan rulings 1–2): the full totals of the ticked expenses, sent to Management. The
     * expense rows are locked in id order before their links are read with a locking read, so two bills never take the
     * same expense; ReverseExpense takes the same row lock.
     *
     * @param  array<string, mixed>  $v
     */
    private function vendorBill(User $actor, array $v): Disbursement
    {
        $vendor = Vendor::query()->find((int) $v['payee_id']);
        if ($vendor === null) {
            throw ValidationException::withMessages(['payee_id' => __('Choose the vendor.')]);
        }
        $ids = array_values(array_unique(array_map('intval', $v['expense_ids'])));
        sort($ids);
        $expenses = Expense::query()->visibleTo($actor)->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        if ($expenses->count() !== count($ids)) {
            throw ValidationException::withMessages(['expense_ids' => __('Choose expenses from the list.')]);
        }
        $reversed = $expenses->first(fn (Expense $e) => $e->status === ExpenseStatus::Reversed);
        if ($reversed !== null) {
            throw ValidationException::withMessages(['expense_ids' => __('Expense :description is reversed.', ['description' => $reversed->description])]);
        }
        $taken = DB::table('disbursement_expenses as de')->join('disbursements as d', 'd.id', '=', 'de.disbursement_id')
            ->whereIn('de.expense_id', $ids)->whereIn('d.status', Disbursement::ACTIVE)->orderBy('de.expense_id')->lockForUpdate()
            ->first(['de.expense_id', 'd.id']);
        if ($taken !== null) {
            throw ValidationException::withMessages(['expense_ids' => __('Expense :description is already on payment out :n.', [
                'description' => $expenses->firstWhere('id', (int) $taken->expense_id)?->description, 'n' => Disbursement::query()->findOrFail((int) $taken->id)->label(),
            ])]);
        }

        $out = (new Disbursement)->forceFill([
            'payee_type' => PayeeType::Vendor,
            'payee_id' => $vendor->id,
            'purpose' => DisbursementPurpose::VendorBill,
            'amount' => Fils::toDecimal((int) $expenses->sum(fn (Expense $e) => Fils::fromDecimal($e->total))),
            'method' => $v['method'],
            'reference' => $v['reference'] ?? null,
            'status' => DisbursementStatus::PendingApproval,
            'reason' => Str::limit(__('Vendor bill for :vendor: :list', ['vendor' => $vendor->name,
                'list' => $expenses->map(fn (Expense $e) => $e->description.' ('.$e->total.')')->implode('; ')]), 2000),
            'notes' => $v['notes'] ?? null,
            'created_by' => $actor->id,
        ]);
        $out->save();
        $out->expenses()->attach($ids);
        $this->request->handle($actor, $out, ApprovalAction::PaymentOut, (string) $out->reason);

        return $out;
    }

    /** @param  array<string, mixed>  $v */
    private function other(User $actor, array $v, int $amount): Disbursement
    {
        $payeeExists = $v['payee_type'] === PayeeType::Customer->value
            ? Customer::visibleTo($actor)->whereKey($v['payee_id'])->exists()
            : Owner::query()->whereKey($v['payee_id'])->exists();
        if (! $payeeExists) {
            throw ValidationException::withMessages(['payee_id' => __('Choose the payee.')]);
        }

        $out = (new Disbursement)->forceFill([
            'payee_type' => $v['payee_type'],
            'payee_id' => (int) $v['payee_id'],
            'purpose' => DisbursementPurpose::Other,
            'amount' => Fils::toDecimal($amount),
            'method' => $v['method'],
            'reference' => $v['reference'] ?? null,
            'status' => DisbursementStatus::PendingApproval,
            'reason' => trim((string) $v['reason']),
            'notes' => $v['notes'] ?? null,
            'created_by' => $actor->id,
        ]);
        $out->save();
        $this->request->handle($actor, $out, ApprovalAction::PaymentOut, $out->reason);

        return $out;
    }
}
```

- [ ] **Step 7: Paying, the cheque, and expense reversal.**
  - In `app/Actions/Disbursements/PayDisbursement.php`:
    - add `use App\Enums\DisbursementPurpose;` after `use App\Enums\DisbursementMethod;` and `use Carbon\CarbonImmutable;` after `use App\Models\User;`;
    - immediately before `$this->paid->handle($locked, $v, $actor);` (after the re-cut head-lease check) add:

```php
            // Ledger money screens plan ruling 2: a vendor bill is never paid before the last expense it pays.
            $latest = $locked->purpose === DisbursementPurpose::VendorBill ? $locked->expenses()->max('expenses.expense_date') : null;
            if (is_string($latest) && (string) $v['paid_on'] < $latest) {
                throw ValidationException::withMessages(['paid_on' => __('The payment cannot be dated before its latest expense (:d).', ['d' => CarbonImmutable::parse($latest)->format('d/m/Y')])]);
            }
```

  - In `app/Actions/Disbursements/MarkDisbursementPaid.php`, after `'owner_id' => $locked->payee_type === PayeeType::Owner ? $locked->payee_id : null,` add:

```php
                'vendor_id' => $locked->payee_type === PayeeType::Vendor ? $locked->payee_id : null, // ledger money screens plan ruling 1
```

  - In `app/Actions/Expenses/ReverseExpense.php`:
    - add `use App\Models\Disbursement;` after `use App\Enums\ExpenseStatus;`;
    - after the block `if ($expense->status === ExpenseStatus::Reversed) { … }` add:

```php
            // Ledger money screens plan ruling 2: a vendor bill holding it would pay a reversed expense.
            $on = DB::table('disbursement_expenses as de')->join('disbursements as d', 'd.id', '=', 'de.disbursement_id')
                ->where('de.expense_id', $expense->id)->whereIn('d.status', Disbursement::ACTIVE)->lockForUpdate()->value('d.id');
            if ($on !== null) {
                throw ValidationException::withMessages(['reason' => __('This expense is on payment out :n. Reject or reverse that payment out first.', [
                    'n' => Disbursement::query()->findOrFail((int) $on)->label(),
                ])]);
            }
```

- [ ] **Step 8: Payee names, the list, the report subject.**
  - `app/Approvals/PaymentOut.php`: replace `'payee' => $out->payee()->name_en,` with `'payee' => $out->payeeName(),`.
  - `app/Approvals/PaymentOutReversal.php`: replace `'n' => $out->number, 'amount' => $out->amount, 'payee' => $out->payee()->name_en,` with `'n' => $out->number, 'amount' => $out->amount, 'payee' => $out->payeeName(),`.
  - `resources/views/livewire/disbursements/index.blade.php`: replace `<flux:table.cell>{{ $row->payee()->name_en }}</flux:table.cell>` with `<flux:table.cell>{{ $row->payeeName() }}</flux:table.cell>`.
  - `resources/views/livewire/disbursements/show.blade.php`: replace `<dd>{{ $out->payee()->name_en }} ({{ $out->payee_type->value === 'customer' ? __('tenant') : __('owner') }})</dd>` with:

```blade
<dd>{{ $out->payeeName() }} ({{ $out->payee_type->value === 'customer' ? __('tenant') : ($out->payee_type->value === 'vendor' ? __('vendor') : __('owner')) }})</dd>
```

  - `app/Livewire/Disbursements/Index.php`: replace

```php
            ->where(fn ($q) => $q->where('payee_type', 'owner')
                ->orWhereIn('payee_id', Customer::query()->visibleTo($this->actor())->select('id')))
```

    with

```php
            ->where(fn ($q) => $q->whereIn('payee_type', ['owner', 'vendor'])
                ->orWhere(fn ($c) => $c->where('payee_type', 'customer')->whereIn('payee_id', Customer::query()->visibleTo($this->actor())->select('id'))))
```

  - `app/Reports/Builder/Subjects/PaymentsOut.php`:
    - in `query()`, replace `return Disbursement::query()->where(fn ($q) => $q->where('disbursements.payee_type', 'owner')` and the next line `->orWhereIn('disbursements.payee_id', Customer::query()->visibleTo($user)->select('id')))` with:

```php
        return Disbursement::query()->where(fn ($q) => $q->whereIn('disbursements.payee_type', ['owner', 'vendor'])
            ->orWhere(fn ($c) => $c->where('disbursements.payee_type', 'customer')->whereIn('disbursements.payee_id', Customer::query()->visibleTo($user)->select('id'))))
```

    - replace `['owner' => __('Owner'), 'customer' => __('Tenant')]` with `['owner' => __('Owner'), 'customer' => __('Tenant'), 'vendor' => __('Vendor')]`;
    - replace `ELSE (SELECT pc.name_en FROM customers pc WHERE pc.id = disbursements.payee_id) END)"),` with:

```php
                WHEN 'vendor' THEN (SELECT pv.name FROM vendors pv WHERE pv.id = disbursements.payee_id)
                ELSE (SELECT pc.name_en FROM customers pc WHERE pc.id = disbursements.payee_id) END)"),
```

- [ ] **Step 9: The posting.** In `app/Ledger/Sources.php`, in `disbursement()`'s `$lines = match ($d->purpose) {`, after `'credit_refund' => [self::line($rules->account('tenant_credit'), $amount, $customer)],` add:

```php
            'vendor_bill' => [self::line($rules->account('vendor_payable'), $amount)], // ledger money screens plan ruling 4
```

  and in the docblock above `disbursement()` replace `Other payments out) against the payout method's account.` with `Other payments out; Vendor payable for a vendor bill) against the payout method's account.`

- [ ] **Step 10: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Vendor bill for :vendor: :list": "فاتورة مورد لـ :vendor: :list",
    "Choose the vendor.": "اختر المورد.",
    "Tick at least one expense.": "حدد مصروفاً واحداً على الأقل.",
    "Choose expenses from the list.": "اختر المصروفات من القائمة.",
    "Expense :description is reversed.": "المصروف :description معكوس.",
    "Expense :description is already on payment out :n.": "المصروف :description مدرج بالفعل في الدفعة الصادرة :n.",
    "This expense is on payment out :n. Reject or reverse that payment out first.": "هذا المصروف مدرج في الدفعة الصادرة :n. ارفض تلك الدفعة أو اعكسها أولاً.",
    "The payment cannot be dated before its latest expense (:d).": "لا يمكن أن يسبق تاريخ الدفعة تاريخ آخر مصروف فيها (:d).",
    "Vendor": "المورد",
    "vendor": "مورد",
```

- [ ] **Step 11: Run the tests.**

Run: `php artisan test tests/Feature/Disbursements tests/Feature/Expenses tests/Feature/Ledger/MoneyOutPostingTest.php tests/Feature/ReportBuilder tests/Feature/Cheques tests/Feature/Approvals`
Expected: PASS. The existing payment-out, expense, cheque and report-builder suites are unchanged by the vendor arms.

- [ ] **Step 12: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Enums/PayeeType.php app/Enums/DisbursementPurpose.php app/Models/Disbursement.php app/Models/Expense.php app/Models/Cheque.php app/Actions/Disbursements/RecordDisbursement.php app/Actions/Disbursements/PayDisbursement.php app/Actions/Disbursements/MarkDisbursementPaid.php app/Actions/Expenses/ReverseExpense.php app/Approvals/PaymentOut.php app/Approvals/PaymentOutReversal.php app/Livewire/Disbursements/Index.php resources/views/livewire/disbursements/index.blade.php resources/views/livewire/disbursements/show.blade.php app/Reports/Builder/Subjects/PaymentsOut.php app/Ledger/Sources.php tests/Pest.php tests/Feature/Disbursements/VendorBillTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
Vendor bills: payments out to a vendor for recorded expenses, one active payment per expense, Management approval, vendor cheques, paid status, Vendor payable posting

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Vendor bill screens: the Payments out form, the payment page, and Paid on the expense list and page

**Files:**
- Modify: `app/Livewire/Disbursements/Create.php`, `resources/views/livewire/disbursements/create.blade.php`, `app/Livewire/Disbursements/Show.php`, `resources/views/livewire/disbursements/show.blade.php`, `app/Livewire/Expenses/Index.php`, `resources/views/livewire/expenses/index.blade.php`, `app/Livewire/Expenses/Show.php`, `resources/views/livewire/expenses/show.blade.php`, `lang/ar.json`
- Test: `tests/Feature/Disbursements/VendorBillScreensTest.php`

**Interfaces:**
- Consumes: Task 2's `RecordDisbursement` vendor-bill input, `Expense::unpaid()`, `Expense::activePayment()`, `Expense::disbursements()`, `Disbursement::ACTIVE`, `Disbursement::expenses()`, `Disbursement::payeeName()`, `vendorBillFixture()`.
- Produces:
  - `Disbursements\Create`: `form.payee_type` accepts `vendor`; `form.expense_ids` (ticked ids as strings); `#[Locked] $added` (`list<int>`); `$extra`; `addExpense()`. View data: `vendorMode` (bool), `candidates` (Eloquent `Collection<int, Expense>`: the vendor's work-order expenses, then those added), `others` (`Collection<int, Expense>`), `total` (string BHD). Error keys `form.expense_ids`, `extra`.
  - The Expenses list's "Payment" column and the expense page's "Payment" row read "Paid · PO-…", "In payment · …", "Unpaid" or "—".

- [ ] **Step 1: Write the failing test** `tests/Feature/Disbursements/VendorBillScreensTest.php`:

```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\PayDisbursement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Livewire\Disbursements\Create;
use App\Livewire\Disbursements\Show;
use App\Livewire\Expenses\Index as ExpenseIndex;
use App\Livewire\Expenses\Show as ExpenseShow;
use App\Models\Approval;
use App\Models\Disbursement;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = vendorBillFixture();
});

test('choosing a vendor lists its work-order expenses first; others are added by hand; the total is the sum of the ticked ones', function () {
    $f = $this->f;
    $company = $f['companyExpense']->id;
    $owner = $f['ownerExpense']->id;

    Livewire::actingAs($f['finance'])->test(Create::class)
        ->set('form.payee_type', 'vendor')->set('form.payee_id', (string) $f['vendor']->id)
        ->assertViewHas('candidates', fn ($c) => $c->modelKeys() === [$company])
        ->assertViewHas('others', fn ($c) => $c->modelKeys() === [$owner])
        ->set('extra', (string) $owner)->call('addExpense')->assertHasNoErrors()
        ->assertViewHas('candidates', fn ($c) => $c->modelKeys() === [$company, $owner])
        ->assertViewHas('others', fn ($c) => $c->modelKeys() === [])
        ->assertSet('form.expense_ids', [(string) $owner])
        ->set('form.expense_ids', [(string) $company, (string) $owner])
        ->assertSee('Total: 80.000 BHD')
        ->call('save')->assertHasNoErrors()->assertRedirect();

    $out = Disbursement::query()->where('purpose', 'vendor_bill')->sole();
    expect([$out->amount, $out->status->value, $out->payeeName()])->toBe(['80.000', 'pending_approval', 'Fix-It Co']);
});

test('the payment lists its expenses; each expense shows In payment, then Paid with its number', function () {
    $f = $this->f;
    $out = app(RecordDisbursement::class)->handle($f['finance'], ['purpose' => 'vendor_bill', 'payee_type' => 'vendor', 'payee_id' => $f['vendor']->id,
        'expense_ids' => [$f['companyExpense']->id], 'method' => 'bank_transfer']);
    Livewire::actingAs($f['finance'])->test(ExpenseShow::class, ['expense' => $f['companyExpense']])->assertSee('In payment');

    app(DecideApproval::class)->handle($f['management'], Approval::query()->latest('id')->firstOrFail(), true);
    $paid = app(PayDisbursement::class)->handle($f['finance'], $out->fresh(), ['method' => 'bank_transfer', 'paid_on' => '2026-10-20']);

    Livewire::actingAs($f['finance'])->test(Show::class, ['disbursement' => $paid])
        ->assertSee('Fix-It Co (vendor)')->assertSeeInOrder(['08/10/2026', 'Lobby lights', '50.000']);
    Livewire::actingAs($f['finance'])->test(ExpenseShow::class, ['expense' => $f['companyExpense']])->assertSee('Paid · '.$paid->number);
    Livewire::actingAs($f['finance'])->test(ExpenseIndex::class)->assertSeeInOrder(['T1 tap', 'Unpaid', 'Lobby lights', 'Paid · '.$paid->number]);
});

test('the owner and tenant forms work as before, and switching to a vendor clears the payee', function () {
    $f = $this->f;
    Livewire::actingAs($f['finance'])->test(Create::class)
        ->set('form.payee_type', 'owner')->set('form.payee_id', (string) $f['ali']->id)
        ->set('form.payee_type', 'vendor')->assertSet('form.payee_id', null)
        ->set('form.payee_type', 'owner')->set('form.payee_id', (string) $f['ali']->id)
        ->set('form.amount', '5')->set('form.method', 'cash')->set('form.reason', 'Keys')
        ->call('save')->assertHasNoErrors()->assertRedirect();

    expect(Disbursement::query()->where('purpose', 'other')->sole()->payeeName())->toBe('Ali Owner');
});

test('tampered input on the vendor form gives a message, never a 500', function () {
    $f = $this->f;
    Livewire::actingAs($f['finance'])->test(Create::class)
        ->set('form.payee_type', 'vendor')->set('form.payee_id', (string) $f['vendor']->id)
        ->set('extra', ['x'])->call('addExpense')->assertHasErrors('extra')
        ->set('extra', '999999')->call('addExpense')->assertHasErrors('extra')
        ->set('form.expense_ids', 'oops')->call('save')->assertHasErrors('form.expense_ids')
        ->set('form.expense_ids', [['x']])->call('save')->assertHasErrors()
        ->set('form.expense_ids', [])->call('save')->assertHasErrors('form.expense_ids')
        ->set('form.payee_id', ['x'])->call('save')->assertHasErrors('form.payee_id');

    expect(Disbursement::query()->where('purpose', 'vendor_bill')->count())->toBe(0);
});
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php artisan test tests/Feature/Disbursements/VendorBillScreensTest.php`
Expected: FAIL. The view has no `candidates`, and `addExpense` does not exist.

- [ ] **Step 3: The form.** Replace `app/Livewire/Disbursements/Create.php` with:

```php
<?php

namespace App\Livewire\Disbursements;

use App\Actions\Disbursements\RecordDisbursement;
use App\Enums\DisbursementMethod;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use App\Models\Disbursement;
use App\Models\Expense;
use App\Models\Owner;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** A payment out with no approved source (purpose other), or a vendor bill (ledger money screens spec §2, plan ruling 11). */
#[Title('New payment out')]
class Create extends Component
{
    use WithActor;

    /** @var array<string, mixed> */
    public array $form = ['payee_type' => 'owner', 'method' => 'bank_transfer', 'expense_ids' => []];

    /** @var list<int> unpaid expenses added by hand to a vendor bill, beyond the vendor's own work orders */
    #[Locked]
    public array $added = [];

    public mixed $extra = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can('create', Disbursement::class), 403);
    }

    public function updatedFormPayeeType(): void
    {
        unset($this->form['payee_id']);
        $this->clearExpenses();
    }

    public function updatedFormPayeeId(): void
    {
        $this->clearExpenses();
    }

    public function addExpense(): void
    {
        $id = is_string($this->extra) && ctype_digit($this->extra) && strlen($this->extra) < 19 ? (int) $this->extra : 0;
        if (! Expense::query()->unpaid()->visibleTo($this->actor())->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['extra' => __('Choose an unpaid expense.')]);
        }
        $this->added = array_values(array_unique([...$this->added, $id]));
        $this->form['expense_ids'] = array_values(array_unique([...self::ticked($this->form['expense_ids'] ?? []), (string) $id]));
        $this->extra = '';
    }

    public function save(RecordDisbursement $record): void
    {
        $data = ($this->form['payee_type'] ?? null) === 'vendor'
            ? ['purpose' => 'vendor_bill', 'payee_type' => 'vendor', 'payee_id' => $this->form['payee_id'] ?? null,
                'expense_ids' => $this->form['expense_ids'] ?? [], 'method' => $this->form['method'] ?? null]
            : [...Arr::except($this->form, ['expense_ids']), 'purpose' => 'other'];
        try {
            $out = $record->handle($this->actor(), $data);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
        }

        $this->redirectRoute('disbursements.show', $out, navigate: true);
    }

    private function clearExpenses(): void
    {
        $this->form['expense_ids'] = [];
        $this->added = [];
    }

    /** @return list<string> the ticked expense ids, whatever the browser sent */
    private static function ticked(mixed $ids): array
    {
        return array_values(array_map('strval', array_filter(is_array($ids) ? $ids : [], fn (mixed $id) => is_int($id) || is_string($id))));
    }

    /** @return Collection<int, Expense> spec §2: the vendor's unpaid work-order expenses first, then those added by hand */
    private function candidates(): Collection
    {
        $vendor = $this->form['payee_id'] ?? null;
        $vendor = is_int($vendor) || (is_string($vendor) && ctype_digit($vendor) && strlen($vendor) < 19) ? (int) $vendor : 0;
        $unpaid = fn () => Expense::query()->unpaid()->visibleTo($this->actor())->with('building:id,code')->orderBy('expense_date')->orderBy('id');

        return $unpaid()->whereIn('id', WorkOrder::query()->where('vendor_id', $vendor)->whereNotNull('expense_id')->select('expense_id'))->get()
            ->merge($unpaid()->whereKey($this->added)->get());
    }

    public function render(): View
    {
        $vendorMode = ($this->form['payee_type'] ?? null) === 'vendor';
        $candidates = $vendorMode ? $this->candidates() : new Collection;
        $ticked = self::ticked($this->form['expense_ids'] ?? []);

        return view('livewire.disbursements.create', [
            'payees' => match ($this->form['payee_type'] ?? 'owner') {
                'customer' => Customer::query()->visibleTo($this->actor())->orderBy('name_en')->get(['id', 'name_en']),
                'vendor' => Vendor::query()->orderBy('name')->get(['id', 'name as name_en']),
                default => Owner::query()->orderBy('name_en')->get(['id', 'name_en']),
            },
            'methods' => DisbursementMethod::cases(),
            'vendorMode' => $vendorMode,
            'candidates' => $candidates,
            // ponytail: the newest 200 other unpaid expenses; a search box when that stops being enough
            'others' => $vendorMode ? Expense::query()->unpaid()->visibleTo($this->actor())->with('building:id,code')->whereNotIn('id', $candidates->modelKeys())
                ->latest('expense_date')->latest('id')->limit(200)->get() : new Collection,
            'total' => Fils::toDecimal((int) $candidates->filter(fn (Expense $e) => in_array((string) $e->id, $ticked, true))->sum(fn (Expense $e) => Fils::fromDecimal($e->total))),
        ]);
    }
}
```

- [ ] **Step 4: The form's view.** Replace `resources/views/livewire/disbursements/create.blade.php` with:

```blade
<section class="w-full max-w-xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('New payment out') }}</flux:heading>
    <flux:text>{{ __('A payment out with no invoice, payment or settlement behind it needs Management approval before it is paid.') }}</flux:text>

    <form wire:submit="save" class="space-y-4">
        <flux:radio.group wire:model.live="form.payee_type" :label="__('Pay to')" variant="segmented">
            <flux:radio value="owner" :label="__('Owner')" />
            <flux:radio value="customer" :label="__('Tenant')" />
            <flux:radio value="vendor" :label="__('Vendor')" />
        </flux:radio.group>
        <flux:select wire:model.live="form.payee_id" :label="__('Payee')">
            <option value="">{{ __('Choose…') }}</option>
            @foreach ($payees as $p)<option value="{{ $p->id }}">{{ $p->name_en }}</option>@endforeach
        </flux:select>

        @if ($vendorMode)
            <flux:text>{{ __('Unpaid expenses: this vendor\'s work orders first; add any other unpaid expense below.') }}</flux:text>
            @if ($candidates->isEmpty())
                <flux:text>{{ __('No unpaid expenses.') }}</flux:text>
            @else
                <flux:checkbox.group wire:model.live="form.expense_ids">
                    @foreach ($candidates as $e)
                        <flux:checkbox wire:key="ex-{{ $e->id }}" :value="(string) $e->id"
                            :label="$e->expense_date->format('d/m/Y').' · '.$e->building->code.' · '.$e->description.' · '.$e->total" />
                    @endforeach
                </flux:checkbox.group>
            @endif
            <div class="flex items-end gap-2">
                <flux:select wire:model="extra" :label="__('Add another unpaid expense')">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($others as $e)<option value="{{ $e->id }}">{{ $e->expense_date->format('d/m/Y') }} · {{ $e->building->code }} · {{ $e->description }} · {{ $e->total }}</option>@endforeach
                </flux:select>
                <flux:button type="button" wire:click="addExpense">{{ __('Add') }}</flux:button>
            </div>
            <flux:error name="extra" />
            <flux:text class="tabular-nums">{{ __('Total: :t BHD', ['t' => $total]) }}</flux:text>
            <flux:select wire:model="form.method" :label="__('Method')">
                @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
            </flux:select>
        @else
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.amount" inputmode="decimal" :label="__('Amount (BHD)')" />
                <flux:select wire:model="form.method" :label="__('Method')">
                    @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
                </flux:select>
            </div>
            <flux:textarea wire:model="form.reason" :label="__('Reason')" rows="3" />
        @endif
        @foreach (['payee_type', 'payee_id', 'amount', 'method', 'reason', 'expense_ids'] as $f)<flux:error name="form.{{ $f }}" />@endforeach
        <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Send for approval') }}</flux:button>
    </form>
</section>
```

- [ ] **Step 5: The payment page lists a vendor bill's expenses.**
  - In `app/Livewire/Disbursements/Show.php`, replace `return Disbursement::with(['creator:id,name', 'recorder:id,name', 'cheque'])->findOrFail($this->disbursementId);` with:

```php
        return Disbursement::with(['creator:id,name', 'recorder:id,name', 'cheque', 'expenses'])->findOrFail($this->disbursementId);
```

  - In `resources/views/livewire/disbursements/show.blade.php`, immediately before `@if ($out->cheque)` add:

```blade
    @if ($out->purpose->value === 'vendor_bill')
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Expense') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Total (BHD)') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($out->expenses->sortBy('id') as $expense)
                        <flux:table.row :key="'e-'.$expense->id">
                            <flux:table.cell class="whitespace-nowrap">{{ $expense->expense_date->format('d/m/Y') }}</flux:table.cell>
                            <flux:table.cell><flux:link :href="route('expenses.show', $expense)" wire:navigate>{{ $expense->description }}</flux:link></flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $expense->total }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

```

- [ ] **Step 6: Paid on the expense list and page.**
  - In `app/Livewire/Expenses/Index.php`:
    - add `use App\Models\Disbursement;` after `use App\Models\Building;`;
    - replace `->with(['building:id,code', 'unit:id,code', 'ownerContract:id,number'])` with:

```php
            ->with(['building:id,code', 'unit:id,code', 'ownerContract:id,number',
                'disbursements' => fn ($q) => $q->whereIn('disbursements.status', Disbursement::ACTIVE)]) // ledger money screens plan ruling 3
```

  - In `resources/views/livewire/expenses/index.blade.php`:
    - after `<flux:table.column>{{ __('Status') }}</flux:table.column>` add `<flux:table.column>{{ __('Payment') }}</flux:table.column>`;
    - after the status cell (the line containing `<flux:badge size="sm" :color="$expense->status->value === 'reversed' ? 'zinc' : 'green'">`) add:

```blade
                        <flux:table.cell>
                            @if ($expense->status->value === 'reversed')
                                —
                            @elseif ($pay = $expense->disbursements->first())
                                <flux:link :href="route('disbursements.show', $pay)" wire:navigate>{{ $pay->status->value === 'paid' ? __('Paid') : __('In payment') }} · {{ $pay->label() }}</flux:link>
                            @else
                                {{ __('Unpaid') }}
                            @endif
                        </flux:table.cell>
```

  - In `app/Livewire/Expenses/Show.php`, replace `'canReverse' => $expense->status === ExpenseStatus::Recorded && $this->actor()->can('reverse', $expense),` with:

```php
            'canReverse' => $expense->status === ExpenseStatus::Recorded && $this->actor()->can('reverse', $expense),
            'payment' => $expense->activePayment(), // ledger money screens plan ruling 3
```

  - In `resources/views/livewire/expenses/show.blade.php`, after the line `<div><dt class="text-sm text-zinc-500">{{ __('Recorded by') }}</dt><dd>{{ $expense->recorder->name }}</dd></div>` add:

```blade
        <div><dt class="text-sm text-zinc-500">{{ __('Payment') }}</dt><dd>
            @if ($payment)
                <flux:link :href="route('disbursements.show', $payment)" wire:navigate>{{ $payment->status->value === 'paid' ? __('Paid') : __('In payment') }} · {{ $payment->label() }}</flux:link>
            @elseif ($expense->status->value === 'recorded')
                {{ __('Unpaid') }}
            @else
                —
            @endif
        </dd></div>
```

- [ ] **Step 7: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Unpaid expenses: this vendor's work orders first; add any other unpaid expense below.": "المصروفات غير المدفوعة: مصروفات أوامر عمل هذا المورد أولاً، ويمكن إضافة أي مصروف غير مدفوع آخر أدناه.",
    "No unpaid expenses.": "لا توجد مصروفات غير مدفوعة.",
    "Add another unpaid expense": "إضافة مصروف غير مدفوع آخر",
    "Add": "إضافة",
    "Choose an unpaid expense.": "اختر مصروفاً غير مدفوع.",
    "Total: :t BHD": "الإجمالي: :t د.ب",
    "In payment": "قيد الدفع",
    "Total (BHD)": "الإجمالي (د.ب)",
```

- [ ] **Step 8: Run the tests.**

Run: `php artisan test tests/Feature/Disbursements tests/Feature/Expenses tests/Feature/Maintenance`
Expected: PASS. `DisbursementScreensTest`'s "switching the payee type clears the chosen payee" still passes: switching clears `payee_id`, and the save then fails on `form.payee_id`.

- [ ] **Step 9: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Livewire/Disbursements/Create.php resources/views/livewire/disbursements/create.blade.php app/Livewire/Disbursements/Show.php resources/views/livewire/disbursements/show.blade.php app/Livewire/Expenses/Index.php resources/views/livewire/expenses/index.blade.php app/Livewire/Expenses/Show.php resources/views/livewire/expenses/show.blade.php tests/Feature/Disbursements/VendorBillScreensTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
Vendor bill screens: pay a vendor from Payments out (work-order expenses first, others added by hand, running total), the bill's expenses on its page, Paid / In payment / Unpaid on expenses

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Ledger health: the Vendor payable row measures unpaid bills

**Files:**
- Modify: `app/Ledger/LedgerHealth.php`, `docs/superpowers/plans/2026-10-24-general-ledger.md`
- Test: `tests/Feature/Ledger/VendorPayableHealthTest.php`

**Interfaces:**
- Consumes: Task 2 (vendor bills post as `disbursement` / `disbursement_reversal`; `disbursement_expenses`); `LedgerHealth::store()`; `PostManualJournal::handle(User, mixed)`; `vendorBillFixture()`.
- Produces: the health row `Vendor payable` = `[ledger, app]` in fils per plan ruling 5. The row's name and every other row are unchanged, so `LedgerHealthTest` keeps `'Vendor payable' => [80_000, 80_000]`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Ledger/VendorPayableHealthTest.php`:

```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\PayDisbursement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Ledger\PostManualJournal;
use App\Ledger\LedgerHealth;
use App\Models\Approval;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = vendorBillFixture();
    $this->row = function (): array {
        LedgerHealth::store();
        $row = collect(LedgerSetting::current()->health)->firstWhere('check', 'Vendor payable');

        return [$row['ledger'], $row['app']];
    };
    $this->record = fn (array $ids) => app(RecordDisbursement::class)->handle($this->f['finance'], ['purpose' => 'vendor_bill', 'payee_type' => 'vendor',
        'payee_id' => $this->f['vendor']->id, 'expense_ids' => $ids, 'method' => 'bank_transfer']);
    $this->decide = fn (bool $approve) => app(DecideApproval::class)->handle($this->f['management'], Approval::query()->latest('id')->firstOrFail(), $approve,
        $approve ? null : 'Not yet');
    $this->pay = function (array $ids) {
        $out = ($this->record)($ids);
        ($this->decide)(true);

        return app(PayDisbursement::class)->handle($this->f['finance'], $out->fresh(), ['method' => 'bank_transfer', 'paid_on' => '2026-10-20']);
    };
    $this->reverse = fn ($out) => app(DecideApproval::class)->handle($this->f['management'],
        app(RequestDisbursementReversal::class)->handle($this->f['finance'], $out, 'Bank returned it'), true);
});

afterEach(function () {
    LedgerSetting::query()->update(['start_date' => null, 'locked_until' => null, 'health' => null, 'health_checked_at' => null, 'rebuild_status' => null, 'rebuild_from' => null,
        'rebuild_to' => null, 'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null, 'rebuild_run' => null]);
});

test('Vendor payable is the unpaid bills: all of them, none once paid, all again once the payment is reversed', function () {
    $f = $this->f;
    expect(($this->row)())->toBe([80_000, 80_000]);

    $out = ($this->pay)([$f['companyExpense']->id, $f['ownerExpense']->id]);
    expect(($this->row)())->toBe([0, 0])
        ->and(ledgerBalances())->not->toHaveKey(2200);

    ($this->reverse)($out);
    expect(($this->row)())->toBe([80_000, 80_000]);
});

test('a part payment leaves the other bill; a waiting or rejected bill changes nothing; a manual vendor journal is left out', function () {
    $f = $this->f;
    ($this->pay)([$f['companyExpense']->id]);
    expect(($this->row)())->toBe([30_000, 30_000]);

    ($this->record)([$f['ownerExpense']->id]);
    expect(($this->row)())->toBe([30_000, 30_000]);
    ($this->decide)(false);
    expect(($this->row)())->toBe([30_000, 30_000]);

    $id = fn (string $code) => (string) LedgerAccount::query()->where('code', $code)->value('id');
    app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-20', 'description' => 'T1 tap paid before vendor bills existed', 'lines' => [
        ['account_id' => $id('2200'), 'debit' => '30.000', 'credit' => ''], ['account_id' => $id('1010'), 'debit' => '', 'credit' => '30.000']]]);

    expect(($this->row)())->toBe([30_000, 30_000])
        ->and(ledgerBalances())->not->toHaveKey(2200);
});

test('a bill from before the start date counts only for what happens to it from the start date', function () {
    $f = $this->f;
    $old = app(RecordExpense::class)->handle($f['finance'], ['building_id' => $f['tower']->id, 'category' => 'utilities', 'description' => 'September power',
        'expense_date' => '2026-09-20', 'net' => '40.000', 'charge_to' => 'company']);
    expect(journalFor('expense', $old->id))->toBeNull()
        ->and(($this->row)())->toBe([80_000, 80_000]);

    $out = ($this->pay)([$old->id]);
    expect(postedLines('disbursement', $out->id))->toBe(['2200 40.000 0.000', '1010 0.000 40.000'])
        ->and(($this->row)())->toBe([40_000, 40_000]);

    ($this->reverse)($out);
    expect(($this->row)())->toBe([80_000, 80_000]);
});

test('a missing vendor-bill journal shows as a difference', function () {
    $f = $this->f;
    $journal = journalFor('disbursement', ($this->pay)([$f['companyExpense']->id])->id);
    DB::table('journal_lines')->where('journal_id', $journal->id)->delete();
    DB::table('journals')->where('id', $journal->id)->delete();

    expect(($this->row)())->toBe([80_000, 30_000]);
});
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php artisan test tests/Feature/Ledger/VendorPayableHealthTest.php`
Expected: FAIL. With both bills paid the row is `[80000, 80000]`: the ledger side still reads expense journals only, and the app side counts every expense.

- [ ] **Step 3: The row.** In `app/Ledger/LedgerHealth.php`, replace the two methods `vendorLedger()` and `vendorApp()` (from `/** What expenses credited to Vendor payable (manual vendor payments left out, plan ruling 11). */` to the end of `vendorApp()`) with:

```php
    /**
     * Ledger money screens plan ruling 5: Vendor payable from what expenses and vendor bills posted. Opening and manual
     * journals are left out: no expense stands behind them.
     */
    private static function vendorLedger(Rules $rules): int
    {
        return -self::fils(DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.ledger_account_id', $rules->account('vendor_payable'))
            ->whereIn('j.source_type', ['expense', 'expense_reversal', 'disbursement', 'disbursement_reversal'])
            ->sum(DB::raw('l.debit - l.credit')));
    }

    /**
     * Ledger money screens plan ruling 5: the unpaid bills. Expenses dated from the start date that are recorded and on no
     * paid vendor bill; then, for bills from before the start date (in the opening balances), only what happened to them
     * from the start date: reversed (−), paid on a bill (−), that bill reversed (+).
     */
    private static function vendorApp(string $start, string $today): int
    {
        $unpaid = self::fils(DB::table('expenses as e')->where('e.status', 'recorded')->whereBetween('e.expense_date', [$start, $today])
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('disbursement_expenses as de')->join('disbursements as d', 'd.id', '=', 'de.disbursement_id')
                ->whereColumn('de.expense_id', 'e.id')->where('d.status', 'paid'))
            ->sum('e.total'));
        $earlier = fn () => DB::table('expenses as e')->where('e.expense_date', '<', $start);
        $onBills = fn () => $earlier()->join('disbursement_expenses as de', 'de.expense_id', '=', 'e.id')->join('disbursements as d', 'd.id', '=', 'de.disbursement_id');
        $reversed = self::fils($earlier()->where('e.status', 'reversed')->whereBetween(DB::raw('DATE(e.reversed_at)'), [$start, $today])->sum('e.total'));
        $paid = self::fils($onBills()->whereIn('d.status', ['paid', 'reversed'])->whereBetween('d.paid_on', [$start, $today])->sum('e.total'));
        $paidBack = self::fils($onBills()->where('d.status', 'reversed')->whereBetween(DB::raw('DATE(d.reversed_at)'), [$start, $today])->sum('e.total'));

        return $unpaid - $reversed - $paid + $paidBack;
    }
```

  `Illuminate\Database\Query\Builder` is already imported in `LedgerHealth.php` (used by `depositLines()`).

- [ ] **Step 4: Supersede ruling 11 of the general ledger plan.** In `docs/superpowers/plans/2026-10-24-general-ledger.md`, replace the whole line that starts `11. **Vendor payments have no source.**` with:

```markdown
11. **Vendor payments** *(superseded on 28/10/2026 by `2026-10-28-ledger-money-screens.md`, rulings 4–5)*. Vendor bills are paid through Payments out (purpose `vendor_bill`) and post as `disbursement` / `disbursement_reversal`: Dr Vendor payable, Cr the method's account. The vendor health row now compares Vendor payable on expense and payment-out journals with the unpaid bills. Manual vendor journals are no longer the way to pay a vendor; one already posted stays and is left out of the row.
```

  In the same file's Ledger health ruling (8), replace `Ruling 11 explains why only those.` with `Superseded by the ledger money screens plan, ruling 5.`

- [ ] **Step 5: Run the tests.**

Run: `php artisan test tests/Feature/Ledger`
Expected: PASS. `LedgerHealthTest` still sees `'Vendor payable' => [80_000, 80_000]` and a difference when the expense journal is removed.

- [ ] **Step 6: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Ledger/LedgerHealth.php docs/superpowers/plans/2026-10-24-general-ledger.md tests/Feature/Ledger/VendorPayableHealthTest.php
git commit -m "$(cat <<'EOF'
Ledger health: the Vendor payable row compares expense and vendor-bill journals with the unpaid bills; general ledger ruling 11 superseded

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Cash and bank movements: record, post, reverse, list

**Files:**
- Create: `app/Enums/CashMovementKind.php`, `app/Models/CashMovement.php`, `app/Actions/Ledger/RecordCashMovement.php`, `app/Actions/Ledger/ReverseMoneyEntry.php`, `app/Livewire/Ledger/CashMovements.php`, `resources/views/livewire/ledger/cash-movements.blade.php`
- Modify: `app/Ledger/Sources.php`, `app/Ledger/Poster.php`, `routes/ledger.php`, `resources/views/layouts/app/sidebar.blade.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/CashMovementTest.php`

**Interfaces:**
- Consumes: Task 1 (`cash_movements`, `is_money`, rules `bank_charges` / `bank_interest`); `PostManualJournal::openDate(LedgerSetting, string, string)` and `::activeAccounts(array, string)`; `Poster::WATCH`; `LockLedger::handle(User, mixed)`; `SaveLedgerAccount`.
- Produces:
  - `CashMovementKind` (`transfer | bank_charge | bank_interest`) with `label(): string`.
  - `CashMovement` (`kind`, `from_account_id`, `to_account_id`, `amount`, `date`, `reference`, `note`, `reverses_id`, `created_by`; `fromAccount()`, `toAccount()`: `BelongsTo<LedgerAccount>`; `reversal()`: `HasOne<CashMovement>`).
  - `RecordCashMovement::handle(User, mixed $data): CashMovement`, where `$data` is `kind, from_account_id, to_account_id, amount, date, reference, note`. Error keys are the same names.
  - `ReverseMoneyEntry::handle(User, CashMovement $entry, mixed $date, mixed $reason): CashMovement`. Error keys `reverseDate`, `reverseReason`. Task 6 widens it to `CashMovement|VatReturn`.
  - Source types `cash_movement` and `cash_movement_reversal`; `Sources::REVERSAL_ROWS`; `Sources::assertPostable(string $type, int $id, string $field): void`.
  - Route `ledger.cash-movements`; page `App\Livewire\Ledger\CashMovements` (properties `kind`, `from_account_id`, `to_account_id`, `amount`, `date`, `reference`, `note`, `#[Locked] reversingId`, `reverseDate`, `reverseReason`, `filterFrom`, `filterTo`, `filterKind`, `filterAccount`; methods `save`, `startReverse(mixed)`, `cancelReverse`, `reverse`).

- [ ] **Step 1: Write the failing test** `tests/Feature/Ledger/CashMovementTest.php`:

```php
<?php

use App\Actions\Ledger\LockLedger;
use App\Actions\Ledger\RecordCashMovement;
use App\Actions\Ledger\ReverseMoneyEntry;
use App\Actions\Ledger\SaveLedgerAccount;
use App\Livewire\Ledger\CashMovements;
use App\Models\CashMovement;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
    $this->id = fn (string $code) => (string) LedgerAccount::query()->where('code', $code)->value('id');
    $this->move = fn (array $data) => app(RecordCashMovement::class)->handle($this->f['finance'], ['date' => '2026-10-20', 'reference' => 'REF-1', ...$data]);
});

afterEach(function () {
    LedgerSetting::query()->update(['start_date' => null, 'locked_until' => null, 'health' => null, 'health_checked_at' => null, 'rebuild_status' => null, 'rebuild_from' => null,
        'rebuild_to' => null, 'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null, 'rebuild_run' => null]);
});

test('each kind posts exactly, on its own date, described by its reference', function () {
    $transfer = ($this->move)(['kind' => 'transfer', 'from_account_id' => ($this->id)('1000'), 'to_account_id' => ($this->id)('1010'), 'amount' => '500', 'reference' => 'DEP-1']);
    $charge = ($this->move)(['kind' => 'bank_charge', 'from_account_id' => ($this->id)('1010'), 'amount' => '2.5', 'date' => '2026-10-19']);
    $interest = ($this->move)(['kind' => 'bank_interest', 'to_account_id' => ($this->id)('1010'), 'amount' => '1.200', 'note' => 'October']);

    expect(postedLines('cash_movement', $transfer->id))->toBe(['1010 500.000 0.000', '1000 0.000 500.000'])
        ->and(postedLines('cash_movement', $charge->id))->toBe(['5180 2.500 0.000', '1010 0.000 2.500'])
        ->and(postedLines('cash_movement', $interest->id))->toBe(['1010 1.200 0.000', '4300 0.000 1.200'])
        ->and(journalFor('cash_movement', $charge->id)->date->toDateString())->toBe('2026-10-19')
        ->and(journalFor('cash_movement', $transfer->id)->description)->toBe('DEP-1')
        ->and([$charge->amount, $charge->to_account_id, $interest->from_account_id, $interest->note])->toBe(['2.500', null, null, 'October']);
});

test('a transfer needs two different active money accounts; a new money account works once Admin flags it', function () {
    $f = $this->f;

    expect(fn () => ($this->move)(['kind' => 'transfer', 'from_account_id' => ($this->id)('1010'), 'to_account_id' => ($this->id)('1010'), 'amount' => '5']))
        ->toThrow(ValidationException::class, 'two different money accounts')
        ->and(fn () => ($this->move)(['kind' => 'transfer', 'from_account_id' => ($this->id)('1100'), 'to_account_id' => ($this->id)('1010'), 'amount' => '5']))
        ->toThrow(ValidationException::class, 'active money account')
        ->and(fn () => ($this->move)(['kind' => 'bank_charge', 'from_account_id' => ($this->id)('5100'), 'amount' => '5']))
        ->toThrow(ValidationException::class, 'active money account')
        ->and(fn () => ($this->move)(['kind' => 'transfer', 'from_account_id' => ($this->id)('1000'), 'to_account_id' => ($this->id)('1010'), 'amount' => '0']))
        ->toThrow(ValidationException::class);

    $petty = app(SaveLedgerAccount::class)->handle($f['admin'], null, ['code' => '1030', 'name_en' => 'Petty cash', 'name_ar' => 'النثرية', 'type' => 'asset', 'is_money' => true]);
    $move = ($this->move)(['kind' => 'transfer', 'from_account_id' => ($this->id)('1000'), 'to_account_id' => (string) $petty->id, 'amount' => '20']);

    expect(postedLines('cash_movement', $move->id))->toBe(['1030 20.000 0.000', '1000 0.000 20.000']);
});

test('a movement is reversed once, by its exact opposite, never before its own date', function () {
    $f = $this->f;
    $transfer = ($this->move)(['kind' => 'transfer', 'from_account_id' => ($this->id)('1000'), 'to_account_id' => ($this->id)('1010'), 'amount' => '500', 'date' => '2026-10-19']);

    expect(fn () => app(ReverseMoneyEntry::class)->handle($f['finance'], $transfer, '2026-10-18', 'Typo'))->toThrow(ValidationException::class, 'before the original (19/10/2026)')
        ->and(fn () => app(ReverseMoneyEntry::class)->handle($f['finance'], $transfer, '2026-10-20', ''))->toThrow(ValidationException::class);

    $reversal = app(ReverseMoneyEntry::class)->handle($f['finance'], $transfer, '2026-10-20', 'Typo');

    expect([$reversal->reverses_id, $reversal->kind->value, $reversal->amount, $reversal->reference, $reversal->note])->toBe([$transfer->id, 'transfer', '500.000', 'REF-1', 'Typo'])
        ->and(postedLines('cash_movement_reversal', $reversal->id))->toBe(['1010 0.000 500.000', '1000 500.000 0.000'])
        ->and(journalFor('cash_movement_reversal', $reversal->id)->date->toDateString())->toBe('2026-10-20')
        ->and(journalFor('cash_movement', $reversal->id))->toBeNull()
        ->and(fn () => app(ReverseMoneyEntry::class)->handle($f['finance'], $transfer, '2026-10-20', 'Again'))->toThrow(ValidationException::class, 'already reversed')
        ->and(fn () => app(ReverseMoneyEntry::class)->handle($f['finance'], $reversal, '2026-10-20', 'Again'))->toThrow(ValidationException::class, 'cannot be reversed');
});

test('nothing is dated on or before the lock or after today, and only Finance records or reverses', function () {
    $f = $this->f;
    $charge = fn (string $date) => ($this->move)(['kind' => 'bank_charge', 'from_account_id' => ($this->id)('1010'), 'amount' => '1', 'date' => $date]);
    $kept = $charge('2026-10-11');
    app(LockLedger::class)->handle($f['finance'], '2026-10-10');

    expect(fn () => $charge('2026-10-10'))->toThrow(ValidationException::class, '11/10/2026')
        ->and(fn () => $charge('2026-10-21'))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordCashMovement::class)->handle($f['admin'], ['kind' => 'bank_charge']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReverseMoneyEntry::class)->handle($f['admin'], $kept, '2026-10-20', 'x'))->toThrow(AuthorizationException::class)
        ->and(postedLines('cash_movement', $kept->id))->toBe(['5180 1.000 0.000', '1010 0.000 1.000']);
});

test('the page records, filters and reverses; Admin only views; tampered input gives messages', function () {
    $f = $this->f;
    $page = Livewire::actingAs($f['finance'])->test(CashMovements::class)
        ->set('kind', 'transfer')->set('from_account_id', ($this->id)('1000'))->set('to_account_id', ($this->id)('1010'))
        ->set('amount', '500')->set('reference', 'DEP-1')->call('save')->assertHasNoErrors()
        ->set('kind', 'bank_interest')->set('to_account_id', ($this->id)('1010'))->set('amount', '1.200')->set('reference', 'INT-OCT')->call('save')->assertHasNoErrors()
        ->set('filterKind', 'bank_interest')->assertSee('INT-OCT')->assertDontSee('DEP-1')
        ->set('filterKind', '')->set('filterAccount', ($this->id)('1000'))->assertSee('DEP-1')->assertDontSee('INT-OCT')
        ->set('filterAccount', '')->set('filterFrom', '2026-10-21')->assertDontSee('DEP-1')
        ->set('filterFrom', '2026-02-31')->assertSee('DEP-1')
        ->call('startReverse', (string) CashMovement::query()->where('reference', 'DEP-1')->value('id'))->set('reverseReason', 'Wrong day')->call('reverse')->assertHasNoErrors()
        ->assertSee('Reversal of #')
        ->set('kind', ['x'])->call('save')->assertHasErrors('kind')
        ->set('kind', 'transfer')->set('amount', ['x'])->call('save')->assertHasErrors('amount')
        ->set('filterKind', ['x'])->set('filterAccount', ['x'])->assertOk();
    $page->call('startReverse', 'abc')->assertNotFound();

    expect(CashMovement::query()->count())->toBe(3);

    Livewire::actingAs($f['admin'])->test(CashMovements::class)->assertOk()->assertDontSee('Record movement')->call('save')->assertForbidden();
    Livewire::actingAs($f['management'])->test(CashMovements::class)->assertForbidden();
});
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php artisan test tests/Feature/Ledger/CashMovementTest.php`
Expected: FAIL. `App\Actions\Ledger\RecordCashMovement` does not exist.

- [ ] **Step 3: The enum and the model.**
  - `app/Enums/CashMovementKind.php`:

```php
<?php

namespace App\Enums;

/** Ledger money screens spec §3: the three kinds of cash and bank movement. */
enum CashMovementKind: string
{
    case Transfer = 'transfer';
    case BankCharge = 'bank_charge';
    case BankInterest = 'bank_interest';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => __('Transfer between money accounts'),
            self::BankCharge => __('Bank charge'),
            self::BankInterest => __('Bank interest received'),
        };
    }
}
```

  - `app/Models/CashMovement.php`:

```php
<?php

namespace App\Models;

use App\Enums\CashMovementKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ledger money screens spec §3 (plan ruling 8). Written only by RecordCashMovement and ReverseMoneyEntry; never updated
 * or deleted (two triggers). A reversal is its own row pointing at the movement it reverses.
 *
 * @property int $id
 * @property CashMovementKind $kind
 * @property int|null $from_account_id
 * @property int|null $to_account_id
 * @property string $amount
 * @property CarbonImmutable $date
 * @property string $reference
 * @property string|null $note
 * @property int|null $reverses_id
 * @property int $created_by
 * @property-read LedgerAccount|null $fromAccount
 * @property-read LedgerAccount|null $toAccount
 * @property-read CashMovement|null $reversal
 */
class CashMovement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['kind' => CashMovementKind::class, 'amount' => 'decimal:3', 'date' => 'immutable_date'];
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'from_account_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'to_account_id');
    }

    /** @return HasOne<CashMovement, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(CashMovement::class, 'reverses_id');
    }
}
```

- [ ] **Step 4: The sources.** In `app/Ledger/Sources.php`:
  - add `use App\Actions\Ledger\PostManualJournal;` before `use App\Support\Fils;`, and `use Illuminate\Validation\ValidationException;` after `use Illuminate\Support\Facades\DB;`;
  - in `TYPES`, after the `'owner_payable_cancel' => [...]` line add:

```php
        // Ledger money screens plan rulings 8–9: a reversal is its own row (reverses_id), dated by its own date.
        'cash_movement' => ['cash_movements', 't.date', 't.reverses_id IS NULL', false],
        'cash_movement_reversal' => ['cash_movements', 't.date', 't.reverses_id IS NOT NULL', false],
```

  - after the `REVERSES` constant add:

```php

    /** reversal type => [its table, the type whose lines it negates]: the original is the row's reverses_id */
    private const array REVERSAL_ROWS = [
        'cash_movement_reversal' => ['cash_movements', 'cash_movement'],
    ];
```

  - in `build()`, after the `if (isset(self::REVERSES[$type])) { … }` block add:

```php
        if (isset(self::REVERSAL_ROWS[$type])) {
            [$table, $baseType] = self::REVERSAL_ROWS[$type];
            $original = DB::table($table)->where('id', $id)->value('reverses_id') ?? throw new LogicException("Reversal {$id} in {$table} is missing.");
            $base = self::build($baseType, (int) $original, $rules);

            return ['description' => $base['description'], 'lines' => array_map(fn (array $l) => [...$l, 'amount' => -$l['amount']], $base['lines'])];
        }
```

    and in its `match ($type)` add `'cash_movement' => self::cashMovement($id, $rules),` after `'owner_payable' => self::ownerPayable($id, $rules),`;
  - in `label()`, before `'manual' => __('Manual journal'),` add:

```php
            'cash_movement' => __('Cash and bank movement'),
            'cash_movement_reversal' => __('Cash and bank movement reversal'),
```

  - in `url()`, before `default => route('ledger.journals.show', $journalId),` add:

```php
            'cash_movement', 'cash_movement_reversal' => route('ledger.cash-movements'),
```

  - after `url()` add:

```php
    /**
     * Ledger money screens plan ruling 10: refuses, on $field, a record whose journal cannot be written now (a rule it needs
     * has no active account, or an account it uses is inactive). Called inside the recording transaction after the row is
     * saved, so a refusal undoes the row; Poster still posts it after commit.
     */
    public static function assertPostable(string $type, int $id, string $field): void
    {
        try {
            $lines = self::build($type, $id, Rules::load())['lines'];
        } catch (LogicException) {
            throw ValidationException::withMessages([$field => __('A posting rule this needs has no active account. Ask Admin to fix the posting rules.')]);
        }
        PostManualJournal::activeAccounts(array_column($lines, 'account'), $field);
    }
```

  - after `ownerPayable()` add:

```php
    /**
     * Ledger money screens spec §3 (plan ruling 8): a transfer moves money from one money account to another; a bank charge
     * is an expense paid from one; bank interest is income received into one.
     *
     * @return Built
     */
    private static function cashMovement(int $id, Rules $rules): array
    {
        $m = DB::table('cash_movements')->where('id', $id)->first(['kind', 'from_account_id', 'to_account_id', 'amount', 'reference'])
            ?? throw new LogicException("Cash movement {$id} is missing.");
        $amount = self::fils($m->amount);
        $debit = $m->kind === 'bank_charge' ? $rules->account('bank_charges') : (int) $m->to_account_id;
        $credit = $m->kind === 'bank_interest' ? $rules->account('bank_interest') : (int) $m->from_account_id;

        return ['description' => (string) $m->reference, 'lines' => [self::line($debit, $amount), self::line($credit, -$amount)]];
    }
```

- [ ] **Step 5: Poster watches the new model.** In `app/Ledger/Poster.php`, add `use App\Models\CashMovement;` before `use App\Models\Disbursement;`, and in `WATCH` after `OwnerPayable::class => ['owner_payable', 'owner_payable_cancel'],` add:

```php
        CashMovement::class => ['cash_movement', 'cash_movement_reversal'],
```

- [ ] **Step 6: The actions.**
  - `app/Actions/Ledger/RecordCashMovement.php`:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\CashMovementKind;
use App\Enums\PermissionName;
use App\Ledger\Sources;
use App\Models\CashMovement;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ledger money screens spec §3 (plan rulings 8 and 10): Finance records a transfer between two money accounts, a bank
 * charge paid from one, or bank interest received into one, on an open date. Poster posts it after commit.
 */
final class RecordCashMovement
{
    /** @param  mixed  $data  kind, from_account_id, to_account_id, amount, date, reference, note */
    public function handle(User $actor, mixed $data): CashMovement
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        $data = is_array($data) ? $data : [];
        $kind = CashMovementKind::tryFrom(is_string($data['kind'] ?? null) ? $data['kind'] : '');
        $needsFrom = $kind !== CashMovementKind::BankInterest;
        $needsTo = $kind !== CashMovementKind::BankCharge;
        $v = Validator::make($data, [
            'kind' => ['required', 'string', Rule::enum(CashMovementKind::class)],
            'from_account_id' => [Rule::requiredIf($needsFrom), 'nullable', 'integer'],
            'to_account_id' => [Rule::requiredIf($needsTo), 'nullable', 'integer'],
            'amount' => ['required', 'string', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'date' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'reference' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $kind = CashMovementKind::from((string) $v['kind']);
        $from = $needsFrom ? (int) $v['from_account_id'] : null;
        $to = $needsTo ? (int) $v['to_account_id'] : null;
        if ($from !== null && $from === $to) {
            throw ValidationException::withMessages(['to_account_id' => __('Choose two different money accounts.')]);
        }

        return DB::transaction(function () use ($actor, $v, $kind, $from, $to) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            PostManualJournal::openDate($settings, (string) $v['date'], 'date');
            foreach (['from_account_id' => $from, 'to_account_id' => $to] as $field => $id) {
                if ($id !== null && ! LedgerAccount::query()->whereKey($id)->where('is_money', true)->where('active', true)->exists()) {
                    throw ValidationException::withMessages([$field => __('Choose an active money account.')]);
                }
            }

            $movement = (new CashMovement)->forceFill([
                'kind' => $kind,
                'from_account_id' => $from,
                'to_account_id' => $to,
                'amount' => Fils::toDecimal(Fils::fromDecimal((string) $v['amount'])),
                'date' => (string) $v['date'],
                'reference' => trim((string) $v['reference']),
                'note' => filled($v['note'] ?? null) ? trim((string) $v['note']) : null,
                'created_by' => $actor->id,
            ]);
            $movement->save();
            Sources::assertPostable('cash_movement', $movement->id, 'kind');
            Audit::log('ledger.cash_movement_recorded', $movement, new: ['kind' => $kind->value, 'amount' => $movement->amount, 'date' => (string) $v['date']], causer: $actor);

            return $movement;
        });
    }
}
```

  - `app/Actions/Ledger/ReverseMoneyEntry.php`:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\Sources;
use App\Models\CashMovement;
use App\Models\LedgerSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Ledger money screens spec §3 (plan rulings 8 and 10): a movement is reversed once, by a row of its own that posts the
 * exact opposite, on an open date on or after the original's. A reversal is never itself reversed.
 */
final class ReverseMoneyEntry
{
    public function handle(User $actor, CashMovement $entry, mixed $date, mixed $reason): CashMovement
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        Validator::make(['reverseDate' => $date, 'reverseReason' => $reason], [
            'reverseDate' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'reverseReason' => ['required', 'string', 'max:1000'],
        ])->validate();
        $date = (string) $date;

        return DB::transaction(function () use ($actor, $entry, $date, $reason) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            $original = CashMovement::query()->lockForUpdate()->findOrFail($entry->id);
            if ($original->reverses_id !== null) {
                throw ValidationException::withMessages(['reverseDate' => __('A reversal cannot be reversed.')]);
            }
            if ($original->reversal()->exists()) {
                throw ValidationException::withMessages(['reverseDate' => __('This entry is already reversed.')]);
            }
            if ($date < $original->date->toDateString()) {
                throw ValidationException::withMessages(['reverseDate' => __('The reversal cannot be dated before the original (:d).', ['d' => $original->date->format('d/m/Y')])]);
            }
            PostManualJournal::openDate($settings, $date, 'reverseDate');

            $reversal = $original->replicate();
            $reversal->forceFill(['date' => $date, 'note' => trim((string) $reason), 'reverses_id' => $original->id, 'created_by' => $actor->id])->save();
            Sources::assertPostable('cash_movement_reversal', $reversal->id, 'reverseDate');
            Audit::log('ledger.money_entry_reversed', $original, properties: ['reversal' => $reversal->id, 'date' => $date], causer: $actor);

            return $reversal;
        });
    }
}
```

- [ ] **Step 7: The page.**
  - `app/Livewire/Ledger/CashMovements.php`:

```php
<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\RecordCashMovement;
use App\Actions\Ledger\ReverseMoneyEntry;
use App\Enums\CashMovementKind;
use App\Enums\PermissionName;
use App\Livewire\Concerns\WithActor;
use App\Models\CashMovement;
use App\Models\LedgerAccount;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Ledger money screens spec §3: Finance records and reverses transfers, bank charges and bank interest; Finance and Admin view the list. */
class CashMovements extends Component
{
    use WithActor, WithPagination;

    // Untyped on purpose: a tampered payload reaches the action's validator, never a TypeError (a 500).
    public mixed $kind = 'transfer';

    public mixed $from_account_id = '';

    public mixed $to_account_id = '';

    public mixed $amount = '';

    public mixed $date = '';

    public mixed $reference = '';

    public mixed $note = '';

    #[Locked]
    public ?int $reversingId = null;

    public mixed $reverseDate = '';

    public mixed $reverseReason = '';

    #[Url]
    public mixed $filterFrom = '';

    #[Url]
    public mixed $filterTo = '';

    #[Url]
    public mixed $filterKind = '';

    #[Url]
    public mixed $filterAccount = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerView), 403);
        $this->date = $this->reverseDate = now('Asia/Bahrain')->toDateString();
    }

    public function updating(string $property): void
    {
        if (str_starts_with($property, 'filter')) {
            $this->resetPage();
        }
    }

    public function save(): void
    {
        app(RecordCashMovement::class)->handle($this->actor(), ['kind' => $this->kind, 'from_account_id' => $this->from_account_id,
            'to_account_id' => $this->to_account_id, 'amount' => $this->amount, 'date' => $this->date, 'reference' => $this->reference, 'note' => $this->note]);
        $this->reset('amount', 'reference', 'note');
        Flux::toast(variant: 'success', text: __('Movement recorded.'));
    }

    public function startReverse(mixed $id): void
    {
        abort_unless(is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) < 19), 404);
        $this->reversingId = CashMovement::query()->findOrFail((int) $id)->id;
        $this->reverseReason = '';
        $this->resetErrorBag();
    }

    public function cancelReverse(): void
    {
        $this->reversingId = null;
        $this->resetErrorBag();
    }

    public function reverse(): void
    {
        abort_if($this->reversingId === null, 404);
        app(ReverseMoneyEntry::class)->handle($this->actor(), CashMovement::query()->findOrFail($this->reversingId), $this->reverseDate, $this->reverseReason);
        $this->reversingId = null;
        Flux::toast(variant: 'success', text: __('Movement reversed.'));
    }

    /** A real Y-m-d date, or null: a filter never sends a bad date to the database. */
    private static function day(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    public function render(): View
    {
        $account = is_string($this->filterAccount) && ctype_digit($this->filterAccount) && strlen($this->filterAccount) < 19 ? (int) $this->filterAccount : null;
        $kind = is_string($this->filterKind) ? CashMovementKind::tryFrom($this->filterKind) : null;
        $rows = CashMovement::query()->with(['fromAccount', 'toAccount', 'reversal'])
            ->when(self::day($this->filterFrom), fn ($q, string $d) => $q->where('date', '>=', $d))
            ->when(self::day($this->filterTo), fn ($q, string $d) => $q->where('date', '<=', $d))
            ->when($kind, fn ($q, CashMovementKind $k) => $q->where('kind', $k->value))
            ->when($account, fn ($q, int $a) => $q->where(fn ($w) => $w->where('from_account_id', $a)->orWhere('to_account_id', $a)))
            ->latest('date')->latest('id')
            ->paginate(50);

        return view('livewire.ledger.cash-movements', [
            'rows' => $rows,
            'kinds' => CashMovementKind::cases(),
            'money' => LedgerAccount::query()->where('is_money', true)->where('active', true)->orderBy('code')->get(),
            'accounts' => LedgerAccount::query()->where('is_money', true)->orderBy('code')->get(),
            'canManage' => $this->actor()->can(PermissionName::LedgerManage),
        ])->title(__('Cash and bank movements'));
    }
}
```

  - `resources/views/livewire/ledger/cash-movements.blade.php`:

```blade
<section class="w-full max-w-6xl space-y-8">
    <flux:heading size="xl" level="1">{{ __('Cash and bank movements') }}</flux:heading>

    @if ($canManage)
        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-3">
                <flux:select wire:model.live="kind" :label="__('Kind')">
                    @foreach ($kinds as $k)
                        <flux:select.option :value="$k->value">{{ $k->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($kind !== 'bank_interest')
                    <flux:select wire:model="from_account_id" :label="__('From account')">
                        <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                        @foreach ($money as $account)
                            <flux:select.option :value="(string) $account->id">{{ $account->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                @if ($kind !== 'bank_charge')
                    <flux:select wire:model="to_account_id" :label="__('To account')">
                        <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                        @foreach ($money as $account)
                            <flux:select.option :value="(string) $account->id">{{ $account->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <flux:input wire:model="amount" inputmode="decimal" :label="__('Amount')" />
                <x-date-input wire:model="date" :label="__('Date')" />
                <flux:input wire:model="reference" :label="__('Reference')" />
            </div>
            <flux:input wire:model="note" :label="__('Note')" />
            @foreach (['kind', 'from_account_id', 'to_account_id', 'amount', 'date', 'reference', 'note'] as $field)
                <flux:error name="{{ $field }}" />
            @endforeach
            <flux:button type="submit" variant="primary">{{ __('Record movement') }}</flux:button>
        </form>
    @endif

    @if ($reversingId !== null)
        <form wire:submit="reverse" class="space-y-3">
            <flux:heading size="lg">{{ __('Reverse') }} #{{ $reversingId }}</flux:heading>
            <div class="grid gap-3 sm:grid-cols-2">
                <x-date-input wire:model="reverseDate" :label="__('Reversal date')" />
                <flux:input wire:model="reverseReason" :label="__('Reason')" />
            </div>
            <flux:error name="reverseDate" />
            <flux:error name="reverseReason" />
            <div class="flex gap-2">
                <flux:button type="submit" variant="danger">{{ __('Reverse') }}</flux:button>
                <flux:button type="button" wire:click="cancelReverse">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif

    <div class="grid gap-3 sm:grid-cols-4">
        <x-date-input wire:model.live="filterFrom" :label="__('From')" />
        <x-date-input wire:model.live="filterTo" :label="__('To')" />
        <flux:select wire:model.live="filterKind" :label="__('Kind')">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            @foreach ($kinds as $k)
                <flux:select.option :value="$k->value">{{ $k->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="filterAccount" :label="__('Account')">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            @foreach ($accounts as $account)
                <flux:select.option :value="(string) $account->id">{{ $account->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$rows">
            <flux:table.columns>
                <flux:table.column>#</flux:table.column>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Kind') }}</flux:table.column>
                <flux:table.column>{{ __('From account') }}</flux:table.column>
                <flux:table.column>{{ __('To account') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column>{{ __('Reference') }}</flux:table.column>
                <flux:table.column>{{ __('Note') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($rows as $row)
                    <flux:table.row :key="'m-'.$row->id">
                        <flux:table.cell>{{ $row->id }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $row->date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $row->kind->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $row->fromAccount?->label() ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $row->toAccount?->label() ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $row->amount }}</flux:table.cell>
                        <flux:table.cell>{{ $row->reference }}</flux:table.cell>
                        <flux:table.cell>{{ $row->note }}</flux:table.cell>
                        <flux:table.cell class="text-end">
                            @if ($row->reverses_id !== null)
                                {{ __('Reversal of #:id', ['id' => $row->reverses_id]) }}
                            @elseif ($row->reversal !== null)
                                {{ __('Reversed') }}
                            @elseif ($canManage)
                                <flux:button size="sm" wire:click="startReverse({{ $row->id }})">{{ __('Reverse') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```

- [ ] **Step 8: Route and sidebar.**
  - In `routes/ledger.php`, after the `journals` route (`Route::livewire('journals', Reports\JournalListReport::class)…->name('journals');`) add:

```php
    Route::livewire('cash-movements', Ledger\CashMovements::class)->middleware('can:ledger.view')->name('cash-movements');
```

  - In `resources/views/layouts/app/sidebar.blade.php`, after the "Journal list" `<flux:sidebar.item …>` line add:

```blade
                            <flux:sidebar.item icon="arrows-right-left" :href="route('ledger.cash-movements')" :current="request()->routeIs('ledger.cash-movements')" wire:navigate>{{ __('Cash and bank movements') }}</flux:sidebar.item>
```

- [ ] **Step 9: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Cash and bank movements": "الحركات النقدية والمصرفية",
    "Cash and bank movement": "حركة نقدية أو مصرفية",
    "Cash and bank movement reversal": "عكس حركة نقدية أو مصرفية",
    "Transfer between money accounts": "تحويل بين الحسابات النقدية",
    "Bank charge": "رسوم مصرفية",
    "Bank interest received": "فوائد بنكية مقبوضة",
    "From account": "من حساب",
    "To account": "إلى حساب",
    "Note": "ملاحظة",
    "Reason": "السبب",
    "Record movement": "تسجيل الحركة",
    "Movement recorded.": "تم تسجيل الحركة.",
    "Movement reversed.": "تم عكس الحركة.",
    "Reversal of #:id": "عكس للقيد رقم :id",
    "Choose an active money account.": "اختر حساباً نقدياً نشطاً.",
    "Choose two different money accounts.": "اختر حسابين نقديين مختلفين.",
    "A reversal cannot be reversed.": "لا يمكن عكس قيد عكسي.",
    "This entry is already reversed.": "هذا القيد معكوس بالفعل.",
    "The reversal cannot be dated before the original (:d).": "لا يمكن أن يسبق تاريخ العكس تاريخ القيد الأصلي (:d).",
    "A posting rule this needs has no active account. Ask Admin to fix the posting rules.": "إحدى قواعد الترحيل المطلوبة ليس لها حساب نشط. اطلب من المسؤول تصحيح قواعد الترحيل.",
```

- [ ] **Step 10: Run the tests.**

Run: `php artisan test tests/Feature/Ledger`
Expected: PASS. `LedgerAccessTest`'s Arabic sweep picks up the new files under `app/Ledger`, `app/Actions/Ledger`, `app/Livewire/Ledger` and `views/livewire/ledger` and finds every key.

- [ ] **Step 11: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Enums/CashMovementKind.php app/Models/CashMovement.php app/Actions/Ledger/RecordCashMovement.php app/Actions/Ledger/ReverseMoneyEntry.php app/Livewire/Ledger/CashMovements.php resources/views/livewire/ledger/cash-movements.blade.php app/Ledger/Sources.php app/Ledger/Poster.php routes/ledger.php resources/views/layouts/app/sidebar.blade.php tests/Feature/Ledger/CashMovementTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
Cash and bank movements: transfers between money accounts, bank charges and bank interest, posted through the Poster, reversed once, listed with filters

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: VAT returns: the period figure, payment or refund, overlap, reversal, list

**Files:**
- Create: `app/Models/VatReturn.php`, `app/Actions/Ledger/RecordVatReturn.php`, `app/Livewire/Ledger/VatReturns.php`, `resources/views/livewire/ledger/vat-returns.blade.php`
- Modify: `app/Ledger/Balances.php`, `app/Ledger/Sources.php`, `app/Ledger/Poster.php`, `app/Actions/Ledger/ReverseMoneyEntry.php`, `routes/ledger.php`, `resources/views/layouts/app/sidebar.blade.php`, `lang/ar.json`
- Test: `tests/Feature/Ledger/VatReturnTest.php`

**Interfaces:**
- Consumes: Task 1 (`vat_returns`); Task 5 (`Sources::REVERSAL_ROWS`, `Sources::assertPostable`, `ReverseMoneyEntry`); `DisbursementMethod`; `PostManualJournal`; `SaveOpeningBalances::handle(User, mixed)`; `LockLedger`.
- Produces:
  - `Balances::vatDue(string $from, string $to): int`: fils; + = owed to NBR, − = refundable.
  - `VatReturn` (`period_from`, `period_to`, `direction` `payment|refund`, `method` (`DisbursementMethod`), `amount`, `date`, `reference`, `note`, `reverses_id`, `created_by`; `reversal(): HasOne<VatReturn>`).
  - `RecordVatReturn::handle(User, mixed $data): VatReturn`, where `$data` is `period_from, period_to, date, method, reference, amount, note`. Error keys are the same names.
  - `ReverseMoneyEntry::handle(User, CashMovement|VatReturn $entry, mixed $date, mixed $reason): CashMovement|VatReturn`.
  - Source types `vat_return` and `vat_return_reversal`. Route `ledger.vat-returns`. Page `App\Livewire\Ledger\VatReturns` (properties as the action's keys plus `#[Locked] reversingId`, `reverseDate`, `reverseReason`; methods `save`, `startReverse(mixed)`, `cancelReverse`, `reverse`).

- [ ] **Step 1: Write the failing test** `tests/Feature/Ledger/VatReturnTest.php`:

```php
<?php

use App\Actions\Ledger\LockLedger;
use App\Actions\Ledger\PostManualJournal;
use App\Actions\Ledger\RecordVatReturn;
use App\Actions\Ledger\ReverseMoneyEntry;
use App\Actions\Ledger\SaveOpeningBalances;
use App\Ledger\Balances;
use App\Livewire\Ledger\VatReturns;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Models\VatReturn;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
    $this->id = fn (string $code) => (string) LedgerAccount::query()->where('code', $code)->value('id');
    $this->vat = fn (array $data) => app(RecordVatReturn::class)->handle($this->f['finance'], ['date' => '2026-10-20', 'method' => 'bank_transfer', 'reference' => 'NBR-1', ...$data]);
});

afterEach(function () {
    LedgerSetting::query()->update(['start_date' => null, 'locked_until' => null, 'health' => null, 'health_checked_at' => null, 'rebuild_status' => null, 'rebuild_from' => null,
        'rebuild_to' => null, 'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null, 'rebuild_run' => null]);
});

test('the period figure is VAT payable credits less debits in the period, with VAT return and opening journals left out', function () {
    $f = $this->f;
    app(SaveOpeningBalances::class)->handle($f['admin'], [($this->id)('2400') => ['debit' => '', 'credit' => '100.000']]);

    expect(Balances::vatDue('2026-10-01', '2026-10-20'))->toBe(42_000)
        ->and(Balances::vatDue('2026-10-02', '2026-10-20'))->toBe(2_000)
        ->and(Balances::vatDue('2026-09-01', '2026-09-30'))->toBe(0);

    ($this->vat)(['period_from' => '2026-10-01', 'period_to' => '2026-10-20', 'amount' => '42']);

    expect(Balances::vatDue('2026-10-01', '2026-10-20'))->toBe(42_000);
});

test('a payment posts VAT payable against the method account; a debit figure records a refund', function () {
    $f = $this->f;
    $payment = ($this->vat)(['period_from' => '2026-10-01', 'period_to' => '2026-10-14', 'amount' => '40']);

    expect([$payment->direction, $payment->amount])->toBe(['payment', '40.000'])
        ->and(postedLines('vat_return', $payment->id))->toBe(['2400 40.000 0.000', '1010 0.000 40.000'])
        ->and(journalFor('vat_return', $payment->id)->description)->toBe('NBR-1 01/10/2026–14/10/2026');

    app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-16', 'description' => 'Input VAT on the lift contract', 'lines' => [
        ['account_id' => ($this->id)('2400'), 'debit' => '10.000', 'credit' => ''], ['account_id' => ($this->id)('1010'), 'debit' => '', 'credit' => '10.000']]]);
    expect(Balances::vatDue('2026-10-15', '2026-10-20'))->toBe(-8_000);

    $refund = ($this->vat)(['period_from' => '2026-10-15', 'period_to' => '2026-10-20', 'method' => 'cheque', 'amount' => '8', 'reference' => 'NBR-R1']);

    expect($refund->direction)->toBe('refund')
        ->and(postedLines('vat_return', $refund->id))->toBe(['2400 0.000 8.000', '1010 8.000 0.000']);
});

test('periods may not overlap a standing return; a reversal posts the opposite once and frees the period', function () {
    $f = $this->f;
    $first = ($this->vat)(['period_from' => '2026-10-01', 'period_to' => '2026-10-20', 'amount' => '42']);

    expect(fn () => ($this->vat)(['period_from' => '2026-10-20', 'period_to' => '2026-10-20', 'amount' => '1']))
        ->toThrow(ValidationException::class, 'overlaps the return for 01/10/2026 – 20/10/2026');

    $reversal = app(ReverseMoneyEntry::class)->handle($f['finance'], $first, '2026-10-20', 'Wrong amount');

    expect($reversal->reverses_id)->toBe($first->id)
        ->and(postedLines('vat_return_reversal', $reversal->id))->toBe(['2400 0.000 42.000', '1010 42.000 0.000'])
        ->and(fn () => app(ReverseMoneyEntry::class)->handle($f['finance'], $first, '2026-10-20', 'Again'))->toThrow(ValidationException::class, 'already reversed')
        ->and(($this->vat)(['period_from' => '2026-10-01', 'period_to' => '2026-10-20', 'amount' => '42'])->direction)->toBe('payment');
});

test('the date is open and the period ends by today; only Finance records; tampered input gives a message', function () {
    $f = $this->f;
    app(LockLedger::class)->handle($f['finance'], '2026-10-10');

    expect(fn () => ($this->vat)(['period_from' => '2026-10-01', 'period_to' => '2026-10-09', 'amount' => '40', 'date' => '2026-10-10']))
        ->toThrow(ValidationException::class, '11/10/2026')
        ->and(fn () => ($this->vat)(['period_from' => '2026-10-01', 'period_to' => '2026-10-21', 'amount' => '40']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->vat)(['period_from' => '2026-10-09', 'period_to' => '2026-10-01', 'amount' => '40']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->vat)(['period_from' => '2026-10-01', 'period_to' => '2026-10-09', 'amount' => '40', 'method' => 'card']))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordVatReturn::class)->handle($f['finance'], 'oops'))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordVatReturn::class)->handle($f['admin'], []))->toThrow(AuthorizationException::class);
});

test('the page prefills the figure, records, lists the difference and reverses; Admin only views', function () {
    $f = $this->f;
    Livewire::actingAs($f['finance'])->test(VatReturns::class)
        ->set('period_from', '2026-10-01')->set('period_to', '2026-10-20')
        ->assertSet('amount', '42.000')->assertSee('VAT payable for the period: 42.000 BHD')
        ->set('reference', 'NBR-Q4')->call('save')->assertHasNoErrors()
        ->assertSeeInOrder(['01/10/2026 – 20/10/2026', '42.000', 'Paid to NBR', '42.000', '0.000', 'NBR-Q4'])
        ->call('startReverse', (string) VatReturn::query()->value('id'))->set('reverseReason', 'Wrong period')->call('reverse')->assertHasNoErrors()
        ->assertSee('Reversed')
        ->set('period_from', ['x'])->call('save')->assertHasErrors('period_from')
        ->call('startReverse', 'abc')->assertNotFound();

    Livewire::actingAs($f['admin'])->test(VatReturns::class)->assertOk()->assertDontSee('Record return')->call('save')->assertForbidden();
    Livewire::actingAs($f['management'])->test(VatReturns::class)->assertForbidden();
});
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php artisan test tests/Feature/Ledger/VatReturnTest.php`
Expected: FAIL. `Balances::vatDue()` does not exist.

- [ ] **Step 3: The model.** `app/Models/VatReturn.php`:

```php
<?php

namespace App\Models;

use App\Enums\DisbursementMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ledger money screens spec §4 (plan ruling 9). Written only by RecordVatReturn and ReverseMoneyEntry; never updated or
 * deleted (two triggers). A reversal is its own row pointing at the return it reverses.
 *
 * @property int $id
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property string $direction payment | refund
 * @property DisbursementMethod $method
 * @property string $amount
 * @property CarbonImmutable $date
 * @property string $reference
 * @property string|null $note
 * @property int|null $reverses_id
 * @property int $created_by
 * @property-read VatReturn|null $reversal
 */
class VatReturn extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period_from' => 'immutable_date', 'period_to' => 'immutable_date', 'method' => DisbursementMethod::class, 'amount' => 'decimal:3', 'date' => 'immutable_date'];
    }

    /** @return HasOne<VatReturn, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(VatReturn::class, 'reverses_id');
    }
}
```

- [ ] **Step 4: The period figure.** In `app/Ledger/Balances.php` (which already imports `Fils`, `Builder` and `DB`), after `net()`, add:

```php
    /**
     * Ledger money screens spec §4 (plan ruling 9): VAT payable for a period, in fils: credits − debits on the account the
     * vat_payable rule points at, in journals dated $from..$to, with VAT return journals and the opening journal left
     * out. + = owed to NBR; − = refundable.
     */
    public static function vatDue(string $from, string $to): int
    {
        $account = (int) DB::table('ledger_rules')->where('key', 'vat_payable')->value('ledger_account_id');

        return -Fils::fromDecimal((string) (DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.ledger_account_id', $account)->whereBetween('j.date', [$from, $to])->where('j.kind', '<>', 'opening')
            ->where(fn (Builder $q) => $q->whereNull('j.source_type')->orWhereNotIn('j.source_type', ['vat_return', 'vat_return_reversal']))
            ->sum(DB::raw('l.debit - l.credit')) ?: '0'));
    }
```

- [ ] **Step 5: The sources and the Poster.**
  - In `app/Ledger/Sources.php`:
    - in `TYPES`, after `'cash_movement_reversal' => [...]` add:

```php
        'vat_return' => ['vat_returns', 't.date', 't.reverses_id IS NULL', false],
        'vat_return_reversal' => ['vat_returns', 't.date', 't.reverses_id IS NOT NULL', false],
```

    - in `REVERSAL_ROWS`, after `'cash_movement_reversal' => ['cash_movements', 'cash_movement'],` add `'vat_return_reversal' => ['vat_returns', 'vat_return'],`;
    - in `build()`'s `match`, after `'cash_movement' => self::cashMovement($id, $rules),` add `'vat_return' => self::vatReturn($id, $rules),`;
    - in `label()`, after `'cash_movement_reversal' => __('Cash and bank movement reversal'),` add:

```php
            'vat_return' => __('VAT return'),
            'vat_return_reversal' => __('VAT return reversal'),
```

    - in `url()`, after `'cash_movement', 'cash_movement_reversal' => route('ledger.cash-movements'),` add `'vat_return', 'vat_return_reversal' => route('ledger.vat-returns'),`;
    - after `cashMovement()` add:

```php
    /**
     * Ledger money screens spec §4 (plan ruling 9): a payment to NBR settles VAT payable from the method's account; a
     * refund is the opposite.
     *
     * @return Built
     */
    private static function vatReturn(int $id, Rules $rules): array
    {
        $r = DB::table('vat_returns')->where('id', $id)->first(['direction', 'method', 'amount', 'reference', 'period_from', 'period_to'])
            ?? throw new LogicException("VAT return {$id} is missing.");
        $amount = ($r->direction === 'refund' ? -1 : 1) * self::fils($r->amount);
        $period = CarbonImmutable::parse((string) $r->period_from)->format('d/m/Y').'–'.CarbonImmutable::parse((string) $r->period_to)->format('d/m/Y');

        return ['description' => trim($r->reference.' '.$period), 'lines' => [
            self::line($rules->account('vat_payable'), $amount),
            self::line($rules->account('method.'.$r->method), -$amount),
        ]];
    }
```

  - In `app/Ledger/Poster.php`, add `use App\Models\VatReturn;` after `use App\Models\PaymentAllocation;`, and in `WATCH` after `CashMovement::class => ['cash_movement', 'cash_movement_reversal'],` add:

```php
        VatReturn::class => ['vat_return', 'vat_return_reversal'],
```

- [ ] **Step 6: The actions.**
  - `app/Actions/Ledger/RecordVatReturn.php`:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\DisbursementMethod;
use App\Enums\PermissionName;
use App\Ledger\Balances;
use App\Ledger\Sources;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Models\VatReturn;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ledger money screens spec §4 (plan rulings 9–10): Finance records the payment to NBR for a period, or the refund when
 * the period's VAT payable is a debit. Periods of standing returns never overlap. Poster posts it after commit.
 */
final class RecordVatReturn
{
    /** @param  mixed  $data  period_from, period_to, date, method, reference, amount, note */
    public function handle(User $actor, mixed $data): VatReturn
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        $data = is_array($data) ? $data : [];
        $today = now('Asia/Bahrain')->toDateString();
        $v = Validator::make($data, [
            'period_from' => ['required', 'string', 'date_format:Y-m-d'],
            'period_to' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:period_from', 'before_or_equal:'.$today],
            'date' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'method' => ['required', 'string', Rule::enum(DisbursementMethod::class)],
            'reference' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'string', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'note' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $from = (string) $v['period_from'];
        $to = (string) $v['period_to'];

        return DB::transaction(function () use ($actor, $v, $from, $to) {
            // Every ledger writer takes this lock first, so the overlap check below cannot race another return.
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            PostManualJournal::openDate($settings, (string) $v['date'], 'date');
            $clash = VatReturn::query()->whereNull('reverses_id')->whereDoesntHave('reversal')
                ->where('period_from', '<=', $to)->where('period_to', '>=', $from)->orderBy('period_from')->first();
            if ($clash !== null) {
                throw ValidationException::withMessages(['period_from' => __('This period overlaps the return for :from – :to.', [
                    'from' => $clash->period_from->format('d/m/Y'), 'to' => $clash->period_to->format('d/m/Y')])]);
            }
            $figure = Balances::vatDue($from, $to);

            $return = (new VatReturn)->forceFill([
                'period_from' => $from,
                'period_to' => $to,
                'direction' => $figure < 0 ? 'refund' : 'payment',
                'method' => (string) $v['method'],
                'amount' => Fils::toDecimal(Fils::fromDecimal((string) $v['amount'])),
                'date' => (string) $v['date'],
                'reference' => trim((string) $v['reference']),
                'note' => filled($v['note'] ?? null) ? trim((string) $v['note']) : null,
                'created_by' => $actor->id,
            ]);
            $return->save();
            Sources::assertPostable('vat_return', $return->id, 'method');
            Audit::log('ledger.vat_return_recorded', $return, new: ['period_from' => $from, 'period_to' => $to, 'direction' => $return->direction,
                'amount' => $return->amount, 'figure' => Fils::toDecimal($figure)], causer: $actor);

            return $return;
        });
    }
}
```

  - Replace `app/Actions/Ledger/ReverseMoneyEntry.php` with:

```php
<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\Sources;
use App\Models\CashMovement;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Models\VatReturn;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Ledger money screens spec §3–§4 (plan rulings 8–10): a cash or bank movement or a VAT return is reversed once, by a row
 * of its own that posts the exact opposite, on an open date on or after the original's. A reversal is never itself reversed.
 */
final class ReverseMoneyEntry
{
    public function handle(User $actor, CashMovement|VatReturn $entry, mixed $date, mixed $reason): CashMovement|VatReturn
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        Validator::make(['reverseDate' => $date, 'reverseReason' => $reason], [
            'reverseDate' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'reverseReason' => ['required', 'string', 'max:1000'],
        ])->validate();
        $date = (string) $date;

        return DB::transaction(function () use ($actor, $entry, $date, $reason) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            $original = $entry instanceof CashMovement
                ? CashMovement::query()->lockForUpdate()->findOrFail($entry->id)
                : VatReturn::query()->lockForUpdate()->findOrFail($entry->id);
            if ($original->reverses_id !== null) {
                throw ValidationException::withMessages(['reverseDate' => __('A reversal cannot be reversed.')]);
            }
            if ($original->reversal()->exists()) {
                throw ValidationException::withMessages(['reverseDate' => __('This entry is already reversed.')]);
            }
            if ($date < $original->date->toDateString()) {
                throw ValidationException::withMessages(['reverseDate' => __('The reversal cannot be dated before the original (:d).', ['d' => $original->date->format('d/m/Y')])]);
            }
            PostManualJournal::openDate($settings, $date, 'reverseDate');

            $reversal = $original->replicate();
            $reversal->forceFill(['date' => $date, 'note' => trim((string) $reason), 'reverses_id' => $original->id, 'created_by' => $actor->id])->save();
            Sources::assertPostable($original instanceof CashMovement ? 'cash_movement_reversal' : 'vat_return_reversal', $reversal->id, 'reverseDate');
            Audit::log('ledger.money_entry_reversed', $original, properties: ['reversal' => $reversal->id, 'date' => $date], causer: $actor);

            return $reversal;
        });
    }
}
```

- [ ] **Step 7: The page.**
  - `app/Livewire/Ledger/VatReturns.php`:

```php
<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\RecordVatReturn;
use App\Actions\Ledger\ReverseMoneyEntry;
use App\Enums\DisbursementMethod;
use App\Enums\PermissionName;
use App\Ledger\Balances;
use App\Livewire\Concerns\WithActor;
use App\Models\VatReturn;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/** Ledger money screens spec §4: Finance records and reverses VAT returns; Finance and Admin view each period's figure and difference. */
class VatReturns extends Component
{
    use WithActor, WithPagination;

    // Untyped on purpose: a tampered payload reaches the action's validator, never a TypeError (a 500).
    public mixed $period_from = '';

    public mixed $period_to = '';

    public mixed $date = '';

    public mixed $method = 'bank_transfer';

    public mixed $reference = '';

    public mixed $amount = '';

    public mixed $note = '';

    #[Locked]
    public ?int $reversingId = null;

    public mixed $reverseDate = '';

    public mixed $reverseReason = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerView), 403);
        $this->date = $this->reverseDate = now('Asia/Bahrain')->toDateString();
    }

    /** Spec §4: once both ends of the period are set, the amount is prefilled with the period's figure from the ledger. */
    public function updated(string $property): void
    {
        $figure = in_array($property, ['period_from', 'period_to'], true) ? $this->figure() : null;
        if ($figure !== null) {
            $this->amount = Fils::toDecimal(abs($figure));
        }
    }

    public function save(): void
    {
        app(RecordVatReturn::class)->handle($this->actor(), ['period_from' => $this->period_from, 'period_to' => $this->period_to, 'date' => $this->date,
            'method' => $this->method, 'reference' => $this->reference, 'amount' => $this->amount, 'note' => $this->note]);
        $this->reset('period_from', 'period_to', 'reference', 'amount', 'note');
        Flux::toast(variant: 'success', text: __('Return recorded.'));
    }

    public function startReverse(mixed $id): void
    {
        abort_unless(is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) < 19), 404);
        $this->reversingId = VatReturn::query()->findOrFail((int) $id)->id;
        $this->reverseReason = '';
        $this->resetErrorBag();
    }

    public function cancelReverse(): void
    {
        $this->reversingId = null;
        $this->resetErrorBag();
    }

    public function reverse(): void
    {
        abort_if($this->reversingId === null, 404);
        app(ReverseMoneyEntry::class)->handle($this->actor(), VatReturn::query()->findOrFail($this->reversingId), $this->reverseDate, $this->reverseReason);
        $this->reversingId = null;
        Flux::toast(variant: 'success', text: __('Return reversed.'));
    }

    /** The figure for the period typed so far, or null until both ends are real dates in order. */
    private function figure(): ?int
    {
        $from = self::day($this->period_from);
        $to = self::day($this->period_to);

        return $from !== null && $to !== null && $from <= $to ? Balances::vatDue($from, $to) : null;
    }

    private static function day(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    public function render(): View
    {
        $figure = $this->figure();
        $rows = VatReturn::query()->whereNull('reverses_id')->with('reversal')->latest('period_from')->latest('id')->paginate(50);
        $lines = [];
        foreach ($rows as $row) {
            $due = Balances::vatDue($row->period_from->toDateString(), $row->period_to->toDateString());
            $paid = ($row->direction === 'refund' ? -1 : 1) * Fils::fromDecimal($row->amount);
            $lines[$row->id] = ['figure' => Fils::toDecimal($due), 'difference' => $row->reversal === null ? Fils::toDecimal($due - $paid) : null];
        }

        return view('livewire.ledger.vat-returns', [
            'rows' => $rows,
            'lines' => $lines,
            'figure' => $figure,
            'figureAmount' => $figure === null ? null : Fils::toDecimal(abs($figure)),
            'methods' => DisbursementMethod::cases(),
            'canManage' => $this->actor()->can(PermissionName::LedgerManage),
        ])->title(__('VAT returns'));
    }
}
```

  - `resources/views/livewire/ledger/vat-returns.blade.php`:

```blade
<section class="w-full max-w-6xl space-y-8">
    <flux:heading size="xl" level="1">{{ __('VAT returns') }}</flux:heading>

    @if ($canManage)
        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-date-input wire:model.live="period_from" :label="__('Period from')" />
                <x-date-input wire:model.live="period_to" :label="__('Period to')" />
            </div>
            @if ($figure !== null)
                <flux:text>{{ $figure < 0
                    ? __('VAT refundable for the period: :a BHD. Record the refund from NBR.', ['a' => $figureAmount])
                    : __('VAT payable for the period: :a BHD. Record the payment to NBR.', ['a' => $figureAmount]) }}</flux:text>
            @endif
            <div class="grid gap-3 sm:grid-cols-4">
                <x-date-input wire:model="date" :label="__('Date')" />
                <flux:select wire:model="method" :label="__('Method')">
                    @foreach ($methods as $m)
                        <flux:select.option :value="$m->value">{{ __($m->label()) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="reference" :label="__('Reference')" />
                <flux:input wire:model="amount" inputmode="decimal" :label="__('Amount')" />
            </div>
            <flux:input wire:model="note" :label="__('Note')" />
            @foreach (['period_from', 'period_to', 'date', 'method', 'reference', 'amount', 'note'] as $field)
                <flux:error name="{{ $field }}" />
            @endforeach
            <flux:button type="submit" variant="primary">{{ __('Record return') }}</flux:button>
        </form>
    @endif

    @if ($reversingId !== null)
        <form wire:submit="reverse" class="space-y-3">
            <flux:heading size="lg">{{ __('Reverse') }} #{{ $reversingId }}</flux:heading>
            <div class="grid gap-3 sm:grid-cols-2">
                <x-date-input wire:model="reverseDate" :label="__('Reversal date')" />
                <flux:input wire:model="reverseReason" :label="__('Reason')" />
            </div>
            <flux:error name="reverseDate" />
            <flux:error name="reverseReason" />
            <div class="flex gap-2">
                <flux:button type="submit" variant="danger">{{ __('Reverse') }}</flux:button>
                <flux:button type="button" wire:click="cancelReverse">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif

    <div class="overflow-x-auto">
        <flux:table :paginate="$rows">
            <flux:table.columns>
                <flux:table.column>{{ __('Period') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Ledger figure') }}</flux:table.column>
                <flux:table.column>{{ __('Paid or refunded') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Difference') }}</flux:table.column>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Method') }}</flux:table.column>
                <flux:table.column>{{ __('Reference') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($rows as $row)
                    <flux:table.row :key="'v-'.$row->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $row->period_from->format('d/m/Y') }} – {{ $row->period_to->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $lines[$row->id]['figure'] }}</flux:table.cell>
                        <flux:table.cell>{{ $row->direction === 'refund' ? __('Refunded by NBR') : __('Paid to NBR') }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $row->amount }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $lines[$row->id]['difference'] ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $row->date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ __($row->method->label()) }}</flux:table.cell>
                        <flux:table.cell>{{ $row->reference }}</flux:table.cell>
                        <flux:table.cell class="text-end">
                            @if ($row->reversal !== null)
                                {{ __('Reversed') }} {{ $row->reversal->date->format('d/m/Y') }}
                            @elseif ($canManage)
                                <flux:button size="sm" wire:click="startReverse({{ $row->id }})">{{ __('Reverse') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```

- [ ] **Step 8: Route and sidebar.**
  - In `routes/ledger.php`, after the `cash-movements` route add:

```php
    Route::livewire('vat-returns', Ledger\VatReturns::class)->middleware('can:ledger.view')->name('vat-returns');
```

  - In `resources/views/layouts/app/sidebar.blade.php`, after the "Cash and bank movements" item add:

```blade
                            <flux:sidebar.item icon="receipt-percent" :href="route('ledger.vat-returns')" :current="request()->routeIs('ledger.vat-returns')" wire:navigate>{{ __('VAT returns') }}</flux:sidebar.item>
```

- [ ] **Step 9: Arabic.** In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "VAT returns": "إقرارات ضريبة القيمة المضافة",
    "VAT return": "إقرار ضريبة القيمة المضافة",
    "VAT return reversal": "عكس إقرار ضريبة القيمة المضافة",
    "Period from": "الفترة من",
    "Period to": "الفترة إلى",
    "Period": "الفترة",
    "Method": "الطريقة",
    "VAT payable for the period: :a BHD. Record the payment to NBR.": "ضريبة القيمة المضافة المستحقة عن الفترة: :a د.ب. سجّل الدفعة إلى الجهاز الوطني للإيرادات.",
    "VAT refundable for the period: :a BHD. Record the refund from NBR.": "ضريبة القيمة المضافة القابلة للاسترداد عن الفترة: :a د.ب. سجّل الاسترداد من الجهاز الوطني للإيرادات.",
    "Paid to NBR": "مدفوع إلى الجهاز الوطني للإيرادات",
    "Refunded by NBR": "مسترد من الجهاز الوطني للإيرادات",
    "Ledger figure": "رقم دفتر الأستاذ",
    "Paid or refunded": "مدفوع أو مسترد",
    "Record return": "تسجيل الإقرار",
    "Return recorded.": "تم تسجيل الإقرار.",
    "Return reversed.": "تم عكس الإقرار.",
    "This period overlaps the return for :from – :to.": "هذه الفترة تتداخل مع إقرار الفترة :from – :to.",
```

- [ ] **Step 10: Run the tests.**

Run: `php artisan test tests/Feature/Ledger`
Expected: PASS, including `CashMovementTest` against the widened `ReverseMoneyEntry`.

- [ ] **Step 11: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Models/VatReturn.php app/Actions/Ledger/RecordVatReturn.php app/Actions/Ledger/ReverseMoneyEntry.php app/Livewire/Ledger/VatReturns.php resources/views/livewire/ledger/vat-returns.blade.php app/Ledger/Balances.php app/Ledger/Sources.php app/Ledger/Poster.php routes/ledger.php resources/views/layouts/app/sidebar.blade.php tests/Feature/Ledger/VatReturnTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
VAT returns: the period's VAT payable from the ledger, payment to or refund from NBR posted through the Poster, no overlapping periods, reversal once, list with difference

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: The worked example, the access and Arabic sweep, and the full suite

**Files:**
- Create: `tests/Feature/Ledger/MoneyScreensWorkedExampleTest.php`
- Modify: `tests/Feature/Ledger/LedgerAccessTest.php`

**Interfaces:**
- Consumes: everything above; `TrialBalanceReport::sheet(User, array)`; `LedgerHealth::store()`.
- Produces: nothing new. This task proves the worked example (plan rulings, "Worked example") and the access rules.

- [ ] **Step 1: Write the test** `tests/Feature/Ledger/MoneyScreensWorkedExampleTest.php`:

```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\PayDisbursement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Ledger\RecordCashMovement;
use App\Actions\Ledger\RecordVatReturn;
use App\Ledger\LedgerHealth;
use App\Ledger\Poster;
use App\Livewire\Reports\TrialBalanceReport;
use App\Models\Approval;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    LedgerSetting::query()->update(['start_date' => null, 'locked_until' => null, 'health' => null, 'health_checked_at' => null, 'rebuild_status' => null, 'rebuild_from' => null,
        'rebuild_to' => null, 'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null, 'rebuild_run' => null]);
});

test('the money screens worked example: a vendor bill, a transfer, a bank charge, bank interest and a VAT return', function () {
    $f = vendorBillFixture();
    $id = fn (string $code) => (string) LedgerAccount::query()->where('code', $code)->value('id');

    $bill = app(RecordDisbursement::class)->handle($f['finance'], ['purpose' => 'vendor_bill', 'payee_type' => 'vendor', 'payee_id' => $f['vendor']->id,
        'expense_ids' => [$f['companyExpense']->id, $f['ownerExpense']->id], 'method' => 'bank_transfer']);
    app(DecideApproval::class)->handle($f['management'], Approval::query()->latest('id')->firstOrFail(), true);
    app(PayDisbursement::class)->handle($f['finance'], $bill->fresh(), ['method' => 'bank_transfer', 'paid_on' => '2026-10-20']);
    $move = fn (array $data) => app(RecordCashMovement::class)->handle($f['finance'], ['date' => '2026-10-20', ...$data]);
    $move(['kind' => 'transfer', 'from_account_id' => $id('1000'), 'to_account_id' => $id('1010'), 'amount' => '500', 'reference' => 'DEP-1']);
    $move(['kind' => 'bank_charge', 'from_account_id' => $id('1010'), 'amount' => '2.500', 'reference' => 'CHG-OCT']);
    $move(['kind' => 'bank_interest', 'to_account_id' => $id('1010'), 'amount' => '1.200', 'reference' => 'INT-OCT']);
    app(RecordVatReturn::class)->handle($f['finance'], ['period_from' => '2026-10-01', 'period_to' => '2026-10-20', 'date' => '2026-10-20', 'method' => 'bank_transfer',
        'reference' => 'NBR-Q4', 'amount' => '42']);

    expect(ledgerBalances())->toBe([
        1000 => '340.000', 1010 => '76.700', 1020 => '300.000', 2000 => '-400.000', 2100 => '-348.000', 4000 => '-300.000', 4200 => '-20.000',
        4300 => '-1.200', 5000 => '300.000', 5100 => '50.000', 5180 => '2.500',
    ])->and(Poster::pendingCount())->toBe(0)
        ->and(TrialBalanceReport::sheet($f['finance'], ['to' => '2026-10-20'])['rows'])->toBe([
            ['1000', 'Cash on hand', '340.000', ''],
            ['1010', 'Bank', '76.700', ''],
            ['1020', 'Card clearing', '300.000', ''],
            ['2000', 'Tenant deposits held', '', '400.000'],
            ['2100', 'Owner payable', '', '348.000'],
            ['4000', 'Rent income', '', '300.000'],
            ['4200', 'Management fee income', '', '20.000'],
            ['4300', 'Bank interest income', '', '1.200'],
            ['5000', 'Lease rent to owners', '300.000', ''],
            ['5100', 'Maintenance and repairs', '50.000', ''],
            ['5180', 'Bank charges', '2.500', ''],
            ['', 'Total', '1069.200', '1069.200'],
        ]);

    LedgerHealth::store();
    $health = collect(LedgerSetting::current()->health)->keyBy('check');

    expect($health->every(fn (array $r) => $r['ledger'] === $r['app']))->toBeTrue()
        ->and([$health['Vendor payable']['ledger'], $health['Vendor payable']['app']])->toBe([0, 0]);
});
```

- [ ] **Step 2: The access and Arabic sweep.** In `tests/Feature/Ledger/LedgerAccessTest.php`:
  - replace `'ledger.journals' => [], 'ledger.journals.show' => ['journal' => $journal->id], 'ledger.journals.create' => [], 'ledger.accounts' => [], 'ledger.setup' => []];` with:

```php
        'ledger.journals' => [], 'ledger.journals.show' => ['journal' => $journal->id], 'ledger.journals.create' => [], 'ledger.accounts' => [], 'ledger.setup' => [],
        'ledger.cash-movements' => [], 'ledger.vat-returns' => []];
```

  - replace `app_path('Enums/LedgerAccountType.php'), app_path('Reports/Builder/Subjects/JournalLines.php'),` with `app_path('Enums/LedgerAccountType.php'), app_path('Enums/CashMovementKind.php'), app_path('Reports/Builder/Subjects/JournalLines.php'),`;
  - append:

```php
test('the vendor bill strings have Arabic', function () {
    $ar = json_decode((string) file_get_contents(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);
    $keys = ['Vendor bill for :vendor: :list', 'Choose the vendor.', 'Tick at least one expense.', 'Choose expenses from the list.', 'Expense :description is reversed.',
        'Expense :description is already on payment out :n.', 'This expense is on payment out :n. Reject or reverse that payment out first.',
        'The payment cannot be dated before its latest expense (:d).', 'Vendor', 'vendor', "Unpaid expenses: this vendor's work orders first; add any other unpaid expense below.",
        'No unpaid expenses.', 'Add another unpaid expense', 'Add', 'Choose an unpaid expense.', 'Total: :t BHD', 'In payment', 'Money account',
        'Only an asset account can be a money account.', 'Bank charges', 'Bank interest income'];

    expect(array_values(array_diff($keys, array_keys($ar))))->toBe([]);
});
```

- [ ] **Step 3: Run the ledger, payments-out and expense suites.**

Run: `php artisan test tests/Feature/Ledger tests/Feature/Disbursements tests/Feature/Expenses`
Expected: PASS. Finance and Admin both reach the two new pages (200); Management, Leasing and Vendor support get 403.

- [ ] **Step 4: The full suite and the gates.**

Run: `php artisan test` (serially, on rms_test), then `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.
Expected: all green.

- [ ] **Step 5: Commit.**

```bash
git add tests/Feature/Ledger/MoneyScreensWorkedExampleTest.php tests/Feature/Ledger/LedgerAccessTest.php
git commit -m "$(cat <<'EOF'
Ledger money screens: the worked example (trial balance 1069.200, health equal), page access for Finance and Admin, Arabic sweep

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-review notes (done while writing)

- **Spec coverage:**
  - §1 purpose: vendors (Tasks 2–4), cash and bank movements (Task 5), VAT returns (Task 6). Out of scope stays out: there is no partial payment (the total is the expenses' full totals), no reconciliation, no NBR forms, and no approval for movements or returns.
  - §2 paying a vendor:
    - purpose Vendor bill and payee type Vendor (Task 2, ruling 1);
    - the vendor's work-order expenses first, others added by hand, the total their full amounts (Task 3, ruling 11);
    - the `disbursement_expenses` link and one non-rejected, non-reversed payment per expense (Tasks 1–2, ruling 2);
    - approval, cheque, mark-paid and reversal unchanged (Task 2: the `PaymentOut` handler, `PayDisbursement`, `MarkDisbursementPaid` with `vendor_id`, `PaymentOutReversal`);
    - Paid with a link, unpaid again after a reversal or rejection (Task 3, ruling 3);
    - the ledger posting and its reversal (Task 2, ruling 4);
    - the health row (Task 4, ruling 5).
  - §3 cash and bank movements:
    - one form with the three kinds, each with date, amount, reference and note (Task 5);
    - the debit and credit table (ruling 8, `Sources::cashMovement`);
    - money accounts with the Admin flag (Task 1, ruling 6);
    - a transfer needs two different money accounts; reversal once, the exact opposite; filters by date, kind and account (Task 5).
  - §4 VAT return:
    - the period figure from the ledger without VAT return journals (Task 6, ruling 9; the opening journal is also left out);
    - payment with date, method, reference and an amount prefilled with the figure; refund on a debit figure; no overlap; reversal once; the list with figure, amount and difference (Task 6).
  - §5 shared rules:
    - `ledger.manage` records and reverses, `ledger.view` views (Tasks 5–7);
    - audited (ruling 12); lock and dates (ruling 10);
    - Fils; tampered input (the tests in Tasks 2, 3, 5, 6); EN/AR (each task's Arabic, the Task 7 sweep);
    - the two accounts and rules by migration, and `is_money` (Task 1);
    - the source types (Tasks 2, 5, 6).
  - §6 testing, each bullet to a file:
    - vendor bill: `VendorBillTest`, `VendorBillScreensTest`, `VendorPayableHealthTest`;
    - cash movements: `CashMovementTest`;
    - VAT return: `VatReturnTest`;
    - permissions, locked dates, tampered input, Arabic: the same files plus `LedgerAccessTest`, and `MoneyScreensStorageTest` for storage.
- **Spec ambiguities ruled on:**
  - A vendor bill always needs Management approval (no within-limit path), and a vendor is a payee only of a vendor bill (ruling 1).
  - "One active payment per expense" is enforced by row locks over write-once links, not a unique key. An expense on an active bill cannot be reversed, and a bill is never paid before its latest expense (ruling 2).
  - The vendor health row counts automatic journals only. Bills from before the start date count only for what happens to them from the start date (ruling 5).
  - The VAT period figure also leaves out the opening journal; manual journals count. A debit figure records a refund, chosen by the figure's sign, not by the user; a zero figure is a payment (ruling 9).
  - A reference is required on movements and returns. A reversal is its own row, dated on or after the original, with a required reason (rulings 8–9).
  - A VAT period must end by today and may start before the ledger start (ruling 9).
  - The new accounts take 5180 and 4300, or the next free code (ruling 7). Admin may flag any asset account as money, not only new ones (ruling 6).
- **Placeholder scan:** none. Every code step has its full code, every edit names its exact anchor text, and every run step has its command and expected result.
- **Type consistency:**
  - `Disbursement::ACTIVE` is used the same way in `Expense::unpaid()`, `Expense::activePayment()`, `RecordDisbursement::vendorBill()`, `ReverseExpense` and `Expenses\Index`.
  - `Disbursement::payeeName()` is used in both approval summaries, both payment-out views and the tests.
  - `Expense::disbursements()`, `activePayment()` and `unpaid()` are used in Tasks 2–4.
  - Source types `cash_movement`, `cash_movement_reversal`, `vat_return` and `vat_return_reversal` are the same in `TYPES`, `REVERSAL_ROWS`, `label()`, `url()`, `Poster::WATCH`, `assertPostable` calls, `Balances::vatDue` and the tests.
  - `Sources::assertPostable(string, int, string)`, `ReverseMoneyEntry::handle(User, CashMovement|VatReturn, mixed, mixed)` (cash-only in Task 5, widened in Task 6), `RecordCashMovement::handle(User, mixed)`, `RecordVatReturn::handle(User, mixed)` and `Balances::vatDue(string, string): int` match between their tasks and their callers.
  - Error keys match the Livewire properties: `kind`, `from_account_id`, `to_account_id`, `amount`, `date`, `reference`, `note`, `period_from`, `period_to`, `method`, `reverseDate`, `reverseReason`, `is_money`, `form.expense_ids`, `form.payee_id`, `extra`.
  - Routes: `ledger.cash-movements`, `ledger.vat-returns`.
  - Pest: `vendorBillFixture()` (Task 2) is used in Tasks 3, 4 and 7. Test-file closures are local (`$this->bill`, `$this->move`, `$this->vat`, `$this->row`), so no new global helpers clash.
- **Trigger count:** 67 + 2 (`disbursement_expenses_no_update`, `_no_delete`) + 2 (`cash_movements_no_update`, `_no_delete`) + 2 (`vat_returns_no_update`, `_no_delete`) = **73**. `cheques_guard` is dropped and re-created: net 0.
- **Worked example arithmetic** (Dr +, Cr −; BHD; general ledger plan balances at 20/10 first):
  - Before: 1000 +840, 1010 −300, 1020 +300, 2000 −400, 2100 −348, 2200 −80, 2400 −42, 4000 −300, 4200 −20, 5000 +300, 5100 +50.
  - Vendor bill 80: 2200 +80, 1010 −80 → 2200 = −80 + 80 = 0; 1010 = −380.
  - Transfer 500: 1010 +500, 1000 −500 → 1000 = 840 − 500 = 340; 1010 = −380 + 500 = 120.
  - Bank charge 2.5: 5180 +2.5, 1010 −2.5 → 1010 = 117.5.
  - Interest 1.2: 1010 +1.2, 4300 −1.2 → 1010 = 118.7.
  - VAT figure 01/10–20/10: Sara's rent VAT 40 (01/10) + fee VAT 2 (15/10) = 42, credits, so a payment. 2400 +42, 1010 −42 → 2400 = −42 + 42 = 0; 1010 = 118.7 − 42 = 76.7.
  - **Trial balance:**
    - debits 340 + 76.7 + 300 + 300 + 50 + 2.5 = 1069.2;
    - credits 400 + 348 + 300 + 20 + 1.2 = 1069.2.
  - **Profit and loss:**
    - income 300 + 20 + 1.2 = 321.2;
    - expenses 300 + 50 + 2.5 = 352.5;
    - profit −31.3.
  - **Balance sheet:**
    - assets 340 + 76.7 + 300 = 716.7;
    - liabilities 400 + 348 = 748;
    - equity −31.3;
    - 748 − 31.3 = 716.7.
  - **Vendor health:** ledger, on the expense and disbursement journals: −(−50 − 30 + 80) = 0. App: no recorded expense in the window is off a paid bill, so 0; no bills from before the start; = 0.
  - **Ruling 5's cases:**
    - part payment: ledger 80 − 50 = 30; app "T1 tap" 30.
    - pre-start 40 paid: ledger 80 − 40 = 40; app 80 − 0 − 40 + 0 = 40.
    - Reversed: ledger 40 + 40 = 80; app 80 − 0 − 40 + 40 = 80.
    - Missing bill journal: ledger 80; app 30.
  - **VAT refund case:** 15/10–20/10 = fee VAT 2 (Cr) − manual 10 (Dr) = −8, a refund: 1010 +8, 2400 −8.
