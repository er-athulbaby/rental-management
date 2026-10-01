# M4 Owner Finance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pay leased-building owners on schedule, keep each managed owner's ledger to the fil, produce monthly owner statements with the management fee, remit the balance within its limit, and show what each building really earns.

**Architecture:** Builds on M3b (branch `m3b-lifecycle-money-out`, head 4e4353f). The owner ledger is a query over rows that already carry their owner stamp (allocations, deposit movements, expenses, payments out) plus one new write-once table, `owner_charges`. Head-lease payables and owner statements are new tables with their triggers; statements are computed by one class, `OwnerStatementCalculator`, both for drafts and at finalisation. The plan starts by making each line's tax split exact across payments and credit notes, because the owner ledger reads `payment_allocations.tax_amount`.

**Tech Stack:** as M3b; `spatie/simple-excel` (already installed) for the report export.

**Spec:** `docs/superpowers/specs/2026-09-28-rental-management-v1-design.md` — §15 M4 row; §4.4 (bank-change notice), §4.5 (end-date changes, payables), §4.6, §7.5 (remittance and head-lease limits), §7.8, §7.9, §8.3 items 8–9, §9.3 (owner statement PDF), §10 (owner statement, head-lease payments due, building profitability, exports), §12 (1st of month 04:00), §14 flows 6, 7, 14, C2, C4.

## Global Constraints

Everything in the M0–M3b Global Constraints still applies:
- Every monetary column `DECIMAL(12,3)`; PHP money maths in **integer fils** (`App\Support\Fils`); half-up to the fil (§2).
- Actions are the only write path; each top-level Action runs in `DB::transaction(…, attempts: 3)` and re-fetches its rows with `lockForUpdate()` inside the closure; internal Actions assert `DB::transactionLevel() > 0`.
- **Lock order (§7.2):** `customers` → `cheques` → invoice lines (ascending id) → invoices (ascending id) → `payments` → `disbursements`. Owner-side locks: `owner_contracts` → `owner_payables` / `owner_statements` → `disbursements`. Never lock a customer after any of these.
- Two DB users; migrations via `--database=migrator`; one statement per `DB::unprepared()`; triggers `SIGNAL SQLSTATE '45000'`, messages ≤ 128 characters; heredoc tag `DDL` for trigger bodies.
- Every migration that adds or drops a trigger updates `App\Integrity\IntegrityCheck::EXPECTED_TRIGGERS` in the same commit (53 at the start of M4; 55 after Task 2, 57 after Task 4, 59 after Task 5).
- Policies on every route and Livewire action; building scope via `visibleTo()` (`OwnerContract::visibleTo`, `Building::visibleTo`); Livewire ids `#[Locked]`.
- Permissions (§8.1): `finance.view` to see payables, ledgers and statements; `disbursements.manage` for head-lease payments, owner statements (draft review and submit) and remittances; Management (`approvals.decide`) finalises statements (item 8) and approves payments out outside their limits (item 9); `reports.financial` for building profitability.
- Exports are audited (§8.4): `Audit::log('report.exported', …)`.
- Mail and jobs only via `DB::afterCommit`. English UI usable at 375 px; free Flux only; PDFs via `App\Pdf\PdfRenderer`, money columns right-aligned with `table.lines td.num, table.lines th.num, .num { text-align: right; }`.
- Tests: Pest, MySQL as `rms_app`, run serially (never `--parallel`). Finance/Management users need `->withTwoFactor()`. Any test that approves an agreement, records a payment or finalises a statement fakes the local disk (`Storage::fake('local')`).

## Rulings made while planning

1. **Exact line tax (M3a carry-in).** An allocation's tax is the tax its line's allocations *should* carry after it, minus what they already carry: `target(allocated) = allocated ≥ total − credited ? tax − creditedTax : ⌊allocated × (tax − creditedTax) ÷ (total − credited)⌋`, where `creditedTax` is the tax of the line's issued credit notes. A credit note that settles its line (after any de-allocation it causes) takes `tax − creditedTax − allocationTax(after)`; otherwise it keeps M3a's cumulative split. Together, a fully settled line's allocations and credit notes carry exactly its tax, in any order. Reversal rows use the same target, so a reversed payment's rows can net to ±1 fil of tax; the line's total stays exact, which is what the owner ledger and VAT read.
2. **Head-lease amounts:** a partial period is `rent_amount` prorated by §6.2 in one exact step: `divRound((wholeMonths × 365 + days × 12) × rent, 365 × months)` for `actual_365`, `divRound((wholeMonths × 30 + days) × rent, 30 × months)` for `days_30`.
3. **Paid payables are never re-cut.** When a contract's end date moves earlier, only `scheduled` payables are cancelled or replaced; an already-paid payable past the new end stays paid (any recovery is handled outside v1).
4. **Head-lease payments:** paid at once when they pay a `scheduled` payable for exactly its amount; anything else (a second payment of the same payable, or a different amount) goes to Management (§8.3 item 9). The payable becomes `paid` when a payment out for exactly its amount is paid.
5. **Remittance limit:** a remittance is paid at once while `amount ≤ live owner-ledger balance − Σ remittances already waiting for approval or payment`; otherwise it goes to Management (item 9).
6. **Expenses on the owner ledger** post their `total` (the owner bears the cost the company paid, VAT included).
7. **The fee base for `percent_*` contracts is rent and `opening_balance` lines only** (C4 default). `fee_value` for percent types is a percentage with up to 3 decimals.
8. **Nightly drafts** (statements) are attributed to the contract's creator until M5 adds a system user — the same stand-in as M3b's settlements.
9. **Building profitability** counts recorded (not reversed) expenses by `expense_date` and paid (not reversed) head-lease payments by `paid_on`; income is cash by allocation `posted_at`.

## Working environment

```bash
export PHP="/c/Users/ababy/.config/herd/bin/php85/php.exe"
cd /c/Users/ababy/Documents/RentalManagementSystem
# MySQL stops between sessions: if "connection refused", run C:\Users\ababy\.mysql\start-mysql.cmd
```
Branch: `m4-owner-finance` (from `m3b-lifecycle-money-out`).

## Conventions carried over (do not re-create)

`OwnerContract` (`owner()`, `building()`, `units()`, `previous()`, `approvals()`, `visibleTo`, `effectiveOn`, `label()`; casts `type`, `status`, `payment_frequency`, `fee_type`, `deposits_held_by`), `OwnerContractType::{Leased, Managed}`, `OwnerContractStatus`, `FeeType::{PercentCollected, PercentBilled, Fixed}`, `DepositsHeldBy::{Company, Owner}`, `ActivateOwnerContract::handle(OwnerContract)` (has two `ponytail: M4 …` comments), `App\Approvals\OwnerContractTermination` (one `ponytail: M4 …` comment), `CloseEndedOwnerContracts`, `Owner` (`name_en`, `bank_name`, `iban`, `account_name`, `bank_changed_at`, `bank_changed_by`), `Expense` (`owner_contract_id`, `total`, `posted_at`, `reversed_at`, `expense_date`, `status`, `charge_to`, `building_id`, `unit_id`), `Disbursement` (+ `SOURCE_PAYMENT`, `SOURCE_SETTLEMENT`, `source()`), `RecordDisbursement` (purposes `credit_refund`, `deposit_refund`, `other`; `$chequeNow`), `PayDisbursement`, `MarkDisbursementPaid`, `PaymentOutReversal` (private `reopen()`), `DisbursementPurpose::{OwnerRemittance, HeadLease}`, `PaymentAllocation` (`owner_contract_id`, `posted_at`, `tax_amount`), `DepositMovement`, `InvoiceLine` (`owner_contract_id`, `charge_type`, `net`, `credited`), `AllocationTax::between`, `CreditNoteSplit::of`, `ApplyToInvoices` (private `writeLine()`), `ReverseAllocations`, `IssueCreditNote`, `BillingPeriods::for`, `BillingPeriod`, `Proration::{line, partial}`, `Tax::amount`, `NextDocumentNumber` + `NumberSequenceKey::OwnerStatement`, `RequestApproval`, `DecideApproval`, `ApprovalHandler`, `PdfRenderer`, `Document` + `DocumentCategory::GeneratedPdf` + the `documents.download` route, `StoreReceipt` (stored-once PDF job pattern), `Audit::log`, `IntegrityCheck`, test helpers `activeOwnerContract()`, `activeAgreement()`, `issuedInvoice()`, `matrixUser()`, `scheduledEvent()`, `Fils_from()`.

---

### Task 1: Exact tax across payments and credit notes (M3a carry-in)

**Spec:** §7.2 (an allocation's tax share; a fully paid line carries exactly its tax), §6.5 (a credit that uses up the line takes exactly its remaining tax), §7.9 (the owner ledger reads allocations net of VAT); plan ruling 1

**Files:**
- Create: `app/Billing/LineTax.php`, `tests/Feature/Billing/LineTaxTest.php`
- Modify: `app/Actions/Payments/ApplyToInvoices.php`, `app/Actions/Payments/ReverseAllocations.php`, `app/Billing/CreditNoteSplit.php`

**Interfaces:**
- Produces: `LineTax::creditedTax(InvoiceLine $line): int`, `LineTax::allocatedTax(InvoiceLine $line): int`, `LineTax::allocationTarget(InvoiceLine $line, int $allocated): int`, `LineTax::allocationShare(InvoiceLine $line, int $allocatedAfter, int $amount): int` (the tax for one allocation row of `$amount` fils, negative for a reversal)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Billing/LineTaxTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RequestPaymentReversal;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->customer = Customer::factory()->create();
    $this->invoice = issuedInvoice($this->customer, [['net' => '1.000', 'tax' => 'standard']]); // 1.100 incl. 0.100 tax
    $this->line = $this->invoice->lines->sole();
    $this->pay = fn (string $amount) => app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => $amount]);
    $this->credit = function (string $amount) {
        $cn = app(SaveCreditNote::class)->handle($this->finance, $this->invoice->fresh(), null, ['reason' => 'x', 'lines' => [['credited_line_id' => $this->line->id, 'amount' => $amount]]]);
        app(DecideApproval::class)->handle($this->management, app(SubmitCreditNote::class)->handle($this->finance, $cn), true);

        return $cn->fresh();
    };
    // Σ tax carried by allocations and issued credit notes on the line.
    $this->taxCarried = fn () => Fils::fromDecimal((string) PaymentAllocation::where('invoice_line_id', $this->line->id)->sum('tax_amount'))
        + Fils::fromDecimal((string) Invoice::where('type', 'credit_note')->where('status', 'issued')->sum('tax_total'));
});

test('credit first, then the rest paid: the line carries exactly its tax', function () {
    ($this->credit)('0.333');      // 30 fils of tax (cumulative split)
    ($this->pay)('0.767');         // takes the remaining 70, not 69

    expect(($this->taxCarried)())->toBe(100)->and(app(IntegrityCheck::class)->run())->toBe([]);
});

test('paid first, then the rest credited: the credit takes the remaining tax', function () {
    ($this->pay)('0.767');         // 69 fils
    $cn = ($this->credit)('0.333'); // settles the line: 31, not 30

    expect($cn->tax_total)->toBe('0.031')->and(($this->taxCarried)())->toBe(100);
});

test('a credit beyond the balance de-allocates and still adds up exactly', function () {
    ($this->pay)('0.900');         // 81 fils
    ($this->credit)('0.500');      // de-allocates 0.300 (−27 fils), credit takes 46

    expect(Fils::fromDecimal((string) PaymentAllocation::where('invoice_line_id', $this->line->id)->sum('tax_amount')))->toBe(54)
        ->and(($this->taxCarried)())->toBe(100);
});

test('a reversal unwinds the allocations\' tax to zero', function () {
    $payment = ($this->pay)('0.500');
    ($this->pay)('0.600');
    app(DecideApproval::class)->handle($this->management, app(RequestPaymentReversal::class)->handle($this->finance, $payment, 'x'), true);

    $live = PaymentAllocation::where('invoice_line_id', $this->line->id);
    expect(Fils::fromDecimal((string) $live->sum('amount')))->toBe(600)
        ->and(Fils::fromDecimal((string) $live->sum('tax_amount')))->toBe(54); // ⌊600 × 100 / 1100⌋
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Billing/LineTaxTest.php`
Expected: the first three tests FAIL on the totals (99 instead of 100; 0.030 instead of 0.031); the reversal test may already pass.

- [ ] **Step 3: Implement**

Create `app/Billing/LineTax.php`:
```php
<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\InvoiceLine;
use App\Models\PaymentAllocation;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;

/**
 * Plan ruling 1: a line's tax is shared between its credit notes and its allocations so that a settled line carries
 * exactly its tax whatever the order. Allocations aim at a target computed on what the credit notes left; each row
 * takes the difference from what allocations already carry, so rounding never accumulates.
 */
final class LineTax
{
    public static function creditedTax(InvoiceLine $line): int
    {
        return Fils::fromDecimal((string) (DB::table('invoice_lines as cl')
            ->join('invoices as cn', 'cn.id', '=', 'cl.invoice_id')
            ->where('cl.credited_line_id', $line->id)
            ->where('cn.type', InvoiceType::CreditNote->value)->where('cn.status', InvoiceStatus::Issued->value)
            ->sum('cl.tax_amount') ?: '0'));
    }

    public static function allocatedTax(InvoiceLine $line): int
    {
        return Fils::fromDecimal((string) (PaymentAllocation::query()->where('invoice_line_id', $line->id)->sum('tax_amount') ?: '0'));
    }

    /** The tax allocations totalling $allocated should carry on this line, given its credit notes so far. */
    public static function allocationTarget(InvoiceLine $line, int $allocated): int
    {
        $baseTotal = Fils::fromDecimal($line->total) - Fils::fromDecimal((string) $line->credited);
        $baseTax = Fils::fromDecimal($line->tax_amount) - self::creditedTax($line);

        if ($allocated <= 0 || $baseTotal <= 0 || $baseTax <= 0) {
            return 0;
        }

        return $allocated >= $baseTotal ? $baseTax : intdiv($allocated * $baseTax, $baseTotal);
    }

    /** Tax for one allocation row of $amount (negative for a reversal) that brings the line to $allocatedAfter. */
    public static function allocationShare(InvoiceLine $line, int $allocatedAfter, int $amount): int
    {
        $share = self::allocationTarget($line, $allocatedAfter) - self::allocatedTax($line);

        // The payment_allocations CHECK keeps a row's tax between 0 and its amount (same sign); a rare clamp is
        // absorbed by the next row on the line.
        return $amount > 0 ? max(0, min($amount, $share)) : max($amount, min(0, $share));
    }
}
```
In `app/Actions/Payments/ApplyToInvoices.php` `writeLine()`, replace the `AllocationTax::between(...)` line with:
```php
        $tax = LineTax::allocationShare($line, $before + $share, $share);
```
In `app/Actions/Payments/ReverseAllocations.php`, replace its `AllocationTax::between($before, $after, …)` line with:
```php
            $tax = LineTax::allocationShare($line, $after, -$cut['amount']);
```
(both import `App\Billing\LineTax`; remove the now-unused `AllocationTax` imports.) Both Actions already save the line's new `allocated` after each row, and `allocatedTax()` reads the rows written earlier in the same transaction, so several rows on one line stay exact.

Replace `app/Billing/CreditNoteSplit.php` with:
```php
<?php

namespace App\Billing;

use App\Models\InvoiceLine;
use App\Support\Fils;

/**
 * Spec §6.5, plan ruling 1: a credit of $amount (gross) split into net and tax. A credit that settles the line — after
 * the de-allocation it causes (IssueCreditNote step 2) — takes exactly the tax not carried by the allocations left and
 * the earlier credit notes. Otherwise the cumulative split on the line's credited amount.
 */
final class CreditNoteSplit
{
    /** @return array{net: int, tax: int} */
    public static function of(int $amount, InvoiceLine $line): array
    {
        $total = Fils::fromDecimal($line->total);
        $lineTax = Fils::fromDecimal($line->tax_amount);
        $credited = Fils::fromDecimal((string) $line->credited);
        $allocated = Fils::fromDecimal((string) $line->allocated);
        $allocatedAfter = min($allocated, $total - $credited - $amount);

        if ($allocatedAfter + $credited + $amount >= $total) {
            $allocationTaxAfter = $allocatedAfter === $allocated
                ? LineTax::allocatedTax($line)
                : LineTax::allocationTarget($line, $allocatedAfter); // what ReverseAllocations will leave
            $tax = $lineTax - LineTax::creditedTax($line) - $allocationTaxAfter;
        } else {
            $tax = AllocationTax::between($credited, $credited + $amount, $lineTax, $total);
        }

        $tax = max(0, min($amount, $tax));

        return ['net' => $amount - $tax, 'tax' => $tax];
    }
}
```
`IssueCreditNote` re-checks the split at approval with the same function before it de-allocates, so the draft's tax and the issued tax agree; `ReverseAllocations` then leaves exactly `allocationTarget($line, $allocatedAfter)`, which is what the split assumed.

- [ ] **Step 4: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Billing tests/Feature/Payments tests/Feature/Invoices tests/Feature/Agreements tests/Feature/Integrity
"$PHP" artisan test
```
Expected: every test passes. M3a's split tests keep their figures (3 030 / 3 030 / 3 940; 6.970 for the remaining credit) because those sequences have no credit before payment or no payment before credit.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Share each line's tax exactly between payments and credit notes

Allocations now aim at the tax their line should carry after them,
given its credit notes, and a credit note that settles its line takes
exactly the tax left. A settled line carries its tax to the fil in any
order, which the owner ledger relies on.
EOF
```
(End the message with your Co-Authored-By trailer.)

---

### Task 2: Head-lease payables

**Spec:** §7.8 (`owner_payables`: owner_contract_id, period_start, period_end, due_date = period start, amount, status scheduled | paid | cancelled, disbursement_id; generated on contract activation with the §6.2 period rules, anchor = the contract's start_date; a full period = `rent_amount`; a partial period prorates `rent_amount` by the §6.2 basis using exact arithmetic, rounded once per payable; a contract end-date change cancels later payables and replaces a straddling one), §4.5 (approving a successor sets the predecessor's end_date to the day before; approving an early termination sets end_date = terminated_on; payables after the new end are cancelled and a straddling one replaced by a prorated one), §8.5 (no DELETE on owner_payables), plan rulings 2–3

**Files:**
- Create: `app/Enums/OwnerPayableStatus.php`, `database/migrations/2026_11_01_000100_create_owner_payables_table.php`, `app/Models/OwnerPayable.php`, `app/Billing/HeadLeaseAmount.php`, `app/Actions/OwnerContracts/{GenerateOwnerPayables,RescheduleOwnerPayables}.php`, `tests/Feature/OwnerContracts/OwnerPayablesTest.php`, `tests/Unit/Billing/HeadLeaseAmountTest.php`
- Modify: `app/Actions/OwnerContracts/ActivateOwnerContract.php`, `app/Approvals/OwnerContractTermination.php`, `app/Models/OwnerContract.php`, `app/Livewire/OwnerContracts/Show.php`, `resources/views/livewire/owner-contracts/show.blade.php`, `app/Integrity/IntegrityCheck.php`

**Interfaces:**
- Produces:
  - `OwnerPayableStatus` (`Scheduled`, `Paid`, `Cancelled`; `label()`)
  - `OwnerPayable` (`contract()`, `disbursement()`, `replacedBy()`), `OwnerContract::payables()`
  - `HeadLeaseAmount::for(BillingPeriod $period, int $rentFils, int $months, ProrationBasis $basis): int`
  - `GenerateOwnerPayables::handle(OwnerContract $contract): int` (internal; rows created)
  - `RescheduleOwnerPayables::handle(OwnerContract $locked, CarbonImmutable $newEnd): void` (internal)

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Billing/HeadLeaseAmountTest.php`:
```php
<?php

use App\Billing\BillingPeriod;
use App\Billing\HeadLeaseAmount;
use App\Enums\ProrationBasis;
use Carbon\CarbonImmutable;

$period = fn (string $from, string $to, bool $regular) => new BillingPeriod(CarbonImmutable::parse($from), CarbonImmutable::parse($to), $regular);

test('a full period is the rent amount', function () use ($period) {
    expect(HeadLeaseAmount::for($period('2026-01-01', '2026-03-31', true), 3_000_000, 3, ProrationBasis::Actual365))->toBe(3_000_000);
});

test('a partial period prorates the rent in one exact step', function () use ($period) {
    // Two whole months of a quarter: exactly two thirds.
    expect(HeadLeaseAmount::for($period('2026-07-01', '2026-08-31', false), 3_000_000, 3, ProrationBasis::Actual365))->toBe(2_000_000);
    // One month and 15 days: (365 + 180) × 3 000 000 / 1 095 = 1 493 150.68 → 1 493 151.
    expect(HeadLeaseAmount::for($period('2026-04-01', '2026-05-15', false), 3_000_000, 3, ProrationBasis::Actual365))->toBe(1_493_151);
    // 30-day basis: (30 + 15) × 3 000 000 / 90 = 1 500 000.
    expect(HeadLeaseAmount::for($period('2026-04-01', '2026-05-15', false), 3_000_000, 3, ProrationBasis::Days30))->toBe(1_500_000);
});
```
Create `tests/Feature/OwnerContracts/OwnerPayablesTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\EnsureNumberSequences;
use App\Actions\OwnerContracts\RequestOwnerContractTermination;
use App\Actions\OwnerContracts\SaveOwnerContract;
use App\Actions\OwnerContracts\SubmitOwnerContract;
use App\Enums\RoleName;
use App\Livewire\OwnerContracts\Show;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Owner;
use App\Models\OwnerPayable;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-01-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->building = Building::factory()->create();
    Unit::factory()->for($this->building)->count(2)->create();
    $this->lease = function (array $over = []) {
        $draft = app(SaveOwnerContract::class)->handle($this->finance, null, [
            'owner_id' => Owner::factory()->create()->id, 'building_id' => $this->building->id, 'type' => 'leased',
            'start_date' => '2026-01-01', 'end_date' => '2026-08-31', 'rent_amount' => '3000.000', 'payment_frequency' => 'quarterly',
            'unit_ids' => $this->building->units()->pluck('id')->all(), ...$over,
        ]);
        app(DecideApproval::class)->handle($this->management, app(SubmitOwnerContract::class)->handle($this->finance, $draft), true);

        return $draft->fresh();
    };
});

test('§14 flow 7: activating a leased contract schedules its payments with proration', function () {
    $contract = ($this->lease)();

    $rows = OwnerPayable::where('owner_contract_id', $contract->id)->orderBy('period_start')->get();
    expect($rows->map(fn ($p) => [$p->period_start->toDateString(), $p->period_end->toDateString(), $p->amount, $p->status->value])->all())->toBe([
        ['2026-01-01', '2026-03-31', '3000.000', 'scheduled'],
        ['2026-04-01', '2026-06-30', '3000.000', 'scheduled'],
        ['2026-07-01', '2026-08-31', '2000.000', 'scheduled'],
    ])->and($rows->first()->due_date->toDateString())->toBe('2026-01-01');
});

test('an early termination cancels later payables and replaces the straddling one', function () {
    $contract = ($this->lease)();
    $approval = app(RequestOwnerContractTermination::class)->handle($this->finance, $contract, '2026-05-15', 'Owner sold the building');
    app(DecideApproval::class)->handle($this->management, $approval, true);

    $live = OwnerPayable::where('owner_contract_id', $contract->id)->where('status', 'scheduled')->orderBy('period_start')->get();
    $q2 = OwnerPayable::where('owner_contract_id', $contract->id)->where('period_start', '2026-04-01')->where('status', 'cancelled')->sole();
    expect($live->map(fn ($p) => [$p->period_start->toDateString(), $p->period_end->toDateString(), $p->amount])->all())->toBe([
        ['2026-01-01', '2026-03-31', '3000.000'],
        ['2026-04-01', '2026-05-15', '1493.151'],
    ])->and($q2->replaced_by_payable_id)->toBe($live->last()->id)
        ->and(OwnerPayable::where('owner_contract_id', $contract->id)->where('period_start', '2026-07-01')->sole()->status->value)->toBe('cancelled');
});

test('a successor ends its predecessor the day before and re-cuts its payables', function () {
    $first = ($this->lease)(['end_date' => '2026-12-31']);
    $successor = ($this->lease)(['owner_id' => $first->owner_id, 'previous_contract_id' => $first->id, 'start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'rent_amount' => '3300.000']);

    expect($first->fresh()->end_date->toDateString())->toBe('2026-05-31')
        ->and(OwnerPayable::where('owner_contract_id', $first->id)->where('status', 'scheduled')->orderBy('period_start')->pluck('period_end')->map->toDateString()->all())->toBe(['2026-03-31', '2026-05-31'])
        ->and(OwnerPayable::where('owner_contract_id', $successor->id)->count())->toBe(4);
});

test('managed contracts get no payables; payables are never deleted and their terms never change', function () {
    $contract = ($this->lease)();
    $id = OwnerPayable::where('owner_contract_id', $contract->id)->value('id');

    expect(fn () => DB::table('owner_payables')->where('id', $id)->delete())->toThrow(QueryException::class, 'owner_payables cannot be deleted');
    expect(fn () => DB::table('owner_payables')->where('id', $id)->update(['amount' => '1.000']))->toThrow(QueryException::class, 'owner_payables: terms are frozen');
    expect(fn () => DB::table('owner_payables')->where('id', $id)->update(['status' => 'paid']))->toThrow(QueryException::class); // paid needs its payment out
});

test('the contract page lists the payables', function () {
    $contract = ($this->lease)();

    Livewire::actingAs($this->finance)->test(Show::class, ['contract' => $contract])->assertSee('Head-lease payments')->assertSee('2000.000');
});
```
Check `SaveOwnerContract`'s input keys (`unit_ids`, `rent_amount`, `payment_frequency`, `previous_contract_id`) and `RequestOwnerContractTermination::handle`'s signature in M1's code before running; adapt the test's arrays, not the Actions.

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Unit/Billing/HeadLeaseAmountTest.php tests/Feature/OwnerContracts/OwnerPayablesTest.php`
Expected: FAIL — `Class "App\Billing\HeadLeaseAmount" not found`.

- [ ] **Step 3: Enum, migration, model, amount**

Create `app/Enums/OwnerPayableStatus.php`:
```php
<?php

namespace App\Enums;

enum OwnerPayableStatus: string
{
    case Scheduled = 'scheduled';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
```
Create `database/migrations/2026_11_01_000100_create_owner_payables_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_payables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_contract_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->decimal('amount', 12, 3);
            $table->string('status', 20)->default('scheduled');
            $table->foreignId('disbursement_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('replaced_by_payable_id')->nullable()->constrained('owner_payables')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'due_date']);
            $table->index(['owner_contract_id', 'period_start']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE owner_payables
                ADD CONSTRAINT owner_payables_status_chk CHECK (status IN ('scheduled', 'paid', 'cancelled')),
                ADD CONSTRAINT owner_payables_amount_chk CHECK (amount > 0),
                ADD CONSTRAINT owner_payables_dates_chk CHECK (period_end >= period_start AND due_date = period_start),
                ADD CONSTRAINT owner_payables_paid_chk CHECK ((status = 'paid') = (disbursement_id IS NOT NULL))
            SQL);

        DB::unprepared("CREATE TRIGGER owner_payables_no_delete BEFORE DELETE ON owner_payables FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_payables cannot be deleted'");

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER owner_payables_guard BEFORE UPDATE ON owner_payables FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'scheduled' AND NEW.status IN ('paid', 'cancelled'))
                    OR (OLD.status = 'paid' AND NEW.status = 'scheduled')) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_payables: status change not allowed';
                END IF;
                IF NOT (NEW.owner_contract_id <=> OLD.owner_contract_id AND NEW.period_start <=> OLD.period_start
                    AND NEW.period_end <=> OLD.period_end AND NEW.due_date <=> OLD.due_date AND NEW.amount <=> OLD.amount) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_payables: terms are frozen';
                END IF;
                IF OLD.replaced_by_payable_id IS NOT NULL AND NOT (NEW.replaced_by_payable_id <=> OLD.replaced_by_payable_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_payables: the replacement is set once';
                END IF;
            END
            DDL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS owner_payables_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS owner_payables_no_delete');
        Schema::dropIfExists('owner_payables');
    }
};
```
`EXPECTED_TRIGGERS` 53 → **55** (comment: `+ 2 (owner payables)`). The `paid → scheduled` transition is for a reversed payment out (Task 3).

Create `app/Models/OwnerPayable.php`:
```php
<?php

namespace App\Models;

use App\Enums\OwnerPayableStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec §7.8.
 *
 * @property int $id
 * @property int $owner_contract_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $due_date
 * @property string $amount
 * @property OwnerPayableStatus $status
 * @property int|null $disbursement_id
 * @property int|null $replaced_by_payable_id
 */
class OwnerPayable extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'due_date' => 'immutable_date',
            'amount' => 'decimal:3',
            'status' => OwnerPayableStatus::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'disbursement_id', 'replaced_by_payable_id'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<OwnerContract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(OwnerContract::class, 'owner_contract_id');
    }

    /** @return BelongsTo<Disbursement, $this> */
    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(Disbursement::class);
    }

    /** @return BelongsTo<OwnerPayable, $this> */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_payable_id');
    }
}
```
In `app/Models/OwnerContract.php` add:
```php
    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<OwnerPayable, $this> */
    public function payables(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OwnerPayable::class);
    }
```
(import `HasMany` rather than fully qualifying.)

Create `app/Billing/HeadLeaseAmount.php`:
```php
<?php

namespace App\Billing;

use App\Enums\ProrationBasis;
use App\Support\Fils;

/** Spec §7.8, plan ruling 2: a payable's amount; a partial period is prorated in one exact step and rounded once. */
final class HeadLeaseAmount
{
    public static function for(BillingPeriod $period, int $rentFils, int $months, ProrationBasis $basis): int
    {
        if ($period->regular) {
            return $rentFils;
        }

        $from = $period->start->startOfDay();
        $to = $period->end->startOfDay();
        $whole = 0;
        while ($from->addMonthsNoOverflow($whole + 1)->subDay()->lessThanOrEqualTo($to)) {
            $whole++;
        }
        $rest = $from->addMonthsNoOverflow($whole);
        $days = $rest->greaterThan($to) ? 0 : (int) $rest->diffInDays($to, true) + 1;

        return match ($basis) {
            ProrationBasis::Actual365 => Fils::divRound(($whole * 365 + $days * 12) * $rentFils, 365 * $months),
            ProrationBasis::Days30 => Fils::divRound(($whole * 30 + $days) * $rentFils, 30 * $months),
        };
    }
}
```
The whole-months loop is the same rule as `Proration::partial`; a one-month-frequency contract gives the same amounts as rent invoices.

- [ ] **Step 4: Generate and reschedule**

Create `app/Actions/OwnerContracts/GenerateOwnerPayables.php`:
```php
<?php

namespace App\Actions\OwnerContracts;

use App\Billing\BillingPeriods;
use App\Billing\HeadLeaseAmount;
use App\Enums\OwnerContractType;
use App\Enums\OwnerPayableStatus;
use App\Models\CompanySetting;
use App\Models\OwnerContract;
use App\Models\OwnerPayable;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Spec §7.8: a leased contract's payments to its owner, one per payment period, anchored on its start date. */
final class GenerateOwnerPayables
{
    public function handle(OwnerContract $contract): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('GenerateOwnerPayables must run inside the caller\'s transaction.');
        }
        if ($contract->type !== OwnerContractType::Leased || $contract->payment_frequency === null || $contract->rent_amount === null) {
            return 0;
        }

        $months = $contract->payment_frequency->months();
        $rent = Fils::fromDecimal($contract->rent_amount);
        $basis = CompanySetting::current()->proration_basis;
        $count = 0;

        foreach (BillingPeriods::for($contract->start_date, $contract->end_date, $contract->payment_frequency, null) as $period) {
            (new OwnerPayable)->forceFill([
                'owner_contract_id' => $contract->id,
                'period_start' => $period->start->toDateString(),
                'period_end' => $period->end->toDateString(),
                'due_date' => $period->start->toDateString(),
                'amount' => Fils::toDecimal(HeadLeaseAmount::for($period, $rent, $months, $basis)),
                'status' => OwnerPayableStatus::Scheduled,
            ])->save();
            $count++;
        }

        return $count;
    }
}
```
Create `app/Actions/OwnerContracts/RescheduleOwnerPayables.php`:
```php
<?php

namespace App\Actions\OwnerContracts;

use App\Billing\BillingPeriod;
use App\Billing\HeadLeaseAmount;
use App\Enums\OwnerPayableStatus;
use App\Models\CompanySetting;
use App\Models\OwnerContract;
use App\Models\OwnerPayable;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Spec §4.5, §7.8, plan ruling 3: after a leased contract's end date moves earlier, scheduled payables starting after it
 * are cancelled and a scheduled one straddling it is replaced by a prorated one. Paid payables stay as they are.
 */
final class RescheduleOwnerPayables
{
    public function handle(OwnerContract $locked, CarbonImmutable $newEnd): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('RescheduleOwnerPayables must run inside the caller\'s transaction.');
        }
        if ($locked->payment_frequency === null || $locked->rent_amount === null) {
            return;
        }

        $end = $newEnd->toDateString();
        $months = $locked->payment_frequency->months();
        $rent = Fils::fromDecimal($locked->rent_amount);
        $basis = CompanySetting::current()->proration_basis;

        $affected = OwnerPayable::query()->where('owner_contract_id', $locked->id)->where('status', OwnerPayableStatus::Scheduled)
            ->where('period_end', '>', $end)->orderBy('id')->lockForUpdate()->get();

        foreach ($affected as $payable) {
            $payable->forceFill(['status' => OwnerPayableStatus::Cancelled])->save();
            if ($payable->period_start->toDateString() > $end) {
                continue; // wholly after the new end
            }

            $replacement = (new OwnerPayable)->forceFill([
                'owner_contract_id' => $locked->id,
                'period_start' => $payable->period_start->toDateString(),
                'period_end' => $end,
                'due_date' => $payable->period_start->toDateString(),
                'amount' => Fils::toDecimal(HeadLeaseAmount::for(new BillingPeriod($payable->period_start, $newEnd, false), $rent, $months, $basis)),
                'status' => OwnerPayableStatus::Scheduled,
            ]);
            $replacement->save();
            $payable->forceFill(['replaced_by_payable_id' => $replacement->id])->save();
        }
    }
}
```
In `app/Actions/OwnerContracts/ActivateOwnerContract.php` inject `GenerateOwnerPayables $payables` and `RescheduleOwnerPayables $reschedule`; replace the first `ponytail: M4 cancels the predecessor's …` comment with `$this->reschedule->handle($previous, $dayBefore);` (right after the predecessor's end date is saved) and the second `ponytail: M4 generates …` comment with `$this->payables->handle($contract);`. In `app/Approvals/OwnerContractTermination.php` inject `RescheduleOwnerPayables` through a constructor and replace its ponytail comment with `$this->reschedule->handle($contract, $on);`.

Check that `PaymentFrequency` (used by owner contracts) has `months()` — the agreements' `PaymentFrequency` enum has it; owner contracts cast `payment_frequency` to the same enum.

- [ ] **Step 5: Show them on the contract page**

In `app/Livewire/OwnerContracts/Show.php` `render()` add `'payables' => $contract->type->value === 'leased' && $this->actor()->can('finance.view') ? $contract->payables()->orderBy('period_start')->orderBy('id')->get() : collect(),`. In `resources/views/livewire/owner-contracts/show.blade.php`, before the documents panel:
```blade
    @if ($payables->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg">{{ __('Head-lease payments') }}</flux:heading>
            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Period') }}</flux:table.column>
                        <flux:table.column>{{ __('Due') }}</flux:table.column>
                        <flux:table.column>{{ __('Amount') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($payables as $p)
                            <flux:table.row :key="'op-'.$p->id">
                                <flux:table.cell class="whitespace-nowrap">{{ $p->period_start->format('d/m/Y') }} – {{ $p->period_end->format('d/m/Y') }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">{{ $p->due_date->format('d/m/Y') }}</flux:table.cell>
                                <flux:table.cell class="text-end tabular-nums">{{ $p->amount }}</flux:table.cell>
                                <flux:table.cell><flux:badge size="sm">{{ $p->status->label() }}</flux:badge></flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    @endif
```
(Task 3 adds the payment link in the status cell.)

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Unit tests/Feature/OwnerContracts tests/Feature/Approvals tests/Feature/Integrity
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Schedule head-lease payments to owners

Activating a leased contract schedules one payment per period from its
start date, the last one prorated exactly. An early termination or a
successor cancels the payments after the new end and re-cuts the one
that straddles it; paid ones stay.
EOF
```

---
### Task 3: Paying head-lease payables

**Spec:** §7.5 (a scheduled head-lease payable is paid directly, the payee copied from the source, exactly once and for its amount; anything else → `pending_approval` → Management (§8.3 item 9) → `approved` → Finance pays), §7.3 (a payment-out reversal returns an owner payable to `scheduled`, `disbursement_id` cleared), §7.2 lock order, §10 (head-lease payments due), §14 flow 7 ("paid exactly once via a disbursement") and flow 14 ("a second payment of the same head-lease payable requires approval"), plan ruling 4

**Files:**
- Create: `app/Actions/Disbursements/LockOwnerSource.php`, `app/Livewire/OwnerPayables/Index.php`, `resources/views/livewire/owner-payables/index.blade.php`, `tests/Feature/Disbursements/HeadLeasePaymentTest.php`
- Modify: `app/Actions/Disbursements/{RecordDisbursement,MarkDisbursementPaid,PayDisbursement}.php`, `app/Approvals/PaymentOutReversal.php`, `app/Models/Disbursement.php`, `routes/property.php`, `resources/views/layouts/app/sidebar.blade.php`, `resources/views/livewire/owner-contracts/show.blade.php`

**Interfaces:**
- Consumes: `OwnerPayable`, `OwnerPayableStatus`, `RescheduleOwnerPayables::handle(OwnerContract, CarbonImmutable)` (Task 2)
- Produces:
  - `RecordDisbursement::handle($actor, ['purpose' => 'head_lease', 'owner_payable_id' => int, 'amount', 'method', 'paid_on', 'reference'?, cheque fields])`
  - `Disbursement::SOURCE_PAYABLE = 'owner_payable'`
  - `LockOwnerSource::handle(Disbursement $out): ?OwnerContract` (internal; locks the owner contract and then the payable, before any disbursement lock; Task 7 adds statements)
  - route `owner-payables.index` (`head-lease-payments`, `can:finance.view`)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Disbursements/HeadLeasePaymentTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\PayDisbursement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\EnsureNumberSequences;
use App\Actions\OwnerContracts\RequestOwnerContractTermination;
use App\Actions\OwnerContracts\SaveOwnerContract;
use App\Actions\OwnerContracts\SubmitOwnerContract;
use App\Enums\RoleName;
use App\Livewire\OwnerPayables\Index;
use App\Models\Approval;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Owner;
use App\Models\OwnerPayable;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-01-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $building = Building::factory()->create();
    $unit = Unit::factory()->for($building)->create();
    $draft = app(SaveOwnerContract::class)->handle($this->finance, null, [
        'owner_id' => Owner::factory()->create()->id, 'building_id' => $building->id, 'type' => 'leased',
        'start_date' => '2026-01-01', 'end_date' => '2026-08-31', 'rent_amount' => '3000.000', 'payment_frequency' => 'quarterly', 'unit_ids' => [$unit->id],
    ]);
    app(DecideApproval::class)->handle($this->management, app(SubmitOwnerContract::class)->handle($this->finance, $draft), true);
    $this->contract = $draft->fresh();
    $this->q1 = OwnerPayable::where('owner_contract_id', $this->contract->id)->orderBy('period_start')->first();
    $this->pay = fn (string $amount, ?OwnerPayable $payable = null) => app(RecordDisbursement::class)->handle($this->finance, [
        'purpose' => 'head_lease', 'owner_payable_id' => ($payable ?? $this->q1)->id, 'amount' => $amount, 'method' => 'bank_transfer', 'paid_on' => '2026-01-05',
    ]);
});

test('§14 flow 7: a scheduled payable is paid once, for its amount, to the contract\'s owner', function () {
    $out = ($this->pay)('3000.000');

    expect($out->status->value)->toBe('paid')->and($out->number)->not->toBeNull()
        ->and($out->payee_id)->toBe($this->contract->owner_id)->and($out->source_type)->toBe('owner_payable')
        ->and($this->q1->fresh()->status->value)->toBe('paid')->and($this->q1->fresh()->disbursement_id)->toBe($out->id);
});

test('§14 flow 14: a second payment of the same payable, or a different amount, needs approval', function () {
    $first = ($this->pay)('3000.000');
    $second = ($this->pay)('3000.000');
    $q2 = OwnerPayable::where('owner_contract_id', $this->contract->id)->where('period_start', '2026-04-01')->sole();
    $odd = ($this->pay)('2999.000', $q2);

    expect($second->status->value)->toBe('pending_approval')->and($odd->status->value)->toBe('pending_approval')
        ->and(Approval::where('approvable_id', $second->id)->where('action', 'payment_out.approve')->exists())->toBeTrue()
        ->and($q2->fresh()->status->value)->toBe('scheduled');

    // While the odd one waits, even the exact amount goes to approval.
    expect(($this->pay)('3000.000', $q2)->status->value)->toBe('pending_approval');

    // Approved and paid: the first payable stays paid by its first payment.
    app(DecideApproval::class)->handle($this->management, Approval::where('approvable_id', $second->id)->sole(), true);
    app(PayDisbursement::class)->handle($this->finance, $second->fresh(), ['method' => 'bank_transfer', 'paid_on' => '2026-01-05']);
    expect($this->q1->fresh()->disbursement_id)->toBe($first->id);
});

test('a cancelled payable cannot be paid', function () {
    app(DecideApproval::class)->handle($this->management, app(RequestOwnerContractTermination::class)->handle($this->finance, $this->contract, '2026-03-31', 'Sold'), true);
    $q3 = OwnerPayable::where('owner_contract_id', $this->contract->id)->where('period_start', '2026-07-01')->sole();

    expect(fn () => ($this->pay)('2000.000', $q3))->toThrow(ValidationException::class, 'cancelled');
});

test('reversing the payment out reopens the payable, or cancels it when the contract now ends earlier', function () {
    $q2 = OwnerPayable::where('owner_contract_id', $this->contract->id)->where('period_start', '2026-04-01')->sole();
    $out = ($this->pay)('3000.000', $q2);
    $reverse = fn ($o) => app(DecideApproval::class)->handle($this->management, app(RequestDisbursementReversal::class)->handle($this->finance, $o, 'Wrong account'), true);

    $reverse($out);
    expect($q2->fresh()->status->value)->toBe('scheduled')->and($q2->fresh()->disbursement_id)->toBeNull();

    $again = ($this->pay)('3000.000', $q2);
    app(DecideApproval::class)->handle($this->management, app(RequestOwnerContractTermination::class)->handle($this->finance, $this->contract, '2026-05-15', 'Sold'), true);
    expect($q2->fresh()->status->value)->toBe('paid'); // plan ruling 3: paid payables are not re-cut

    $reverse($again);
    $live = OwnerPayable::where('owner_contract_id', $this->contract->id)->where('period_start', '2026-04-01')->where('status', 'scheduled')->sole();
    expect($q2->fresh()->status->value)->toBe('cancelled')->and($live->period_end->toDateString())->toBe('2026-05-15')->and($live->amount)->toBe('1493.151');
});

test('the head-lease payments due page lists scheduled payables and pays one', function () {
    Livewire::actingAs($this->finance)->test(Index::class)
        ->assertSee($this->contract->number)->assertSee('3000.000')
        ->call('startPaying', $this->q1->id)
        ->set('form.method', 'bank_transfer')->set('form.paid_on', '2026-01-05')
        ->call('pay')->assertHasNoErrors();

    expect($this->q1->fresh()->status->value)->toBe('paid');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Disbursements/HeadLeasePaymentTest.php`
Expected: FAIL — `purpose` validation error ("The selected purpose is invalid").

- [ ] **Step 3: Record and mark paid**

In `app/Models/Disbursement.php` add `public const string SOURCE_PAYABLE = 'owner_payable';` and the `source()` arm `self::SOURCE_PAYABLE => OwnerPayable::query()->find($this->source_id),`.

Create `app/Actions/Disbursements/LockOwnerSource.php`:
```php
<?php

namespace App\Actions\Disbursements;

use App\Models\Disbursement;
use App\Models\OwnerContract;
use App\Models\OwnerPayable;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Spec §7.2: a payment out to an owner locks owner_contracts, then its payable, before any disbursements row. */
final class LockOwnerSource
{
    public function handle(Disbursement $out): ?OwnerContract
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('LockOwnerSource must run inside the caller\'s transaction.');
        }

        $contractId = match ($out->source_type) {
            Disbursement::SOURCE_PAYABLE => OwnerPayable::query()->whereKey($out->source_id)->value('owner_contract_id'),
            default => null,
        };
        if ($contractId === null) {
            return null;
        }

        $contract = OwnerContract::query()->lockForUpdate()->findOrFail($contractId);
        if ($out->source_type === Disbursement::SOURCE_PAYABLE) {
            OwnerPayable::query()->lockForUpdate()->findOrFail($out->source_id);
        }

        return $contract;
    }
}
```
In `app/Actions/Disbursements/RecordDisbursement.php`:
- add `DisbursementPurpose::HeadLease->value` to the `purpose` rule's list and to the `$chequeNow` purposes;
- add the rule `'owner_payable_id' => ['required_if:purpose,head_lease', 'nullable', 'integer', Rule::exists('owner_payables', 'id')],`;
- add the match arm `DisbursementPurpose::HeadLease->value => $this->headLease($actor, $v, $amount),`;
- update the class docblock to name head-lease payables among the sources;
- add the method:
```php
    /** @param  array<string, mixed>  $v */
    private function headLease(User $actor, array $v, int $amount): Disbursement
    {
        $payable = OwnerPayable::query()->findOrFail((int) $v['owner_payable_id']);
        $contract = OwnerContract::query()->lockForUpdate()->findOrFail($payable->owner_contract_id); // owner-side first lock (spec §7.2)
        if (! OwnerContract::visibleTo($actor)->whereKey($contract->id)->exists()) {
            throw new AuthorizationException;
        }
        $payable = OwnerPayable::query()->lockForUpdate()->findOrFail($payable->id);
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
```
(imports: `App\Enums\OwnerPayableStatus`, `App\Models\OwnerContract`, `App\Models\OwnerPayable`.)

In `app/Actions/Disbursements/MarkDisbursementPaid.php`, after the deposit-refund block:
```php
        // Spec §7.5, plan ruling 4: the payable becomes paid when a payment out for exactly its amount is paid. The
        // caller locked the payable before this disbursement (LockOwnerSource / RecordDisbursement).
        if ($locked->purpose === DisbursementPurpose::HeadLease) {
            $payable = OwnerPayable::query()->lockForUpdate()->findOrFail($locked->source_id);
            if ($payable->status === OwnerPayableStatus::Scheduled && Fils::fromDecimal($payable->amount) === Fils::fromDecimal($locked->amount)) {
                $payable->forceFill(['status' => OwnerPayableStatus::Paid, 'disbursement_id' => $locked->id])->save();
            }
        }
```
In `app/Actions/Disbursements/PayDisbursement.php` inject `LockOwnerSource $ownerSource` and call `$this->ownerSource->handle($disbursement);` as the first line inside the transaction closure, before the disbursement is locked.

- [ ] **Step 4: Reversal reopens the payable**

In `app/Approvals/PaymentOutReversal.php` add a constructor `public function __construct(private LockOwnerSource $ownerSource, private RescheduleOwnerPayables $reschedule) {}` (handlers are resolved with `app()`). In `approve()`, after the customer lock and before the cheque lock, add `$contract = $this->ownerSource->handle($out);` and pass it on: `$this->reopen($out, $approver, $contract);`. Extend `reopen()`:
```php
    /** Spec §7.3: reopen the source. A credit refund needs nothing (its amount counts as credit again once reversed). */
    private function reopen(Disbursement $out, User $approver, ?OwnerContract $contract): void
    {
        if ($out->purpose === DisbursementPurpose::HeadLease && $contract !== null) {
            $payable = OwnerPayable::query()->lockForUpdate()->findOrFail($out->source_id);
            if ($payable->disbursement_id === $out->id) {
                $payable->forceFill(['status' => OwnerPayableStatus::Scheduled, 'disbursement_id' => null])->save();
                // A payable past an end date that moved while it was paid is cancelled or re-cut now (plan ruling 3).
                $this->reschedule->handle($contract, $contract->end_date);
            }

            return;
        }

        if ($out->purpose !== DisbursementPurpose::DepositRefund) {
            return;
        }
        // … the existing deposit-refund code, unchanged …
    }
```

- [ ] **Step 5: The head-lease payments due page**

Create `app/Livewire/OwnerPayables/Index.php`:
```php
<?php

namespace App\Livewire\OwnerPayables;

use App\Actions\Disbursements\RecordDisbursement;
use App\Enums\DisbursementMethod;
use App\Enums\OwnerPayableStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Disbursement;
use App\Models\OwnerContract;
use App\Models\OwnerPayable;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Spec §10: head-lease payments due — scheduled payables to owners, paid from here (§7.5). */
class Index extends Component
{
    use WithActor;

    #[Url]
    public ?int $building = null;

    #[Url]
    public string $dueBy = '';

    #[Locked]
    public ?int $payingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->actor()->can('finance.view'), 403);
        $this->dueBy = $this->dueBy ?: now('Asia/Bahrain')->addDays(30)->toDateString();
    }

    public function startPaying(int $payableId): void
    {
        abort_unless($this->actor()->can('create', Disbursement::class), 403);
        $payable = $this->payables()->findOrFail($payableId);
        $this->payingId = $payable->id;
        $this->form = ['amount' => $payable->amount, 'method' => DisbursementMethod::BankTransfer->value, 'paid_on' => now('Asia/Bahrain')->toDateString()];
        Flux::modal('pay-head-lease')->show();
    }

    public function pay(RecordDisbursement $record): void
    {
        try {
            $out = $record->handle($this->actor(), [...$this->form, 'purpose' => 'head_lease', 'owner_payable_id' => $this->payingId]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
        }

        Flux::modal('pay-head-lease')->close();
        $this->reset('payingId', 'form');
        Flux::toast(variant: 'success', text: $out->status->value === 'paid' ? __('Paid as :n.', ['n' => $out->number]) : __('Sent to Management for approval.'));
    }

    /** @return \Illuminate\Database\Eloquent\Builder<OwnerPayable> */
    private function payables(): \Illuminate\Database\Eloquent\Builder
    {
        return OwnerPayable::query()->where('status', OwnerPayableStatus::Scheduled)
            ->whereIn('owner_contract_id', OwnerContract::visibleTo($this->actor())->select('id'));
    }

    public function render(): View
    {
        return view('livewire.owner-payables.index', [
            'rows' => $this->payables()
                ->with(['contract:id,number,owner_id,building_id', 'contract.owner:id,name_en', 'contract.building:id,code'])
                ->when($this->building, fn ($q, $b) => $q->whereIn('owner_contract_id', OwnerContract::query()->where('building_id', $b)->select('id')))
                ->where('due_date', '<=', $this->dueBy)
                ->orderBy('due_date')->orderBy('id')->get(),
            'buildings' => Building::visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
            'methods' => DisbursementMethod::cases(),
            'canPay' => $this->actor()->can('create', Disbursement::class),
        ])->title(__('Head-lease payments due'));
    }
}
```
(Import `Builder` instead of fully qualifying.) Create `resources/views/livewire/owner-payables/index.blade.php`:
```blade
<div class="space-y-4">
    <flux:heading size="xl">{{ __('Head-lease payments due') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-3">
        <flux:select wire:model.live="building" :label="__('Building')">
            <flux:select.option value="">{{ __('All buildings') }}</flux:select.option>
            @foreach ($buildings as $b)
                <flux:select.option :value="$b->id">{{ $b->code }} — {{ $b->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input type="date" wire:model.live="dueBy" :label="__('Due by')" />
    </div>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Due') }}</flux:table.column>
                <flux:table.column>{{ __('Contract') }}</flux:table.column>
                <flux:table.column>{{ __('Owner') }}</flux:table.column>
                <flux:table.column>{{ __('Period') }}</flux:table.column>
                <flux:table.column>{{ __('Amount') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($rows as $p)
                    <flux:table.row :key="'op-'.$p->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $p->due_date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell><flux:link :href="route('owner-contracts.show', $p->owner_contract_id)" wire:navigate>{{ $p->contract->number }}</flux:link> <span class="text-zinc-500">{{ $p->contract->building->code }}</span></flux:table.cell>
                        <flux:table.cell>{{ $p->contract->owner->name_en }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $p->period_start->format('d/m/Y') }} – {{ $p->period_end->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $p->amount }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($canPay)
                                <flux:button size="sm" wire:click="startPaying({{ $p->id }})">{{ __('Pay') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="6">{{ __('Nothing due.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="pay-head-lease" class="md:w-96">
        <form wire:submit="pay" class="space-y-4">
            <flux:heading size="lg">{{ __('Pay head-lease') }}</flux:heading>
            <flux:input wire:model="form.amount" :label="__('Amount (BHD)')" inputmode="decimal" />
            <flux:select wire:model.live="form.method" :label="__('Method')">
                @foreach ($methods as $m)
                    <flux:select.option :value="$m->value">{{ $m->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="date" wire:model="form.paid_on" :label="__('Paid on')" />
            <flux:input wire:model="form.reference" :label="__('Reference')" />
            @if (($form['method'] ?? null) === 'cheque')
                <flux:input wire:model="form.cheque_no" :label="__('Cheque number')" />
                <flux:input wire:model="form.bank_name" :label="__('Bank')" />
                <flux:input type="date" wire:model="form.cheque_date" :label="__('Cheque date')" />
            @endif
            <flux:error name="form.owner_payable_id" />
            <div class="flex justify-end"><flux:button type="submit" variant="primary">{{ __('Record payment') }}</flux:button></div>
        </form>
    </flux:modal>
</div>
```
Add to `routes/property.php`, beside the disbursements routes:
```php
    Route::livewire('head-lease-payments', OwnerPayables\Index::class)->middleware('can:finance.view')->name('owner-payables.index');
```
(import the `App\Livewire\OwnerPayables` namespace like the others.) In the sidebar's Finance group, after "Payments out":
```blade
                        <flux:sidebar.item icon="calendar-days" :href="route('owner-payables.index')" :current="request()->routeIs('owner-payables.*')" wire:navigate>{{ __('Head-lease due') }}</flux:sidebar.item>
```
In the owner contract page's payables table (Task 2), make the status cell link the payment out when there is one:
```blade
                                <flux:table.cell>
                                    <flux:badge size="sm">{{ $p->status->label() }}</flux:badge>
                                    @if ($p->disbursement_id)
                                        <flux:link :href="route('disbursements.show', $p->disbursement_id)" wire:navigate>{{ __('Payment') }}</flux:link>
                                    @endif
                                </flux:table.cell>
```

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Disbursements tests/Feature/OwnerContracts tests/Feature/Permissions
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Pay head-lease payables to owners

A scheduled payable is paid at once for exactly its amount; a second
payment or a different amount goes to Management. Reversing the payment
reopens the payable. A new page lists the payments due.
EOF
```

---

### Task 4: Owner charges and the owner ledger

**Spec:** §7.9 (owner ledger entries and signs, ordered by `posted_at`; `owner_charges` write-once; deposits post only if `deposits_held_by = owner`, opening movements excluded; expenses at `posted_at` with the reversal at `reversed_at`), §11 (an imported `opening_balance` charge is the only opening amount), plan ruling 6

**Files:**
- Create: `database/migrations/2026_11_01_000200_create_owner_charges_table.php`, `app/Enums/OwnerChargeType.php`, `app/Models/OwnerCharge.php`, `app/Billing/OwnerLedger.php`, `tests/Feature/Owners/OwnerLedgerTest.php`
- Modify: `app/Integrity/IntegrityCheck.php`, `app/Models/OwnerContract.php`

**Interfaces:**
- Produces:
  - `OwnerChargeType` (`ManagementFee = 'management_fee'`, `OpeningBalance = 'opening_balance'`; `label()`), `OwnerCharge`, `OwnerContract::charges()`
  - `OwnerLedger::entries(OwnerContract $contract, ?CarbonImmutable $after = null, ?CarbonImmutable $upTo = null): Collection<int, array{posted_at: CarbonImmutable, date: string, kind: string, reference: string, amount: int}>` — ordered by `posted_at` then kind; window is `after < posted_at ≤ upTo`
  - `OwnerLedger::balance(OwnerContract $contract, ?CarbonImmutable $upTo = null): int` (fils)
  - entry kinds: `collection`, `collection_reversal`, `deposit_received`, `deposit_applied`, `deposit_refunded`, `deposit_transfer_in`, `deposit_transfer_out`, `management_fee`, `opening_balance`, `expense`, `expense_reversal` (Task 7 adds `remittance`, `remittance_reversal`)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Owners/OwnerLedgerTest.php`:
```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Expenses\ReverseExpense;
use App\Actions\Payments\RecordPayment;
use App\Billing\OwnerLedger;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerCharge;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00']);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $building = Building::factory()->create();
    $this->unit = Unit::factory()->for($building)->create();
    $this->contract = fn (string $heldBy) => activeOwnerContract(['building_id' => $building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => $heldBy, 'fee_type' => 'percent_collected', 'fee_value' => '10.000'], [$this->unit]);
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $this->au = $this->agreement->agreementUnits()->sole();
});

test('collections post net of VAT, deposits only when the owner holds them, expenses and their reversal at their own times', function () {
    $contract = ($this->contract)('owner');
    issuedInvoice($this->customer, [['net' => '100.000', 'tax' => 'standard', 'au' => $this->au], ['type' => 'deposit', 'net' => '200.000', 'au' => $this->au]], '2026-03-01', $this->agreement);
    app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-03-10', 'method' => 'cash', 'amount' => '310.000']);
    $expense = app(RecordExpense::class)->handle($this->finance, ['building_id' => $this->unit->building_id, 'unit_id' => $this->unit->id, 'category' => 'maintenance', 'description' => 'Pump', 'expense_date' => '2026-03-10', 'net' => '20.000', 'tax_amount' => '2.000', 'charge_to' => 'owner']);
    $this->travel(1)->days();
    app(ReverseExpense::class)->handle($this->finance, $expense, 'Duplicate');

    $entries = OwnerLedger::entries($contract->fresh());
    expect($entries->map(fn ($e) => [$e['kind'], $e['amount']])->all())->toEqualCanonicalizing([
        ['collection', 100_000],        // 110.000 less 10.000 VAT
        ['deposit_received', 200_000],
        ['expense', -22_000],           // plan ruling 6: the total
        ['expense_reversal', 22_000],
    ])->and(OwnerLedger::balance($contract->fresh()))->toBe(300_000)
        ->and(OwnerLedger::balance($contract->fresh(), CarbonImmutable::parse('2026-03-10 23:59:59')))->toBe(278_000)
        ->and(OwnerLedger::entries($contract->fresh(), CarbonImmutable::parse('2026-03-10 23:59:59'))->pluck('kind')->all())->toBe(['expense_reversal']);
});

test('deposits held by the company stay off the owner ledger; owner charges post signed', function () {
    $contract = ($this->contract)('company');
    issuedInvoice($this->customer, [['type' => 'deposit', 'net' => '200.000', 'au' => $this->au]], '2026-03-01', $this->agreement);
    app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-03-10', 'method' => 'cash', 'amount' => '200.000']);
    OwnerCharge::create(['owner_contract_id' => $contract->id, 'type' => 'opening_balance', 'net' => '-50.000', 'tax_amount' => '0.000', 'amount' => '-50.000', 'posted_at' => now(), 'created_by' => $this->finance->id]);

    expect(OwnerLedger::entries($contract)->map(fn ($e) => [$e['kind'], $e['amount']])->all())->toBe([['opening_balance', -50_000]]);
});

test('owner charges are write-once', function () {
    $contract = ($this->contract)('company');
    $charge = OwnerCharge::create(['owner_contract_id' => $contract->id, 'type' => 'opening_balance', 'net' => '50.000', 'tax_amount' => '0.000', 'amount' => '50.000', 'posted_at' => now(), 'created_by' => $this->finance->id]);

    expect(fn () => DB::table('owner_charges')->where('id', $charge->id)->update(['amount' => '1.000']))->toThrow(QueryException::class, 'owner_charges are write-once')
        ->and(fn () => DB::table('owner_charges')->where('id', $charge->id)->delete())->toThrow(QueryException::class, 'owner_charges are write-once')
        ->and(fn () => OwnerCharge::create(['owner_contract_id' => $contract->id, 'type' => 'management_fee', 'net' => '5.000', 'tax_amount' => '0.500', 'amount' => '5.500', 'posted_at' => now(), 'created_by' => $this->finance->id]))
        ->toThrow(QueryException::class); // a fee is always owed by the owner and belongs to a statement
});
```
Check `RecordExpense`'s input keys and that it resolves `owner_contract_id` from the unit for `charge_to = owner`; adapt the arrays, not the Action.

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Owners/OwnerLedgerTest.php`
Expected: FAIL — `Class "App\Billing\OwnerLedger" not found`.

- [ ] **Step 3: Migration, enum, model**

Create `database/migrations/2026_11_01_000200_create_owner_charges_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_contract_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('owner_statement_id')->nullable(); // its foreign key comes with owner_statements (Task 5)
            $table->string('type', 20);
            $table->decimal('net', 12, 3);
            $table->decimal('tax_amount', 12, 3)->default(0);
            $table->decimal('amount', 12, 3);
            $table->timestamp('posted_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['owner_contract_id', 'posted_at']);
        });

        // amount: + = the company owes the owner. A fee is owed by the owner, with its VAT, and belongs to a statement.
        DB::statement(<<<'SQL'
            ALTER TABLE owner_charges
                ADD CONSTRAINT owner_charges_type_chk CHECK (type IN ('management_fee', 'opening_balance')),
                ADD CONSTRAINT owner_charges_amount_chk CHECK (amount <> 0),
                ADD CONSTRAINT owner_charges_fee_chk CHECK (type <> 'management_fee'
                    OR (net > 0 AND tax_amount >= 0 AND amount = -(net + tax_amount) AND owner_statement_id IS NOT NULL)),
                ADD CONSTRAINT owner_charges_opening_chk CHECK (type <> 'opening_balance'
                    OR (tax_amount = 0 AND amount = net AND owner_statement_id IS NULL))
            SQL);

        DB::unprepared("CREATE TRIGGER owner_charges_no_update BEFORE UPDATE ON owner_charges FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_charges are write-once'");
        DB::unprepared("CREATE TRIGGER owner_charges_no_delete BEFORE DELETE ON owner_charges FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_charges are write-once'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS owner_charges_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS owner_charges_no_update');
        Schema::dropIfExists('owner_charges');
    }
};
```
`EXPECTED_TRIGGERS` 55 → **57** (`+ 2 (owner charges)`).

Create `app/Enums/OwnerChargeType.php`:
```php
<?php

namespace App\Enums;

enum OwnerChargeType: string
{
    case ManagementFee = 'management_fee';
    case OpeningBalance = 'opening_balance';

    public function label(): string
    {
        return match ($this) {
            self::ManagementFee => __('Management fee'),
            self::OpeningBalance => __('Opening balance'),
        };
    }
}
```
Create `app/Models/OwnerCharge.php`:
```php
<?php

namespace App\Models;

use App\Enums\OwnerChargeType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spec §7.9. Write-once (triggers).
 *
 * @property int $id
 * @property int $owner_contract_id
 * @property int|null $owner_statement_id
 * @property OwnerChargeType $type
 * @property string $net
 * @property string $tax_amount
 * @property string $amount
 * @property CarbonImmutable $posted_at
 * @property int $created_by
 */
class OwnerCharge extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => OwnerChargeType::class,
            'net' => 'decimal:3',
            'tax_amount' => 'decimal:3',
            'amount' => 'decimal:3',
            'posted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<OwnerContract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(OwnerContract::class, 'owner_contract_id');
    }
}
```
In `app/Models/OwnerContract.php` add `charges(): HasMany` → `OwnerCharge::class`.

- [ ] **Step 4: The ledger query**

Create `app/Billing/OwnerLedger.php`:
```php
<?php

namespace App\Billing;

use App\Enums\DepositsHeldBy;
use App\Enums\DepositMovementType;
use App\Models\OwnerContract;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spec §7.9: a managed contract's owner ledger, a query over rows that carry their owner stamp. + = the company owes
 * the owner. Each entry sits at its posting time (a reversal at its reversal time) and shows its business date.
 *
 * ponytail: loads the contract's whole history and windows it in PHP; push the window into SQL if one contract
 * passes ~10k entries.
 */
final class OwnerLedger
{
    /** @return Collection<int, array{posted_at: CarbonImmutable, date: string, kind: string, reference: string, amount: int}> */
    public static function entries(OwnerContract $contract, ?CarbonImmutable $after = null, ?CarbonImmutable $upTo = null): Collection
    {
        return self::all($contract)
            ->filter(fn (array $e) => ($after === null || $e['posted_at']->greaterThan($after)) && ($upTo === null || $e['posted_at']->lessThanOrEqualTo($upTo)))
            ->sortBy(fn (array $e) => [$e['posted_at']->getTimestamp(), $e['kind'], $e['reference']])
            ->values();
    }

    public static function balance(OwnerContract $contract, ?CarbonImmutable $upTo = null): int
    {
        return (int) self::entries($contract, null, $upTo)->sum('amount');
    }

    /** @return Collection<int, array{posted_at: CarbonImmutable, date: string, kind: string, reference: string, amount: int}> */
    private static function all(OwnerContract $contract): Collection
    {
        $at = fn (string $ts) => CarbonImmutable::parse($ts);
        $entries = collect();

        // Allocations and their reversals on the contract's non-deposit lines, net of VAT.
        foreach (DB::table('payment_allocations as a')
            ->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id')
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.owner_contract_id', $contract->id)->where('l.charge_type', '<>', 'deposit')
            ->get(['a.amount', 'a.tax_amount', 'a.posted_at', 'p.number as payment', 'p.received_on', 'i.number as invoice']) as $r) {
            $amount = Fils::fromDecimal((string) $r->amount) - Fils::fromDecimal((string) $r->tax_amount);
            $entries->push([
                'posted_at' => $at($r->posted_at),
                'date' => $amount >= 0 ? (string) $r->received_on : $at($r->posted_at)->toDateString(),
                'kind' => $amount >= 0 ? 'collection' : 'collection_reversal',
                'reference' => "{$r->payment} → {$r->invoice}",
                'amount' => $amount,
            ]);
        }

        // Deposits, only when the owner holds them; opening movements never (spec §11).
        if ($contract->deposits_held_by === DepositsHeldBy::Owner) {
            foreach (DB::table('deposit_movements')->where('owner_contract_id', $contract->id)
                ->where('type', '<>', DepositMovementType::Opening->value)->get(['type', 'amount', 'posted_at', 'source_type', 'source_id']) as $r) {
                $entries->push([
                    'posted_at' => $at($r->posted_at),
                    'date' => $at($r->posted_at)->toDateString(),
                    'kind' => 'deposit_'.$r->type,
                    'reference' => "{$r->source_type} {$r->source_id}",
                    'amount' => Fils::fromDecimal((string) $r->amount),
                ]);
            }
        }

        foreach (DB::table('owner_charges')->where('owner_contract_id', $contract->id)->get(['type', 'amount', 'posted_at', 'owner_statement_id']) as $r) {
            $entries->push([
                'posted_at' => $at($r->posted_at),
                'date' => $at($r->posted_at)->toDateString(),
                'kind' => $r->type,
                'reference' => $r->owner_statement_id !== null ? "statement {$r->owner_statement_id}" : '',
                'amount' => Fils::fromDecimal((string) $r->amount),
            ]);
        }

        // Expenses charged to the owner: the total (plan ruling 6); a reversal is the opposite entry at reversed_at.
        foreach (DB::table('expenses')->where('owner_contract_id', $contract->id)->whereNotNull('posted_at')
            ->get(['id', 'description', 'expense_date', 'total', 'posted_at', 'reversed_at']) as $r) {
            $total = Fils::fromDecimal((string) $r->total);
            $entries->push(['posted_at' => $at($r->posted_at), 'date' => (string) $r->expense_date, 'kind' => 'expense', 'reference' => $r->description, 'amount' => -$total]);
            if ($r->reversed_at !== null) {
                $entries->push(['posted_at' => $at($r->reversed_at), 'date' => $at($r->reversed_at)->toDateString(), 'kind' => 'expense_reversal', 'reference' => $r->description, 'amount' => $total]);
            }
        }

        return $entries;
    }
}
```
If `expenses.posted_at` is written by `RecordExpense` (check; it should be, §5.10/M1), nothing else is needed. If it is left null, set it to `now()` in `RecordExpense` in this task and say so in the report.

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Owners tests/Feature/Integrity
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add owner charges and the owner ledger

Owner charges (management fee, opening balance) are write-once. The
owner ledger is a query: collections net of VAT, deposits when the owner
holds them, charges, and owner-charged expenses with their reversals at
the time they happen.
EOF
```

---
### Task 5: Owner statements — table, figures and monthly drafts

**Spec:** §7.9 Statements (`owner_statements` fields; window = after the previous statement's `cutoff_at`, up to its own; late entries land on the next statement showing their business date; drafts on the 1st for every managed contract active on any day of the previous month or with ledger entries after its last cutoff; the successor's first opening balance is 0; fee base rules for the three fee types, `opening_balance` lines count as rent, a negative base gives fee 0 and carries into next month's base; VAT on the fee when `vat_registered`), §12 (1st of month 04:00, idempotent, `withoutOverlapping(120)`, heartbeat), §14 flow 6 (statement = net-of-VAT collections − fee − expenses − remittances; a late entry lands on the next statement), C4 (rent only), plan rulings 7–8

**Files:**
- Create: `app/Enums/OwnerStatementStatus.php`, `database/migrations/2026_11_01_000300_create_owner_statements_table.php`, `app/Models/OwnerStatement.php`, `app/Billing/OwnerStatementCalculator.php`, `app/Actions/OwnerStatements/DraftOwnerStatements.php`, `app/Console/Commands/DraftOwnerStatementsCommand.php`, `tests/Feature/OwnerStatements/OwnerStatementFiguresTest.php`
- Modify: `routes/console.php`, `config/services.php`, `app/Models/OwnerContract.php`, `app/Integrity/IntegrityCheck.php`

**Interfaces:**
- Consumes: `OwnerLedger::entries()` (Task 4), `OwnerCharge` (Task 4)
- Produces:
  - `OwnerStatementStatus` (`Draft`, `PendingApproval`, `Finalised`; `label()`)
  - `OwnerStatement` (`contract()`, `approvals()`, `previous(): ?OwnerStatement`, `label(): string`, casts: `period_start`/`period_end` immutable_date, `cutoff_at` immutable_datetime, money decimal:3, `status`)
  - `OwnerContract::statements()`
  - `OwnerStatementCalculator::compute(OwnerStatement $s): array{opening: int, entries: Collection, fee_base: int, fee: int, fee_tax: int, closing: int}` (fils; `entries` = `OwnerLedger::entries` in the window without the statement's own fee row)
  - `OwnerStatementCalculator::apply(OwnerStatement $s): void` (writes `opening_balance`, `fee_base`, `fee_amount`, `fee_tax`, `closing_balance`; does not save)
  - `DraftOwnerStatements::handle(CarbonImmutable $month): int` (drafts for that calendar month; returns rows created)
  - command `rms:owner-statements:draft {--month=YYYY-MM}` (default: the previous month)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OwnerStatements/OwnerStatementFiguresTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\EnsureNumberSequences;
use App\Actions\Expenses\RecordExpense;
use App\Actions\OwnerStatements\DraftOwnerStatements;
use App\Actions\Payments\RecordPayment;
use App\Billing\OwnerStatementCalculator;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerCharge;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->building = Building::factory()->create();
    $this->unit = Unit::factory()->for($this->building)->create();
    $this->managed = fn (array $over = []) => activeOwnerContract(['building_id' => $this->building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'percent_collected', 'fee_value' => '10.000', ...$over], [$this->unit]);
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $this->rent = fn (string $net, string $due) => issuedInvoice($this->customer, [['net' => $net, 'tax' => 'standard', 'au' => $this->agreement->agreementUnits()->sole()]], $due, $this->agreement);
    $this->pay = fn (string $amount, string $on) => app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => $on, 'method' => 'cash', 'amount' => $amount]);
    $this->draft = function (string $month) {
        $this->travelTo(CarbonImmutable::parse($month.'-01 04:00', 'Asia/Bahrain')->addMonth());

        return app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse($month.'-01'));
    };
});

test('§14 flow 6: collections net of VAT, less expenses and the fee; a late entry lands on the next statement', function () {
    $contract = ($this->managed)();
    ($this->rent)('100.000', '2026-03-01');
    ($this->pay)('110.000', '2026-03-10');
    app(RecordExpense::class)->handle($this->finance, ['building_id' => $this->building->id, 'unit_id' => $this->unit->id, 'category' => 'maintenance', 'description' => 'Pump', 'expense_date' => '2026-03-10', 'net' => '20.000', 'tax_amount' => '2.000', 'charge_to' => 'owner']);
    ($this->rent)('100.000', '2026-03-15');

    expect(($this->draft)('2026-03'))->toBe(1);
    $march = OwnerStatement::where('owner_contract_id', $contract->id)->sole();
    expect([$march->opening_balance, $march->fee_base, $march->fee_amount, $march->fee_tax, $march->closing_balance, $march->status->value])
        ->toBe(['0.000', '100.000', '10.000', '1.000', '67.000', 'draft'])  // 100 − 22 − 10 − 1
        ->and($march->cutoff_at->toDateTimeString())->toBe('2026-03-31 23:59:59');

    // Received on 31 March but recorded on 2 April: April's statement, showing its March date.
    $this->travelTo(CarbonImmutable::parse('2026-04-02 09:00', 'Asia/Bahrain'));
    ($this->pay)('110.000', '2026-03-31');
    ($this->draft)('2026-04');
    $april = OwnerStatement::where('owner_contract_id', $contract->id)->where('period_start', '2026-04-01')->sole();
    $figures = OwnerStatementCalculator::compute($april);

    expect($april->opening_balance)->toBe('67.000')->and($april->closing_balance)->toBe('156.000') // 67 + 100 − 11
        ->and($figures['entries']->map(fn ($e) => [$e['kind'], $e['date'], $e['amount']])->all())->toBe([['collection', '2026-03-31', 100_000]]);
});

test('percent billed: a negative base gives no fee and carries into next month', function () {
    $contract = ($this->managed)(['fee_type' => 'percent_billed']);
    $march = ($this->rent)('100.000', '2026-03-01');
    ($this->draft)('2026-03');

    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00', 'Asia/Bahrain'));
    $cn = app(SaveCreditNote::class)->handle($this->finance, $march, null, ['reason' => 'Rent waived', 'lines' => [['credited_line_id' => $march->lines->sole()->id, 'amount' => '110.000']]]);
    app(DecideApproval::class)->handle($this->management, app(SubmitCreditNote::class)->handle($this->finance, $cn), true);
    ($this->draft)('2026-04');

    $this->travelTo(CarbonImmutable::parse('2026-05-10 10:00', 'Asia/Bahrain'));
    ($this->rent)('300.000', '2026-05-01');
    ($this->draft)('2026-05');

    $s = OwnerStatement::where('owner_contract_id', $contract->id)->orderBy('period_start')->get();
    expect($s->map(fn ($x) => [$x->fee_base, $x->fee_amount])->all())->toBe([
        ['100.000', '10.000'],
        ['-100.000', '0.000'],
        ['200.000', '20.000'], // 300 − the 100 carried
    ]);
});

test('a fixed fee is prorated for the days the contract is active in the month', function () {
    $contract = ($this->managed)(['fee_type' => 'fixed', 'fee_value' => '300.000', 'start_date' => '2026-03-16']);
    ($this->draft)('2026-03');

    $march = OwnerStatement::where('owner_contract_id', $contract->id)->sole();
    // 16 days on actual/365: 300 000 × 16 × 12 / 365 = 157 808.2 → 157.808; VAT 15.781.
    expect([$march->fee_base, $march->fee_amount, $march->fee_tax, $march->closing_balance])->toBe(['157.808', '157.808', '15.781', '-173.589']);
});

test('drafts: managed contracts active in the month or with new entries; once per month; nightly at 04:00 on the 1st', function () {
    $active = ($this->managed)();
    $ended = function () {
        $c = ($this->managed)(['start_date' => '2025-01-01', 'end_date' => '2026-01-31']);
        $c->forceFill(['status' => 'ended'])->save();

        return $c;
    };
    $endedQuiet = $ended();
    $endedBusy = $ended();
    OwnerCharge::create(['owner_contract_id' => $endedBusy->id, 'type' => 'opening_balance', 'net' => '5.000', 'tax_amount' => '0.000', 'amount' => '5.000', 'posted_at' => now(), 'created_by' => $this->finance->id]);
    activeOwnerContract(['building_id' => $this->building->id, 'type' => 'leased', 'rent_amount' => '1.000', 'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], []);

    expect(($this->draft)('2026-03'))->toBe(2)
        ->and(OwnerStatement::pluck('owner_contract_id')->sort()->values()->all())->toBe([$active->id, $endedBusy->id])
        ->and(OwnerStatement::where('owner_contract_id', $endedBusy->id)->value('created_by'))->toBe($endedBusy->created_by) // plan ruling 8
        ->and(app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-03-01')))->toBe(0)
        ->and(OwnerStatement::where('owner_contract_id', $endedQuiet->id)->exists())->toBeFalse()
        ->and(scheduledEvent('rms:owner-statements:draft')->expression)->toBe('0 4 1 * *');
});

test('statements are never deleted and a finalised one never changes', function () {
    $contract = ($this->managed)();
    ($this->draft)('2026-03');
    $id = OwnerStatement::where('owner_contract_id', $contract->id)->value('id');

    expect(fn () => DB::table('owner_statements')->where('id', $id)->delete())->toThrow(QueryException::class, 'owner_statements cannot be deleted');
    DB::table('owner_statements')->where('id', $id)->update(['status' => 'pending_approval']);
    DB::table('owner_statements')->where('id', $id)->update(['status' => 'finalised', 'number' => 'OS-T-1', 'finalised_at' => now(), 'finalised_by' => $this->management->id]);
    expect(fn () => DB::table('owner_statements')->where('id', $id)->update(['closing_balance' => '1.000']))->toThrow(QueryException::class, 'owner_statements: a finalised statement never changes')
        ->and(fn () => DB::table('owner_statements')->where('id', $id)->update(['status' => 'draft']))->toThrow(QueryException::class);
});
```
`activeOwnerContract` makes an `active` contract; active → ended is an allowed transition.

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/OwnerStatements/OwnerStatementFiguresTest.php`
Expected: FAIL — `Class "App\Actions\OwnerStatements\DraftOwnerStatements" not found`.

- [ ] **Step 3: Enum, migration, model**

Create `app/Enums/OwnerStatementStatus.php`:
```php
<?php

namespace App\Enums;

enum OwnerStatementStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Finalised = 'finalised';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
```
Create `database/migrations/2026_11_01_000300_create_owner_statements_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_statements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('owner_contract_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamp('cutoff_at');
            $table->decimal('opening_balance', 12, 3)->default(0);
            $table->decimal('fee_base', 12, 3)->default(0);
            $table->decimal('fee_amount', 12, 3)->default(0);
            $table->decimal('fee_tax', 12, 3)->default(0);
            $table->decimal('closing_balance', 12, 3)->default(0);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('finalised_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('finalised_at')->nullable();
            $table->timestamps();
            $table->unique(['owner_contract_id', 'period_start']);
            $table->index(['status', 'period_start']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE owner_statements
                ADD CONSTRAINT owner_statements_status_chk CHECK (status IN ('draft', 'pending_approval', 'finalised')),
                ADD CONSTRAINT owner_statements_final_chk CHECK ((status = 'finalised') = (number IS NOT NULL)
                    AND (status = 'finalised') = (finalised_at IS NOT NULL) AND (status = 'finalised') = (finalised_by IS NOT NULL)),
                ADD CONSTRAINT owner_statements_fee_chk CHECK (fee_amount >= 0 AND fee_tax >= 0),
                ADD CONSTRAINT owner_statements_period_chk CHECK (period_end >= period_start)
            SQL);

        Schema::table('owner_charges', function (Blueprint $table) {
            $table->foreign('owner_statement_id')->references('id')->on('owner_statements')->restrictOnDelete();
        });

        DB::unprepared("CREATE TRIGGER owner_statements_no_delete BEFORE DELETE ON owner_statements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_statements cannot be deleted'");

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER owner_statements_guard BEFORE UPDATE ON owner_statements FOR EACH ROW
            BEGIN
                IF OLD.status = 'finalised' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_statements: a finalised statement never changes';
                END IF;
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'draft' AND NEW.status = 'pending_approval')
                    OR (OLD.status = 'pending_approval' AND NEW.status IN ('draft', 'finalised'))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_statements: status change not allowed';
                END IF;
                IF NOT (NEW.owner_contract_id <=> OLD.owner_contract_id AND NEW.period_start <=> OLD.period_start
                    AND NEW.period_end <=> OLD.period_end AND NEW.cutoff_at <=> OLD.cutoff_at AND NEW.created_by <=> OLD.created_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_statements: the period and contract are frozen';
                END IF;
            END
            DDL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS owner_statements_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS owner_statements_no_delete');
        Schema::table('owner_charges', fn (Blueprint $table) => $table->dropForeign(['owner_statement_id']));
        Schema::dropIfExists('owner_statements');
    }
};
```
`EXPECTED_TRIGGERS` 57 → **59** (`+ 2 (owner statements)`).

Create `app/Models/OwnerStatement.php`:
```php
<?php

namespace App\Models;

use App\Enums\OwnerStatementStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec §7.9.
 *
 * @property int $id
 * @property string|null $number
 * @property int $owner_contract_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $cutoff_at
 * @property string $opening_balance
 * @property string $fee_base
 * @property string $fee_amount
 * @property string $fee_tax
 * @property string $closing_balance
 * @property OwnerStatementStatus $status
 * @property int $created_by
 * @property int|null $finalised_by
 * @property CarbonImmutable|null $finalised_at
 * @property-read OwnerContract $contract
 */
class OwnerStatement extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'cutoff_at' => 'immutable_datetime',
            'opening_balance' => 'decimal:3',
            'fee_base' => 'decimal:3',
            'fee_amount' => 'decimal:3',
            'fee_tax' => 'decimal:3',
            'closing_balance' => 'decimal:3',
            'status' => OwnerStatementStatus::class,
            'finalised_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'number', 'closing_balance'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<OwnerContract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(OwnerContract::class, 'owner_contract_id');
    }

    /** @return MorphMany<Approval, $this> */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    /** The contract's statement for the month before this one's, if any (statements may skip quiet months). */
    public function previous(): ?self
    {
        return self::query()->where('owner_contract_id', $this->owner_contract_id)
            ->where('period_start', '<', $this->period_start->toDateString())->orderByDesc('period_start')->first();
    }

    public function label(): string
    {
        return $this->number ?? __('Draft statement :m', ['m' => $this->period_start->format('M Y')]);
    }
}
```
In `app/Models/OwnerContract.php` add `statements(): HasMany` → `OwnerStatement::class`.

- [ ] **Step 4: The calculator**

Create `app/Billing/OwnerStatementCalculator.php`:
```php
<?php

namespace App\Billing;

use App\Enums\FeeType;
use App\Enums\TaxCategory;
use App\Models\CompanySetting;
use App\Models\OwnerStatement;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spec §7.9: one statement's figures. Its window is (previous statement's cutoff_at, its own cutoff_at]; the opening
 * balance is the previous statement's closing (0 for a contract's first). Entries in a window never change once the
 * cutoff has passed (late postings land in the next window), so drafts, submission and finalisation agree.
 */
final class OwnerStatementCalculator
{
    /** Spec C4: the fee base is rent only; on managed contracts imported opening_balance lines count as rent. */
    private const array FEE_CHARGES = ['rent', 'opening_balance'];

    /** @return array{opening: int, entries: Collection<int, array{posted_at: CarbonImmutable, date: string, kind: string, reference: string, amount: int}>, fee_base: int, fee: int, fee_tax: int, closing: int} */
    public static function compute(OwnerStatement $s): array
    {
        $contract = $s->contract;
        $previous = $s->previous();
        $after = $previous?->cutoff_at;
        $settings = CompanySetting::current();

        // The window's only possible fee row is this statement's own (earlier ones sit at earlier cutoffs).
        $entries = OwnerLedger::entries($contract, $after, $s->cutoff_at)->reject(fn (array $e) => $e['kind'] === 'management_fee')->values();
        $opening = $previous !== null ? Fils::fromDecimal($previous->closing_balance) : 0;

        if ($contract->fee_type === FeeType::Fixed) {
            $from = $contract->start_date->max($s->period_start);
            $to = $contract->end_date->min($s->period_end);
            $base = $from->greaterThan($to) ? 0 : Proration::partial(Fils::fromDecimal((string) $contract->fee_value), $from, $to, $settings->proration_basis);
            $fee = $base;
        } else {
            $carry = $previous !== null ? min(0, Fils::fromDecimal($previous->fee_base)) : 0;
            $base = self::windowBase($s, $contract->fee_type, $after) + $carry;
            $fee = $base > 0 ? Fils::divRound($base * Fils::fromDecimal((string) $contract->fee_value), 100_000) : 0; // fee_value % with 3 decimals
        }

        $feeTax = Tax::amount($fee, TaxCategory::Standard, (bool) $settings->vat_registered, (string) $settings->vat_rate);

        return [
            'opening' => $opening,
            'entries' => $entries,
            'fee_base' => $base,
            'fee' => $fee,
            'fee_tax' => $feeTax,
            'closing' => $opening + (int) $entries->sum('amount') - $fee - $feeTax,
        ];
    }

    public static function apply(OwnerStatement $s): void
    {
        $f = self::compute($s);
        $s->forceFill([
            'opening_balance' => Fils::toDecimal($f['opening']),
            'fee_base' => Fils::toDecimal($f['fee_base']),
            'fee_amount' => Fils::toDecimal($f['fee']),
            'fee_tax' => Fils::toDecimal($f['fee_tax']),
            'closing_balance' => Fils::toDecimal($f['closing']),
        ]);
    }

    private static function windowBase(OwnerStatement $s, FeeType $type, ?CarbonImmutable $after): int
    {
        $window = fn ($q, string $column) => $q->when($after, fn ($q) => $q->where($column, '>', $after))->where($column, '<=', $s->cutoff_at);

        if ($type === FeeType::PercentCollected) {
            $q = DB::table('payment_allocations as a')->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id')
                ->where('a.owner_contract_id', $s->owner_contract_id)->whereIn('l.charge_type', self::FEE_CHARGES);

            return Fils::fromDecimal((string) ($window($q, 'a.posted_at')->sum(DB::raw('a.amount - a.tax_amount')) ?: '0'));
        }

        // percent_billed: net rent lines issued in the window minus rent credit notes issued in the window.
        $billed = fn (bool $credit) => Fils::fromDecimal((string) ($window(DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('l.owner_contract_id', $s->owner_contract_id)->whereIn('l.charge_type', self::FEE_CHARGES)
            ->where('i.status', 'issued')->where('i.type', $credit ? '=' : '<>', 'credit_note'), 'i.issued_at')->sum('l.net') ?: '0'));

        return $billed(false) - $billed(true);
    }
}
```
Check that `CarbonImmutable::max()/min()` with a date argument return the later/earlier date (Carbon 3: yes); otherwise use `$a->greaterThan($b) ? $a : $b`.

- [ ] **Step 5: Monthly drafts and the schedule**

Create `app/Actions/OwnerStatements/DraftOwnerStatements.php`:
```php
<?php

namespace App\Actions\OwnerStatements;

use App\Billing\OwnerLedger;
use App\Billing\OwnerStatementCalculator;
use App\Enums\OwnerContractStatus;
use App\Enums\OwnerContractType;
use App\Enums\OwnerStatementStatus;
use App\Models\OwnerContract;
use App\Models\OwnerStatement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Spec §7.9, §12: on the 1st, a draft statement for the previous month for every managed contract that was active on
 * any day of it, or has ledger entries after its last statement's cutoff. Idempotent: one statement per contract and month.
 */
final class DraftOwnerStatements
{
    public function handle(CarbonImmutable $month): int
    {
        $start = $month->startOfMonth()->startOfDay();
        $end = $month->endOfMonth()->startOfDay();
        $cutoff = CarbonImmutable::parse($end->toDateString().' 23:59:59', 'Asia/Bahrain');

        $ids = OwnerContract::query()->where('type', OwnerContractType::Managed)
            ->whereIn('status', [OwnerContractStatus::Active, OwnerContractStatus::Ended, OwnerContractStatus::Terminated])
            ->where('start_date', '<=', $end->toDateString())->orderBy('id')->pluck('id');

        $created = 0;
        foreach ($ids as $id) {
            try {
                $created += DB::transaction(fn () => $this->draftOne($id, $start, $end, $cutoff), attempts: 3);
            } catch (Throwable $e) {
                report($e); // one contract's failure doesn't stop the others' statements
            }
        }

        return $created;
    }

    private function draftOne(int $id, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $cutoff): int
    {
        $contract = OwnerContract::query()->lockForUpdate()->findOrFail($id);
        if ($contract->statements()->where('period_start', $start->toDateString())->exists()) {
            return 0;
        }

        $last = $contract->statements()->where('period_start', '<', $start->toDateString())->orderByDesc('period_start')->first();
        $activeInMonth = $contract->end_date->greaterThanOrEqualTo($start);
        if (! $activeInMonth && OwnerLedger::entries($contract, $last?->cutoff_at, $cutoff)->isEmpty()) {
            return 0;
        }

        $statement = (new OwnerStatement)->forceFill([
            'owner_contract_id' => $contract->id,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'cutoff_at' => $cutoff,
            'status' => OwnerStatementStatus::Draft,
            'created_by' => $contract->created_by, // plan ruling 8, until M5 adds a system user
        ]);
        $statement->setRelation('contract', $contract);
        OwnerStatementCalculator::apply($statement);
        $statement->save();

        return 1;
    }
}
```
Create `app/Console/Commands/DraftOwnerStatementsCommand.php`:
```php
<?php

namespace App\Console\Commands;

use App\Actions\OwnerStatements\DraftOwnerStatements;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class DraftOwnerStatementsCommand extends Command
{
    protected $signature = 'rms:owner-statements:draft {--month= : YYYY-MM; defaults to the previous month}';

    protected $description = 'Draft owner statements for a month (spec §7.9)';

    public function handle(DraftOwnerStatements $draft): int
    {
        $month = $this->option('month')
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $this->option('month'), 'Asia/Bahrain')
            : CarbonImmutable::now('Asia/Bahrain')->subMonthNoOverflow();

        $this->info(sprintf('%d owner statement(s) drafted for %s.', $draft->handle($month), $month->format('Y-m')));

        return self::SUCCESS;
    }
}
```
In `routes/console.php`, following the existing entries:
```php
Schedule::command('rms:owner-statements:draft')->monthlyOn(1, '04:00')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.owner_statements')), (string) $url);
```
In `config/services.php` add `'owner_statements' => env('HEARTBEAT_OWNER_STATEMENTS'),` to `forge.heartbeats`, and `HEARTBEAT_OWNER_STATEMENTS=` to `.env.example` next to the other heartbeats.

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/OwnerStatements tests/Feature/Owners tests/Feature/Integrity
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Draft monthly owner statements with the management fee

On the 1st, every managed contract active in the previous month, or
with new ledger entries, gets a draft statement: opening balance, the
window's entries, the fee on its base (collected, billed or fixed) with
VAT, and the closing balance. A negative base carries into next month.
EOF
```

---

### Task 6: Statement review, finalisation, PDF and screens

**Spec:** §7.9 (Finance reviews and submits; Management approves (§8.3 item 8); a statement can be submitted only after the contract's previous statement is finalised; finalisation writes one `management_fee` row with `posted_at = cutoff_at` and stores the PDF; a finalised statement never changes; deposits held by the company shown for information), §8.3 (different approver), §9.3 (owner statement PDF, English), §10 (owner statement report; statements export to Excel and PDF; exports audited §8.4), §14 flow 6 (a reversed expense shows on the later statement and the finalised one is unchanged)

**Files:**
- Create: `app/Actions/OwnerStatements/SubmitOwnerStatement.php`, `app/Approvals/OwnerStatementFinalisation.php`, `app/Policies/OwnerStatementPolicy.php`, `app/Pdf/OwnerStatementPdf.php`, `app/Jobs/StoreOwnerStatement.php`, `resources/views/pdf/owner-statement.blade.php`, `app/Http/Controllers/OwnerStatementPdfController.php`, `app/Http/Controllers/OwnerStatementExportController.php`, `app/Livewire/OwnerStatements/{Index,Show}.php`, `resources/views/livewire/owner-statements/{index,show}.blade.php`, `tests/Feature/OwnerStatements/OwnerStatementFinalisationTest.php`
- Modify: `app/Enums/ApprovalAction.php`, `app/Billing/OwnerLedger.php`, `routes/property.php`, `resources/views/layouts/app/sidebar.blade.php`, `resources/views/livewire/owner-contracts/show.blade.php`

**Interfaces:**
- Consumes: `OwnerStatement`, `OwnerStatementCalculator` (Task 5), `OwnerCharge`, `OwnerChargeType` (Task 4)
- Produces:
  - `ApprovalAction::OwnerStatementFinalisation = 'owner_statement.finalise'` (handler `OwnerStatementFinalisation`)
  - `SubmitOwnerStatement::handle(User $actor, OwnerStatement $statement): Approval`
  - `OwnerStatementPolicy::{viewAny, view, submit}`
  - `OwnerLedger::kindLabel(string $kind): string`
  - `OwnerStatementPdf::render(OwnerStatement $s): string`, `StoreOwnerStatement` job (`stored(OwnerStatement): ?Document`)
  - routes `owner-statements.index`, `owner-statements.show`, `owner-statements.pdf`, `owner-statements.export`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OwnerStatements/OwnerStatementFinalisationTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\EnsureNumberSequences;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Expenses\ReverseExpense;
use App\Actions\OwnerStatements\DraftOwnerStatements;
use App\Actions\OwnerStatements\SubmitOwnerStatement;
use App\Actions\Payments\RecordPayment;
use App\Billing\OwnerStatementCalculator;
use App\Enums\RoleName;
use App\Jobs\StoreOwnerStatement;
use App\Livewire\OwnerStatements\Show;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerCharge;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $building = Building::factory()->create();
    $unit = Unit::factory()->for($building)->create();
    $this->contract = activeOwnerContract(['building_id' => $building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'percent_collected', 'fee_value' => '10.000'], [$unit]);
    $customer = Customer::factory()->create();
    $agreement = activeAgreement(['customer_id' => $customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$unit]);
    issuedInvoice($customer, [['net' => '100.000', 'tax' => 'standard', 'au' => $agreement->agreementUnits()->sole()]], '2026-03-01', $agreement);
    app(RecordPayment::class)->handle($this->finance, $customer, ['received_on' => '2026-03-10', 'method' => 'cash', 'amount' => '110.000']);
    $this->expense = app(RecordExpense::class)->handle($this->finance, ['building_id' => $building->id, 'unit_id' => $unit->id, 'category' => 'maintenance', 'description' => 'Pump', 'expense_date' => '2026-03-10', 'net' => '20.000', 'tax_amount' => '2.000', 'charge_to' => 'owner']);
    $this->draftFor = function (string $month) {
        $this->travelTo(CarbonImmutable::parse($month.'-01 04:00', 'Asia/Bahrain')->addMonth());
        app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse($month.'-01'));

        return OwnerStatement::where('owner_contract_id', $this->contract->id)->where('period_start', $month.'-01')->sole();
    };
    $this->finalise = fn (OwnerStatement $s) => app(DecideApproval::class)->handle($this->management, app(SubmitOwnerStatement::class)->handle($this->finance, $s), true);
});

test('finalising writes the fee charge at the cutoff, numbers the statement and stores its PDF', function () {
    $march = ($this->draftFor)('2026-03');
    ($this->finalise)($march);

    $march->refresh();
    $fee = OwnerCharge::where('owner_statement_id', $march->id)->sole();
    expect($march->status->value)->toBe('finalised')->and($march->number)->toStartWith('OS-2026-')
        ->and([$fee->type->value, $fee->net, $fee->tax_amount, $fee->amount, $fee->posted_at->toDateTimeString()])
        ->toBe(['management_fee', '10.000', '1.000', '-11.000', '2026-03-31 23:59:59'])
        ->and(StoreOwnerStatement::stored($march))->not->toBeNull()
        ->and(OwnerStatementCalculator::compute($march)['closing'])->toBe(67_000); // the fee row is not counted twice
});

test('§14 flow 6: a reversed expense shows on the later statement; the finalised one is unchanged', function () {
    $march = ($this->draftFor)('2026-03');
    ($this->finalise)($march);
    $this->travelTo(CarbonImmutable::parse('2026-04-05 10:00', 'Asia/Bahrain'));
    app(ReverseExpense::class)->handle($this->finance, $this->expense, 'Duplicate');
    $april = ($this->draftFor)('2026-04');

    expect($march->fresh()->closing_balance)->toBe('67.000')
        ->and(OwnerStatementCalculator::compute($march->fresh())['entries']->pluck('kind')->all())->toBe(['collection', 'expense'])
        ->and(OwnerStatementCalculator::compute($april)['entries']->map(fn ($e) => [$e['kind'], $e['amount']])->all())->toBe([['expense_reversal', 22_000]])
        ->and($april->opening_balance)->toBe('67.000')->and($april->closing_balance)->toBe('89.000');
});

test('a statement is submitted only after the previous one is finalised; rejection returns it to draft', function () {
    $march = ($this->draftFor)('2026-03');
    $april = ($this->draftFor)('2026-04');

    expect(fn () => app(SubmitOwnerStatement::class)->handle($this->finance, $april))->toThrow(ValidationException::class, 'previous statement');

    $approval = app(SubmitOwnerStatement::class)->handle($this->finance, $march);
    expect($march->fresh()->status->value)->toBe('pending_approval');
    app(DecideApproval::class)->handle($this->management, $approval, false, 'Check the expense');
    expect($march->fresh()->status->value)->toBe('draft')->and(OwnerCharge::count())->toBe(0);
});

test('the statement page shows the figures and submits; PDF and Excel downloads are audited', function () {
    $march = ($this->draftFor)('2026-03');

    Livewire::actingAs($this->finance)->test(Show::class, ['statement' => $march])
        ->assertSee('67.000')->assertSee('Pump')->call('submit')->assertHasNoErrors();
    expect($march->fresh()->status->value)->toBe('pending_approval');

    $this->actingAs($this->finance)->get(route('owner-statements.pdf', $march))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->finance)->get(route('owner-statements.export', $march))->assertOk()->assertDownload();
    expect(\Spatie\Activitylog\Models\Activity::where('event', 'owner_statement.exported')->count())->toBe(2);
});
```
(Import `Spatie\Activitylog\Models\Activity` — check the model class the M3a audit tests use and match it.)

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/OwnerStatements/OwnerStatementFinalisationTest.php`
Expected: FAIL — `Class "App\Actions\OwnerStatements\SubmitOwnerStatement" not found`.

- [ ] **Step 3: Policy, submission, approval**

Create `app/Policies/OwnerStatementPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\OwnerContract;
use App\Models\OwnerStatement;
use App\Models\User;

class OwnerStatementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::FinanceView);
    }

    public function view(User $user, OwnerStatement $statement): bool
    {
        return $user->can(PermissionName::FinanceView)
            && OwnerContract::visibleTo($user)->whereKey($statement->owner_contract_id)->exists();
    }

    /** Spec §8.1: disbursements.manage covers owner statements. */
    public function submit(User $user, OwnerStatement $statement): bool
    {
        return $user->can(PermissionName::DisbursementsManage) && $this->view($user, $statement);
    }
}
```
In `app/Enums/ApprovalAction.php` add `case OwnerStatementFinalisation = 'owner_statement.finalise';`, its label `__('Owner statement finalisation')` and handler `OwnerStatementFinalisation::class` (import `App\Approvals\OwnerStatementFinalisation`).

Create `app/Actions/OwnerStatements/SubmitOwnerStatement.php`:
```php
<?php

namespace App\Actions\OwnerStatements;

use App\Actions\Approvals\RequestApproval;
use App\Billing\OwnerStatementCalculator;
use App\Enums\ApprovalAction;
use App\Enums\OwnerStatementStatus;
use App\Models\Approval;
use App\Models\OwnerContract;
use App\Models\OwnerStatement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §7.9: Finance submits a reviewed draft; only after the contract's previous statement is finalised. */
final class SubmitOwnerStatement
{
    public function __construct(private RequestApproval $request) {}

    public function handle(User $actor, OwnerStatement $statement): Approval
    {
        if (! $actor->can('submit', $statement)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $statement) {
            OwnerContract::query()->lockForUpdate()->findOrFail($statement->owner_contract_id);
            $locked = OwnerStatement::query()->lockForUpdate()->findOrFail($statement->id);
            if ($locked->status !== OwnerStatementStatus::Draft) {
                throw ValidationException::withMessages(['statement' => __('Only a draft statement can be submitted.')]);
            }
            $previous = $locked->previous();
            if ($previous !== null && $previous->status !== OwnerStatementStatus::Finalised) {
                throw ValidationException::withMessages(['statement' => __('Finalise the previous statement (:m) first.', ['m' => $previous->period_start->format('M Y')])]);
            }

            OwnerStatementCalculator::apply($locked); // its opening now comes from a finalised statement
            $locked->forceFill(['status' => OwnerStatementStatus::PendingApproval])->save();

            return $this->request->handle($actor, $locked, ApprovalAction::OwnerStatementFinalisation);
        }, attempts: 3);
    }
}
```
Create `app/Approvals/OwnerStatementFinalisation.php`:
```php
<?php

namespace App\Approvals;

use App\Actions\NextDocumentNumber;
use App\Billing\OwnerStatementCalculator;
use App\Enums\NumberSequenceKey;
use App\Enums\OwnerChargeType;
use App\Enums\OwnerStatementStatus;
use App\Jobs\StoreOwnerStatement;
use App\Models\Approval;
use App\Models\OwnerCharge;
use App\Models\OwnerContract;
use App\Models\OwnerStatement;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §7.9, §8.3 item 8. Runs inside DecideApproval's transaction. */
final class OwnerStatementFinalisation implements ApprovalHandler
{
    public function __construct(private NextDocumentNumber $next) {}

    public function creatorId(Approval $approval): int
    {
        return OwnerStatement::query()->findOrFail($approval->approvable_id)->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $statement = $this->lock($approval);
        OwnerStatementCalculator::apply($statement);
        $statement->forceFill([
            'status' => OwnerStatementStatus::Finalised,
            'number' => ($this->next)(NumberSequenceKey::OwnerStatement),
            'finalised_by' => $approver->id,
            'finalised_at' => now(),
        ])->save();

        $fee = Fils::fromDecimal($statement->fee_amount);
        if ($fee > 0) {
            $tax = Fils::fromDecimal($statement->fee_tax);
            OwnerCharge::create([
                'owner_contract_id' => $statement->owner_contract_id,
                'owner_statement_id' => $statement->id,
                'type' => OwnerChargeType::ManagementFee,
                'net' => Fils::toDecimal($fee),
                'tax_amount' => Fils::toDecimal($tax),
                'amount' => Fils::toDecimal(-($fee + $tax)),
                'posted_at' => $statement->cutoff_at,
                'created_by' => $approver->id,
            ]);
        }

        DB::afterCommit(fn () => StoreOwnerStatement::dispatch($statement->id, $approver->id));
    }

    public function reject(Approval $approval, User $approver): void
    {
        $this->lock($approval)->forceFill(['status' => OwnerStatementStatus::Draft])->save();
    }

    private function lock(Approval $approval): OwnerStatement
    {
        $statement = OwnerStatement::query()->findOrFail($approval->approvable_id);
        OwnerContract::query()->lockForUpdate()->findOrFail($statement->owner_contract_id);
        $statement = OwnerStatement::query()->lockForUpdate()->findOrFail($statement->id);
        if ($statement->status !== OwnerStatementStatus::PendingApproval) {
            throw ValidationException::withMessages(['approval' => __('This statement is no longer waiting for approval.')]);
        }

        return $statement;
    }

    public function summary(Approval $approval): string
    {
        $s = OwnerStatement::query()->with('contract.owner')->findOrFail($approval->approvable_id);

        return __('Finalise the :m statement for :owner (:c): fee :fee BHD + VAT :tax, closing balance :close BHD.', [
            'm' => $s->period_start->format('M Y'), 'owner' => $s->contract->owner->name_en, 'c' => $s->contract->number,
            'fee' => $s->fee_amount, 'tax' => $s->fee_tax, 'close' => $s->closing_balance,
        ]);
    }

    public function url(Approval $approval): string
    {
        return route('owner-statements.show', $approval->approvable_id);
    }
}
```
Check `NextDocumentNumber`'s invocation in `MarkDisbursementPaid` (`($this->next)(NumberSequenceKey::PaymentOut)`) and the `ApprovalHandler` interface's methods; match both.

- [ ] **Step 4: PDF and the stored copy**

In `app/Billing/OwnerLedger.php` add:
```php
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'collection' => __('Rent collected (net of VAT)'),
            'collection_reversal' => __('Collection reversed'),
            'deposit_received' => __('Deposit received'),
            'deposit_applied' => __('Deposit applied'),
            'deposit_refunded' => __('Deposit refunded'),
            'deposit_transfer_in', 'deposit_transfer_out' => __('Deposit transferred'),
            'management_fee' => __('Management fee'),
            'opening_balance' => __('Opening balance'),
            'expense' => __('Expense'),
            'expense_reversal' => __('Expense reversed'),
            'remittance' => __('Paid to you'),
            'remittance_reversal' => __('Payment to you reversed'),
            default => str($kind)->headline()->toString(),
        };
    }
```
Create `app/Pdf/OwnerStatementPdf.php`:
```php
<?php

namespace App\Pdf;

use App\Billing\OwnerStatementCalculator;
use App\Enums\DepositsHeldBy;
use App\Enums\OwnerStatementStatus;
use App\Models\CompanySetting;
use App\Models\OwnerStatement;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Spec §9.3: the owner statement, English. A statement not yet finalised carries a DRAFT watermark. */
final class OwnerStatementPdf
{
    public function __construct(private PdfRenderer $renderer) {}

    public function render(OwnerStatement $s): string
    {
        $s->loadMissing(['contract.owner', 'contract.building']);
        $settings = CompanySetting::current();
        $figures = OwnerStatementCalculator::compute($s);
        $balance = $figures['opening'];
        $rows = $figures['entries']->map(function (array $e) use (&$balance) {
            $balance += $e['amount'];

            return [...$e, 'balance' => $balance];
        });

        // Spec §7.9: deposits the company holds are shown for information only.
        $heldByCompany = $s->contract->deposits_held_by === DepositsHeldBy::Company
            ? Fils::fromDecimal((string) (DB::table('deposit_movements')->where('owner_contract_id', $s->owner_contract_id)->where('posted_at', '<=', $s->cutoff_at)->sum('amount') ?: '0'))
            : null;

        return $this->renderer->render('pdf.owner-statement', [
            'statement' => $s,
            'company' => $settings,
            'logo' => $settings->logo_path && Storage::disk('local')->exists($settings->logo_path) ? Storage::disk('local')->path($settings->logo_path) : null,
            'figures' => $figures,
            'rows' => $rows,
            'heldByCompany' => $heldByCompany,
        ], [
            'footer' => '<div style="text-align:center; font-size:8pt; color:#555">'.e($s->label()).' · {PAGENO} / {nbpg}</div>',
            'watermark' => $s->status === OwnerStatementStatus::Finalised ? null : 'DRAFT',
        ]);
    }
}
```
Create `resources/views/pdf/owner-statement.blade.php`:
```blade
<html>
<head>
<style>
    body { font-family: dejavusans; font-size: 10pt; }
    h1 { font-size: 15pt; margin: 0 0 3mm; }
    table { width: 100%; border-collapse: collapse; }
    table.lines td, table.lines th { border-bottom: 0.2mm solid #bbb; padding: 1.5mm; text-align: left; }
    table.lines td.num, table.lines th.num, .num { text-align: right; }
    .muted { color: #555; }
    tr.total td { font-weight: bold; }
</style>
</head>
<body>
<table style="margin-bottom:5mm">
    <tr>
        <td style="width:60%"><strong>{{ $company->name_en }}</strong><br><span class="muted">{{ $company->address_en }}</span></td>
        <td style="width:40%; text-align:right">@if ($logo)<img src="{{ $logo }}" style="height:16mm">@endif</td>
    </tr>
</table>

<h1>{{ __('Owner statement') }} {{ $statement->number }}</h1>
<p>
    <strong>{{ $statement->contract->owner->name_en }}</strong><br>
    {{ __('Contract') }} {{ $statement->contract->number }} — {{ $statement->contract->building->name }}<br>
    {{ __('Period') }} {{ $statement->period_start->format('d/m/Y') }} – {{ $statement->period_end->format('d/m/Y') }}
</p>

<table class="lines">
    <tr><th>{{ __('Date') }}</th><th>{{ __('Entry') }}</th><th>{{ __('Reference') }}</th><th class="num">{{ __('Amount (BHD)') }}</th><th class="num">{{ __('Balance') }}</th></tr>
    <tr><td></td><td>{{ __('Opening balance') }}</td><td></td><td class="num"></td><td class="num">{{ \App\Support\Fils::toDecimal($figures['opening']) }}</td></tr>
    @foreach ($rows as $r)
        <tr>
            <td>{{ \Carbon\CarbonImmutable::parse($r['date'])->format('d/m/Y') }}</td>
            <td>{{ \App\Billing\OwnerLedger::kindLabel($r['kind']) }}</td>
            <td>{{ $r['reference'] }}</td>
            <td class="num">{{ \App\Support\Fils::toDecimal($r['amount']) }}</td>
            <td class="num">{{ \App\Support\Fils::toDecimal($r['balance']) }}</td>
        </tr>
    @endforeach
    <tr><td></td><td>{{ __('Management fee') }}</td><td class="muted">{{ __('on :b BHD', ['b' => $statement->fee_base]) }}</td><td class="num">-{{ $statement->fee_amount }}</td><td class="num"></td></tr>
    @if ((float) $statement->fee_tax > 0)
        <tr><td></td><td>{{ __('VAT on the fee') }}</td><td></td><td class="num">-{{ $statement->fee_tax }}</td><td class="num"></td></tr>
    @endif
    <tr class="total"><td></td><td>{{ __('Closing balance') }}</td><td></td><td class="num"></td><td class="num">{{ \App\Support\Fils::toDecimal($figures['closing']) }}</td></tr>
</table>

@if ($heldByCompany !== null)
    <p class="muted">{{ __('Deposits held by the company on your behalf: :a BHD', ['a' => \App\Support\Fils::toDecimal($heldByCompany)]) }}</p>
@endif
</body>
</html>
```
Create `app/Jobs/StoreOwnerStatement.php` by copying `app/Jobs/StoreReceipt.php` with these changes: constructor `(public int $statementId, public int $userId)`; `handle(OwnerStatementPdf $pdf)`; lock `OwnerStatement` instead of `Payment`; `original_name` = `$statement->number.'.pdf'`; audit properties `['owner_statement' => $statement->number]`; `stored(OwnerStatement $statement): ?Document` with the same query on the statement's morph class.

- [ ] **Step 5: Screens, downloads, navigation**

Create `app/Http/Controllers/OwnerStatementPdfController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Jobs\StoreOwnerStatement;
use App\Models\OwnerStatement;
use App\Pdf\OwnerStatementPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class OwnerStatementPdfController
{
    public function __invoke(Request $request, OwnerStatement $statement, OwnerStatementPdf $pdf): Response
    {
        abort_unless($request->user()?->can('view', $statement), 403);
        Audit::log('owner_statement.exported', $statement, properties: ['format' => 'pdf'], causer: $request->user());

        // A finalised statement is served from its stored copy (spec §7.9); drafts are rendered with a watermark.
        $stored = StoreOwnerStatement::stored($statement);
        if ($stored !== null) {
            return Storage::disk($stored->disk)->response($stored->path, $stored->original_name, ['Content-Type' => 'application/pdf']);
        }

        return response($pdf->render($statement), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.($statement->number ?? 'statement-draft').'.pdf"']);
    }
}
```
Create `app/Http/Controllers/OwnerStatementExportController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Billing\OwnerLedger;
use App\Billing\OwnerStatementCalculator;
use App\Models\OwnerStatement;
use App\Support\Fils;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Spec §10: statements export to Excel. Audited (§8.4). */
final class OwnerStatementExportController
{
    public function __invoke(Request $request, OwnerStatement $statement): BinaryFileResponse
    {
        abort_unless($request->user()?->can('view', $statement), 403);
        Audit::log('owner_statement.exported', $statement, properties: ['format' => 'xlsx'], causer: $request->user());

        $f = OwnerStatementCalculator::compute($statement);
        $path = sys_get_temp_dir().'/rms-statement-'.Str::uuid().'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        $balance = $f['opening'];
        $writer->addRow(['Date' => '', 'Entry' => 'Opening balance', 'Reference' => '', 'Amount' => '', 'Balance' => Fils::toDecimal($balance)]);
        foreach ($f['entries'] as $e) {
            $balance += $e['amount'];
            $writer->addRow(['Date' => $e['date'], 'Entry' => OwnerLedger::kindLabel($e['kind']), 'Reference' => $e['reference'], 'Amount' => Fils::toDecimal($e['amount']), 'Balance' => Fils::toDecimal($balance)]);
        }
        $writer->addRow(['Date' => '', 'Entry' => 'Management fee', 'Reference' => 'on '.Fils::toDecimal($f['fee_base']), 'Amount' => Fils::toDecimal(-$f['fee']), 'Balance' => '']);
        $writer->addRow(['Date' => '', 'Entry' => 'VAT on the fee', 'Reference' => '', 'Amount' => Fils::toDecimal(-$f['fee_tax']), 'Balance' => '']);
        $writer->addRow(['Date' => '', 'Entry' => 'Closing balance', 'Reference' => '', 'Amount' => '', 'Balance' => Fils::toDecimal($f['closing'])]);
        $writer->close();

        return response()->download($path, ($statement->number ?? 'statement-draft').'.xlsx')->deleteFileAfterSend();
    }
}
```
Create `app/Livewire/OwnerStatements/Index.php`:
```php
<?php

namespace App\Livewire\OwnerStatements;

use App\Enums\OwnerStatementStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\OwnerContract;
use App\Models\OwnerStatement;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public ?int $building = null;

    #[Url]
    public ?int $contract = null;

    public function mount(): void
    {
        abort_unless($this->actor()->can('viewAny', OwnerStatement::class), 403);
    }

    public function render(): View
    {
        $contracts = OwnerContract::visibleTo($this->actor())->select('id')
            ->when($this->building, fn ($q, $b) => $q->where('building_id', $b))
            ->when($this->contract, fn ($q, $c) => $q->whereKey($c));

        return view('livewire.owner-statements.index', [
            'statements' => OwnerStatement::query()->with(['contract:id,number,owner_id', 'contract.owner:id,name_en'])
                ->whereIn('owner_contract_id', $contracts)
                ->when($this->status, fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('period_start')->orderBy('id')->paginate(25),
            'buildings' => Building::visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
            'statuses' => OwnerStatementStatus::cases(),
        ])->title(__('Owner statements'));
    }
}
```
Create `resources/views/livewire/owner-statements/index.blade.php`:
```blade
<div class="space-y-4">
    <flux:heading size="xl">{{ __('Owner statements') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-3">
        <flux:select wire:model.live="status" :label="__('Status')">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            @foreach ($statuses as $s)
                <flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="building" :label="__('Building')">
            <flux:select.option value="">{{ __('All buildings') }}</flux:select.option>
            @foreach ($buildings as $b)
                <flux:select.option :value="$b->id">{{ $b->code }} — {{ $b->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$statements">
            <flux:table.columns>
                <flux:table.column>{{ __('Month') }}</flux:table.column>
                <flux:table.column>{{ __('Statement') }}</flux:table.column>
                <flux:table.column>{{ __('Owner') }}</flux:table.column>
                <flux:table.column>{{ __('Closing balance') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($statements as $s)
                    <flux:table.row :key="'os-'.$s->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $s->period_start->format('M Y') }}</flux:table.cell>
                        <flux:table.cell><flux:link :href="route('owner-statements.show', $s)" wire:navigate>{{ $s->label() }}</flux:link> <span class="text-zinc-500">{{ $s->contract->number }}</span></flux:table.cell>
                        <flux:table.cell>{{ $s->contract->owner->name_en }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $s->closing_balance }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $s->status->label() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="5">{{ __('No statements yet.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</div>
```
Create `app/Livewire/OwnerStatements/Show.php`:
```php
<?php

namespace App\Livewire\OwnerStatements;

use App\Actions\OwnerStatements\SubmitOwnerStatement;
use App\Billing\OwnerStatementCalculator;
use App\Livewire\Concerns\WithActor;
use App\Models\OwnerStatement;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $statementId;

    public function mount(OwnerStatement $statement): void
    {
        abort_unless($this->actor()->can('view', $statement), 403);
        $this->statementId = $statement->id;
    }

    protected function statement(): OwnerStatement
    {
        return OwnerStatement::with(['contract.owner', 'contract.building:id,code,name'])->findOrFail($this->statementId);
    }

    public function submit(SubmitOwnerStatement $submit): void
    {
        try {
            $submit->handle($this->actor(), $this->statement());
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::toast(variant: 'success', text: __('Submitted for approval.'));
    }

    public function render(): View
    {
        $statement = $this->statement();
        $figures = OwnerStatementCalculator::compute($statement);
        $balance = $figures['opening'];

        return view('livewire.owner-statements.show', [
            'statement' => $statement,
            'figures' => $figures,
            'rows' => $figures['entries']->map(function (array $e) use (&$balance) {
                $balance += $e['amount'];

                return [...$e, 'balance' => $balance];
            }),
            'canSubmit' => $statement->status->value === 'draft' && $this->actor()->can('submit', $statement),
            'approvals' => $statement->approvals()->with(['requester:id,name', 'decider:id,name'])->latest('id')->get(),
        ])->title($statement->label());
    }
}
```
Create `resources/views/livewire/owner-statements/show.blade.php`:
```blade
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ $statement->label() }}</flux:heading>
            <flux:text>{{ $statement->contract->owner->name_en }} · <flux:link :href="route('owner-contracts.show', $statement->owner_contract_id)" wire:navigate>{{ $statement->contract->number }}</flux:link> · {{ $statement->period_start->format('d/m/Y') }} – {{ $statement->period_end->format('d/m/Y') }}</flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:badge>{{ $statement->status->label() }}</flux:badge>
            <flux:button size="sm" :href="route('owner-statements.pdf', $statement)" target="_blank">{{ __('PDF') }}</flux:button>
            <flux:button size="sm" :href="route('owner-statements.export', $statement)">{{ __('Excel') }}</flux:button>
            @if ($canSubmit)
                <flux:button size="sm" variant="primary" wire:click="submit">{{ __('Submit for approval') }}</flux:button>
            @endif
        </div>
    </div>
    <flux:error name="statement" />

    <div class="grid gap-3 sm:grid-cols-4">
        <flux:card><flux:text>{{ __('Opening') }}</flux:text><flux:heading class="tabular-nums">{{ $statement->opening_balance }}</flux:heading></flux:card>
        <flux:card><flux:text>{{ __('Fee base') }}</flux:text><flux:heading class="tabular-nums">{{ $statement->fee_base }}</flux:heading></flux:card>
        <flux:card><flux:text>{{ __('Fee + VAT') }}</flux:text><flux:heading class="tabular-nums">{{ $statement->fee_amount }} + {{ $statement->fee_tax }}</flux:heading></flux:card>
        <flux:card><flux:text>{{ __('Closing') }}</flux:text><flux:heading class="tabular-nums">{{ $statement->closing_balance }}</flux:heading></flux:card>
    </div>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Entry') }}</flux:table.column>
                <flux:table.column>{{ __('Reference') }}</flux:table.column>
                <flux:table.column>{{ __('Amount') }}</flux:table.column>
                <flux:table.column>{{ __('Balance') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($rows as $i => $r)
                    <flux:table.row :key="'e-'.$i">
                        <flux:table.cell class="whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($r['date'])->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ \App\Billing\OwnerLedger::kindLabel($r['kind']) }}</flux:table.cell>
                        <flux:table.cell>{{ $r['reference'] }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ \App\Support\Fils::toDecimal($r['amount']) }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ \App\Support\Fils::toDecimal($r['balance']) }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="5">{{ __('No entries this month.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    @if ($approvals->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg">{{ __('Approvals') }}</flux:heading>
            @foreach ($approvals as $a)
                <flux:text>{{ $a->status->label() }} — {{ $a->requester->name }} {{ $a->requested_at->format('d/m/Y H:i') }}@if ($a->decider), {{ $a->decider->name }}: {{ $a->comment }}@endif</flux:text>
            @endforeach
        </div>
    @endif
</div>
```
(Check the approvals block in `owner-contracts/show.blade.php` and copy its markup instead if it differs — the field names must match the `Approval` model.)

Add to `routes/property.php`:
```php
    Route::livewire('owner-statements', OwnerStatements\Index::class)->middleware('can:finance.view')->name('owner-statements.index');
    Route::livewire('owner-statements/{statement}', OwnerStatements\Show::class)->middleware('can:finance.view')->name('owner-statements.show');
    Route::get('owner-statements/{statement}/pdf', OwnerStatementPdfController::class)->middleware('can:finance.view')->name('owner-statements.pdf');
    Route::get('owner-statements/{statement}/export', OwnerStatementExportController::class)->middleware('can:finance.view')->name('owner-statements.export');
```
Sidebar, Finance group after "Head-lease due": `<flux:sidebar.item icon="document-chart-bar" :href="route('owner-statements.index')" :current="request()->routeIs('owner-statements.*')" wire:navigate>{{ __('Owner statements') }}</flux:sidebar.item>`. On the owner contract page, for managed contracts and `finance.view`, add next to the heading actions: `<flux:button size="sm" :href="route('owner-statements.index', ['contract' => $contract->id])" wire:navigate>{{ __('Statements') }}</flux:button>`.

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan test tests/Feature/OwnerStatements tests/Feature/Approvals tests/Feature/Pdf
"$PHP" artisan test
```
Expected: every test passes. Then render one statement PDF and look at it (as in M3a Task 9): totals right-aligned, DRAFT watermark on a draft, no watermark on a finalised one.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Finalise owner statements with Management approval

Finance submits a draft once the previous month is finalised.
Approval numbers it, writes the management fee charge at the cutoff
and stores the PDF. Statement list, page, PDF and Excel export.
EOF
```

---
### Task 7: Remittances to owners

**Spec:** §7.9 (Finance records a disbursement to the owner, source = the statement, for up to the live owner-ledger balance; payments to the owner post at `posted_at`, a reversal as an opposite entry at `reversed_at`), §7.5 (with the source and the owner_contracts row locked: Σ non-reversed remittances ≤ the live balance; the payee copied from the source; anything else → item 9), §4.4 (for 30 days after a bank change the remittance screen shows "Bank details changed {date} by {user}"), §14 flow 14 ("a second remittance beyond the owner-ledger balance … requires approval"), plan ruling 5

**Files:**
- Create: `tests/Feature/Disbursements/OwnerRemittanceTest.php`
- Modify: `app/Actions/Disbursements/{RecordDisbursement,LockOwnerSource}.php`, `app/Models/Disbursement.php`, `app/Billing/OwnerLedger.php`, `app/Livewire/OwnerStatements/Show.php`, `resources/views/livewire/owner-statements/show.blade.php`

**Interfaces:**
- Consumes: `OwnerLedger::balance()` (Task 4), `OwnerStatement` (Task 5), `LockOwnerSource` (Task 3)
- Produces:
  - `RecordDisbursement::handle($actor, ['purpose' => 'owner_remittance', 'owner_statement_id' => int, 'amount', 'method', 'paid_on', …])`
  - `Disbursement::SOURCE_STATEMENT = 'owner_statement'`
  - ledger kinds `remittance`, `remittance_reversal`
  - `OwnerLedger::remittableFils(OwnerContract $contract): int` (live balance − remittances waiting for approval or payment)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Disbursements/OwnerRemittanceTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\EnsureNumberSequences;
use App\Actions\OwnerStatements\DraftOwnerStatements;
use App\Actions\OwnerStatements\SubmitOwnerStatement;
use App\Actions\Payments\RecordPayment;
use App\Billing\OwnerLedger;
use App\Billing\OwnerStatementCalculator;
use App\Enums\RoleName;
use App\Livewire\OwnerStatements\Show;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $building = Building::factory()->create();
    $unit = Unit::factory()->for($building)->create();
    $this->contract = activeOwnerContract(['building_id' => $building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'percent_collected', 'fee_value' => '10.000'], [$unit]);
    $customer = Customer::factory()->create();
    $agreement = activeAgreement(['customer_id' => $customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$unit]);
    issuedInvoice($customer, [['net' => '100.000', 'tax' => 'standard', 'au' => $agreement->agreementUnits()->sole()]], '2026-03-01', $agreement);
    app(RecordPayment::class)->handle($this->finance, $customer, ['received_on' => '2026-03-10', 'method' => 'cash', 'amount' => '110.000']);
    $this->travelTo(CarbonImmutable::parse('2026-04-01 04:00', 'Asia/Bahrain'));
    app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-03-01'));
    $this->march = OwnerStatement::where('owner_contract_id', $this->contract->id)->sole(); // closing 89.000 = 100 − 11
    $this->travelTo(CarbonImmutable::parse('2026-04-03 10:00', 'Asia/Bahrain'));
    $this->remit = fn (string $amount, ?OwnerStatement $s = null) => app(RecordDisbursement::class)->handle($this->finance, [
        'purpose' => 'owner_remittance', 'owner_statement_id' => ($s ?? $this->march)->id, 'amount' => $amount, 'method' => 'bank_transfer', 'paid_on' => '2026-04-03',
    ]);
});

test('only a finalised statement can be remitted', function () {
    expect(fn () => ($this->remit)('89.000'))->toThrow(ValidationException::class, 'finalised');
});

test('§14 flow 14: within the live balance it is paid at once; beyond it, it needs approval', function () {
    app(DecideApproval::class)->handle($this->management, app(SubmitOwnerStatement::class)->handle($this->finance, $this->march), true);

    $out = ($this->remit)('89.000');
    expect($out->status->value)->toBe('paid')->and($out->payee_id)->toBe($this->contract->owner_id)
        ->and(OwnerLedger::balance($this->contract))->toBe(0);

    expect(($this->remit)('1.000')->status->value)->toBe('pending_approval');
});

test('remittances waiting for approval count against the limit', function () {
    app(DecideApproval::class)->handle($this->management, app(SubmitOwnerStatement::class)->handle($this->finance, $this->march), true);

    expect(($this->remit)('100.000')->status->value)->toBe('pending_approval') // above 89
        ->and(($this->remit)('10.000')->status->value)->toBe('pending_approval')  // 89 − 100 waiting < 10
        ->and(OwnerLedger::remittableFils($this->contract))->toBe(-21_000);
});

test('a remittance posts on the next statement; its reversal posts at the reversal time', function () {
    app(DecideApproval::class)->handle($this->management, app(SubmitOwnerStatement::class)->handle($this->finance, $this->march), true);
    $out = ($this->remit)('89.000');
    $this->travel(2)->days();
    app(DecideApproval::class)->handle($this->management, app(RequestDisbursementReversal::class)->handle($this->finance, $out, 'Bounced by the bank'), true);

    $this->travelTo(CarbonImmutable::parse('2026-05-01 04:00', 'Asia/Bahrain'));
    app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-04-01'));
    $april = OwnerStatement::where('owner_contract_id', $this->contract->id)->where('period_start', '2026-04-01')->sole();

    expect(OwnerStatementCalculator::compute($april)['entries']->map(fn ($e) => [$e['kind'], $e['date'], $e['amount']])->all())->toBe([
        ['remittance', '2026-04-03', -89_000],
        ['remittance_reversal', '2026-04-05', 89_000],
    ])->and($april->closing_balance)->toBe('89.000');
});

test('the statement page remits and warns about a recent bank change', function () {
    app(DecideApproval::class)->handle($this->management, app(SubmitOwnerStatement::class)->handle($this->finance, $this->march), true);
    $this->contract->owner->forceFill(['bank_changed_at' => now()->subDays(3), 'bank_changed_by' => $this->management->id])->save();

    Livewire::actingAs($this->finance)->test(Show::class, ['statement' => $this->march->fresh()])
        ->assertSee('Bank details changed')->assertSee($this->management->name)
        ->assertSet('remittance.amount', '89.000')
        ->set('remittance.paid_on', '2026-04-03')
        ->call('remit')->assertHasNoErrors();

    expect(OwnerLedger::balance($this->contract))->toBe(0);
});
```
(The March closing balance here is 89.000: no expense in this setup.)

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Disbursements/OwnerRemittanceTest.php`
Expected: FAIL — `purpose` validation error.

- [ ] **Step 3: Ledger entries and the limit**

In `app/Models/Disbursement.php` add `public const string SOURCE_STATEMENT = 'owner_statement';` and the `source()` arm `self::SOURCE_STATEMENT => OwnerStatement::query()->find($this->source_id),`.

In `app/Billing/OwnerLedger.php` `all()`, before `return $entries;`:
```php
        // Payments to the owner (remittances against the contract's statements); a reversal at reversed_at.
        foreach (DB::table('disbursements as d')->join('owner_statements as s', 's.id', '=', 'd.source_id')
            ->where('d.source_type', 'owner_statement')->where('d.purpose', 'owner_remittance')->where('s.owner_contract_id', $contract->id)
            ->whereIn('d.status', ['paid', 'reversed'])->get(['d.number', 'd.amount', 'd.paid_on', 'd.posted_at', 'd.reversed_at']) as $r) {
            $amount = Fils::fromDecimal((string) $r->amount);
            $entries->push(['posted_at' => $at($r->posted_at), 'date' => (string) $r->paid_on, 'kind' => 'remittance', 'reference' => (string) $r->number, 'amount' => -$amount]);
            if ($r->reversed_at !== null) {
                $entries->push(['posted_at' => $at($r->reversed_at), 'date' => $at($r->reversed_at)->toDateString(), 'kind' => 'remittance_reversal', 'reference' => (string) $r->number, 'amount' => $amount]);
            }
        }
```
and the method:
```php
    /** Plan ruling 5: what can be remitted now without approval — the live balance less remittances not yet paid. */
    public static function remittableFils(OwnerContract $contract): int
    {
        $waiting = Fils::fromDecimal((string) (DB::table('disbursements as d')->join('owner_statements as s', 's.id', '=', 'd.source_id')
            ->where('d.source_type', 'owner_statement')->where('d.purpose', 'owner_remittance')->where('s.owner_contract_id', $contract->id)
            ->whereIn('d.status', ['pending_approval', 'approved'])->sum('d.amount') ?: '0'));

        return self::balance($contract) - $waiting;
    }
```

- [ ] **Step 4: Record the remittance**

In `app/Actions/Disbursements/LockOwnerSource.php` add the match arm `Disbursement::SOURCE_STATEMENT => OwnerStatement::query()->whereKey($out->source_id)->value('owner_contract_id'),` and, after locking the contract, `if ($out->source_type === Disbursement::SOURCE_STATEMENT) { OwnerStatement::query()->lockForUpdate()->findOrFail($out->source_id); }`.

In `app/Actions/Disbursements/RecordDisbursement.php`:
- add `DisbursementPurpose::OwnerRemittance->value` to the `purpose` rule and to the `$chequeNow` purposes;
- add the rule `'owner_statement_id' => ['required_if:purpose,owner_remittance', 'nullable', 'integer', Rule::exists('owner_statements', 'id')],`;
- add the match arm `DisbursementPurpose::OwnerRemittance->value => $this->remittance($actor, $v, $amount),`;
- add the method:
```php
    /** @param  array<string, mixed>  $v */
    private function remittance(User $actor, array $v, int $amount): Disbursement
    {
        $statement = OwnerStatement::query()->findOrFail((int) $v['owner_statement_id']);
        $contract = OwnerContract::query()->lockForUpdate()->findOrFail($statement->owner_contract_id); // owner-side first lock (spec §7.5)
        if (! OwnerContract::visibleTo($actor)->whereKey($contract->id)->exists()) {
            throw new AuthorizationException;
        }
        $statement = OwnerStatement::query()->lockForUpdate()->findOrFail($statement->id);
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
```
(imports: `App\Billing\OwnerLedger`, `App\Enums\OwnerStatementStatus`, `App\Models\OwnerStatement`.) `RecordDisbursement::handle`'s closure locks nothing before calling these methods, so the owner contract is the first lock.

- [ ] **Step 5: The remittance form**

In `app/Livewire/OwnerStatements/Show.php` add:
```php
    /** @var array<string, mixed> */
    public array $remittance = [];

    public function mount(OwnerStatement $statement): void
    {
        abort_unless($this->actor()->can('view', $statement), 403);
        $this->statementId = $statement->id;
        $this->resetRemittance();
    }

    private function resetRemittance(): void
    {
        $this->remittance = [
            'amount' => Fils::toDecimal(max(0, OwnerLedger::remittableFils($this->statement()->contract))),
            'method' => DisbursementMethod::BankTransfer->value,
            'paid_on' => now('Asia/Bahrain')->toDateString(),
        ];
    }

    public function remit(RecordDisbursement $record): void
    {
        try {
            $out = $record->handle($this->actor(), [...$this->remittance, 'purpose' => 'owner_remittance', 'owner_statement_id' => $this->statementId]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["remittance.$k" => $m])->all());
        }

        $this->resetRemittance();
        Flux::toast(variant: 'success', text: $out->status->value === 'paid' ? __('Paid as :n.', ['n' => $out->number]) : __('Sent to Management for approval.'));
    }
```
(replacing the existing `mount`), and in `render()` add `'canRemit' => $statement->status->value === 'finalised' && $this->actor()->can('create', Disbursement::class)`, `'live' => OwnerLedger::balance($statement->contract)`, `'methods' => DisbursementMethod::cases()`, and eager-load `contract.owner.bankChanger:id,name`. In the view, after the figures cards:
```blade
    @if ($canRemit)
        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('Pay the owner') }}</flux:heading>
            <flux:text>{{ __('Owner ledger balance now: :b BHD', ['b' => \App\Support\Fils::toDecimal($live)]) }}</flux:text>
            <flux:text>{{ $statement->contract->owner->bank_name }} · {{ $statement->contract->owner->maskedIban() }} · {{ $statement->contract->owner->account_name }}</flux:text>
            @if ($statement->contract->owner->bankChangedRecently())
                <flux:callout variant="warning" icon="exclamation-triangle">
                    {{ __('Bank details changed :d by :u', ['d' => $statement->contract->owner->bank_changed_at->format('d/m/Y'), 'u' => $statement->contract->owner->bankChanger?->name]) }}
                </flux:callout>
            @endif
            <form wire:submit="remit" class="grid gap-3 sm:grid-cols-2">
                <flux:input wire:model="remittance.amount" :label="__('Amount (BHD)')" inputmode="decimal" />
                <flux:select wire:model.live="remittance.method" :label="__('Method')">
                    @foreach ($methods as $m)
                        <flux:select.option :value="$m->value">{{ $m->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input type="date" wire:model="remittance.paid_on" :label="__('Paid on')" />
                <flux:input wire:model="remittance.reference" :label="__('Reference')" />
                @if (($remittance['method'] ?? null) === 'cheque')
                    <flux:input wire:model="remittance.cheque_no" :label="__('Cheque number')" />
                    <flux:input wire:model="remittance.bank_name" :label="__('Bank')" />
                    <flux:input type="date" wire:model="remittance.cheque_date" :label="__('Cheque date')" />
                @endif
                <flux:error name="remittance.owner_statement_id" />
                <div class="sm:col-span-2 flex justify-end"><flux:button type="submit" variant="primary">{{ __('Record payment') }}</flux:button></div>
            </form>
        </flux:card>
    @endif
```
Check the `Owner` model's `bankChanger()` / `bankChangedRecently()` / `maskedIban()` (M1) and use them as they are.

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Disbursements tests/Feature/OwnerStatements tests/Feature/Owners
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Remit owner balances against finalised statements

A remittance up to the live owner-ledger balance, less remittances
still waiting, is paid at once; above it goes to Management. Payments
to the owner and their reversals post on the ledger. The statement
page warns about a recent bank change.
EOF
```

---

### Task 8: Building profitability report and exports

**Spec:** §10 (every report filters by building and date range, respects building assignment, exports to Excel; building profitability per building, cash basis, with a billed column: income = net-of-VAT allocations on non-deposit lines stamped NULL or with a leased contract + finalised management fees (net) of the building's managed contracts; costs = head-lease disbursements for its leased contracts + expenses charged to the company or to tenants; result = income − costs; head-lease payments due is a financial report), §8.4 (exports audited), plan ruling 9

**Files:**
- Create: `app/Reports/BuildingProfitability.php`, `app/Livewire/Reports/BuildingProfitability.php` (class `BuildingProfitabilityReport`), `resources/views/livewire/reports/building-profitability.blade.php`, `tests/Feature/Reports/BuildingProfitabilityTest.php`
- Modify: `app/Livewire/OwnerPayables/Index.php`, `resources/views/livewire/owner-payables/index.blade.php`, `routes/property.php`, `resources/views/layouts/app/sidebar.blade.php`

**Interfaces:**
- Produces:
  - `BuildingProfitability::for(Building $building, string $from, string $to): array{collected: int, fees: int, income: int, billed: int, head_lease: int, expenses: int, costs: int, result: int}` (fils; dates `Y-m-d`, inclusive)
  - route `reports.building-profitability` (`reports/building-profitability`, `can:reports.financial`)
  - `OwnerPayables\Index::export()` (Excel, `reports.financial`)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Reports/BuildingProfitabilityTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\EnsureNumberSequences;
use App\Actions\Expenses\RecordExpense;
use App\Actions\OwnerStatements\DraftOwnerStatements;
use App\Actions\OwnerStatements\SubmitOwnerStatement;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Livewire\OwnerPayables\Index as PayablesIndex;
use App\Livewire\Reports\BuildingProfitabilityReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerPayable;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\User;
use App\Reports\BuildingProfitability;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-03-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->building = Building::factory()->create();
    [$owned, $leasedUnit, $managedUnit] = Unit::factory()->for($this->building)->count(3)->create()->all();
    $leased = activeOwnerContract(['building_id' => $this->building->id, 'type' => 'leased', 'rent_amount' => '50.000', 'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null, 'start_date' => '2026-03-01', 'end_date' => '2027-02-28'], [$leasedUnit]);
    $managed = activeOwnerContract(['building_id' => $this->building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'percent_collected', 'fee_value' => '10.000'], [$managedUnit]);

    // Head lease: one payable of 50, paid 5 March.
    $payable = (new OwnerPayable)->forceFill(['owner_contract_id' => $leased->id, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'due_date' => '2026-03-01', 'amount' => '50.000', 'status' => 'scheduled']);
    $payable->save();
    app(RecordDisbursement::class)->handle($this->finance, ['purpose' => 'head_lease', 'owner_payable_id' => $payable->id, 'amount' => '50.000', 'method' => 'bank_transfer', 'paid_on' => '2026-03-05']);

    // Rent of 100 + VAT on each unit and a deposit on the owned one, all paid on 10 March.
    $customer = Customer::factory()->create();
    $agreement = activeAgreement(['customer_id' => $customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$owned, $leasedUnit, $managedUnit]);
    $au = $agreement->agreementUnits()->orderBy('id')->get();
    issuedInvoice($customer, [
        ['net' => '100.000', 'tax' => 'standard', 'au' => $au[0]], ['net' => '100.000', 'tax' => 'standard', 'au' => $au[1]],
        ['net' => '100.000', 'tax' => 'standard', 'au' => $au[2]], ['type' => 'deposit', 'net' => '200.000', 'au' => $au[0]],
    ], '2026-03-01', $agreement);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00', 'Asia/Bahrain'));
    app(RecordPayment::class)->handle($this->finance, $customer, ['received_on' => '2026-03-10', 'method' => 'cash', 'amount' => '530.000']);

    // Expenses: 30 + 3 VAT to the company (counts 30, VAT registered); 20 to the owner (not the company's cost).
    app(RecordExpense::class)->handle($this->finance, ['building_id' => $this->building->id, 'category' => 'cleaning', 'description' => 'Lobby', 'expense_date' => '2026-03-10', 'net' => '30.000', 'tax_amount' => '3.000', 'charge_to' => 'company']);
    app(RecordExpense::class)->handle($this->finance, ['building_id' => $this->building->id, 'unit_id' => $managedUnit->id, 'category' => 'maintenance', 'description' => 'Pump', 'expense_date' => '2026-03-10', 'net' => '20.000', 'charge_to' => 'owner']);

    // The managed contract's March statement, finalised: fee 10.000 net.
    $this->travelTo(CarbonImmutable::parse('2026-04-01 04:00', 'Asia/Bahrain'));
    app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-03-01'));
    app(DecideApproval::class)->handle($management, app(SubmitOwnerStatement::class)->handle($this->finance, OwnerStatement::sole()), true);
    $this->travelTo(CarbonImmutable::parse('2026-04-02 09:00', 'Asia/Bahrain'));
});

test('income is the owned and leased units\' collections net of VAT plus managed fees; costs are head lease and company expenses', function () {
    expect(BuildingProfitability::for($this->building, '2026-03-01', '2026-03-31'))->toBe([
        'collected' => 200_000,  // owned + leased rent, net of VAT; managed rent and the deposit excluded
        'fees' => 10_000,
        'income' => 210_000,
        'billed' => 210_000,     // 200 billed + the fee
        'head_lease' => 50_000,
        'expenses' => 30_000,
        'costs' => 80_000,
        'result' => 130_000,
    ])->and(BuildingProfitability::for($this->building, '2026-04-01', '2026-04-30')['result'])->toBe(0);
});

test('the report page shows each building and exports to Excel, audited', function () {
    Livewire::actingAs($this->finance)->test(BuildingProfitabilityReport::class)
        ->set('from', '2026-03-01')->set('to', '2026-03-31')
        ->assertSee($this->building->code)->assertSee('130.000')
        ->call('export')->assertFileDownloaded();

    Livewire::actingAs($this->finance)->test(PayablesIndex::class)->call('export')->assertFileDownloaded();

    expect(Activity::where('event', 'report.exported')->count())->toBe(2);
});
```
Check that `RecordExpense` resolves a building-level company expense without `unit_id` and an owner expense from `unit_id`; adapt the arrays, not the Action.

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Reports/BuildingProfitabilityTest.php`
Expected: FAIL — `Class "App\Reports\BuildingProfitability" not found`.

- [ ] **Step 3: The calculation**

Create `app/Reports/BuildingProfitability.php`:
```php
<?php

namespace App\Reports;

use App\Models\Building;
use App\Models\CompanySetting;
use App\Support\Fils;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Spec §10: per building, cash basis, with a billed column. Income: net-of-VAT allocations on non-deposit lines of the
 * building's owned (unstamped) and leased units + finalised management fees (net) of its managed contracts. Costs:
 * head-lease payments for its leased contracts + expenses charged to the company or to tenants (plan ruling 9).
 * Lines without a unit (some manual invoices) cannot be placed in a building and are left out.
 */
final class BuildingProfitability
{
    /** @return array{collected: int, fees: int, income: int, billed: int, head_lease: int, expenses: int, costs: int, result: int} */
    public static function for(Building $building, string $from, string $to): array
    {
        $start = $from.' 00:00:00';
        $end = $to.' 23:59:59';
        $sum = fn (Builder $q, string $expr) => Fils::fromDecimal((string) ($q->sum(DB::raw($expr)) ?: '0'));

        // The building's company-earned lines: unstamped (owned) or stamped with a leased contract, never deposits.
        $companyLines = fn (Builder $q) => $q->join('units as u', 'u.id', '=', 'l.unit_id')
            ->leftJoin('owner_contracts as oc', 'oc.id', '=', 'l.owner_contract_id')
            ->where('u.building_id', $building->id)->where('l.charge_type', '<>', 'deposit')
            ->where(fn ($w) => $w->whereNull('l.owner_contract_id')->orWhere('oc.type', 'leased'));

        $collected = $sum($companyLines(DB::table('payment_allocations as a')->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id'))
            ->whereBetween('a.posted_at', [$start, $end]), 'a.amount - a.tax_amount');

        $fees = $sum(DB::table('owner_charges as c')->join('owner_contracts as oc', 'oc.id', '=', 'c.owner_contract_id')
            ->where('oc.building_id', $building->id)->where('c.type', 'management_fee')->whereBetween('c.posted_at', [$start, $end]), 'c.net');

        $billedLines = fn (bool $credit) => $sum($companyLines(DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id'))
            ->where('i.status', 'issued')->where('i.type', $credit ? '=' : '<>', 'credit_note')->whereBetween('i.issue_date', [$from, $to]), 'l.net');

        $headLease = $sum(DB::table('disbursements as d')->join('owner_payables as p', 'p.id', '=', 'd.source_id')
            ->join('owner_contracts as oc', 'oc.id', '=', 'p.owner_contract_id')
            ->where('d.source_type', 'owner_payable')->where('d.purpose', 'head_lease')->where('d.status', 'paid')
            ->where('oc.building_id', $building->id)->whereBetween('d.paid_on', [$from, $to]), 'd.amount');

        // A VAT-registered company recovers the VAT on its costs; otherwise the VAT is a cost too.
        $expenses = $sum(DB::table('expenses')->where('building_id', $building->id)->whereIn('charge_to', ['company', 'tenant'])
            ->where('status', 'recorded')->whereBetween('expense_date', [$from, $to]), CompanySetting::current()->vat_registered ? 'net' : 'total');

        $income = $collected + $fees;
        $costs = $headLease + $expenses;

        return [
            'collected' => $collected,
            'fees' => $fees,
            'income' => $income,
            'billed' => $billedLines(false) - $billedLines(true) + $fees,
            'head_lease' => $headLease,
            'expenses' => $expenses,
            'costs' => $costs,
            'result' => $income - $costs,
        ];
    }
}
```

- [ ] **Step 4: The page and the exports**

Create `app/Livewire/Reports/BuildingProfitabilityReport.php`:
```php
<?php

namespace App\Livewire\Reports;

use App\Audit\Audit;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Reports\BuildingProfitability;
use App\Support\Fils;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BuildingProfitabilityReport extends Component
{
    use WithActor;

    #[Url]
    public ?int $building = null;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can('reports.financial'), 403);
        $this->from = $this->from ?: now('Asia/Bahrain')->startOfMonth()->toDateString();
        $this->to = $this->to ?: now('Asia/Bahrain')->toDateString();
    }

    /** @return Collection<int, array{building: Building, figures: array<string, int>}> */
    private function rows(): Collection
    {
        $this->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return Building::visibleTo($this->actor())->when($this->building, fn ($q, $b) => $q->whereKey($b))->orderBy('code')->get()
            ->map(fn (Building $b) => ['building' => $b, 'figures' => BuildingProfitability::for($b, $this->from, $this->to)]);
    }

    public function export(): BinaryFileResponse
    {
        abort_unless($this->actor()->can('reports.financial'), 403);
        Audit::log('report.exported', properties: ['report' => 'building_profitability', 'from' => $this->from, 'to' => $this->to, 'building' => $this->building], causer: $this->actor());

        $path = sys_get_temp_dir().'/rms-profitability-'.Str::uuid().'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        foreach ($this->rows() as $r) {
            $writer->addRow(['Building' => $r['building']->code.' '.$r['building']->name, ...collect($r['figures'])->mapWithKeys(fn (int $v, string $k) => [str($k)->headline()->toString() => Fils::toDecimal($v)])->all()]);
        }
        $writer->close();

        return response()->download($path, "building-profitability-{$this->from}-{$this->to}.xlsx")->deleteFileAfterSend();
    }

    public function render(): View
    {
        return view('livewire.reports.building-profitability', [
            'rows' => $this->rows(),
            'buildings' => Building::visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
        ])->title(__('Building profitability'));
    }
}
```
Create `resources/views/livewire/reports/building-profitability.blade.php`:
```blade
<div class="space-y-4">
    <flux:heading size="xl">{{ __('Building profitability') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-4">
        <flux:select wire:model.live="building" :label="__('Building')">
            <flux:select.option value="">{{ __('All buildings') }}</flux:select.option>
            @foreach ($buildings as $b)
                <flux:select.option :value="$b->id">{{ $b->code }} — {{ $b->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input type="date" wire:model.live="from" :label="__('From')" />
        <flux:input type="date" wire:model.live="to" :label="__('To')" />
        <div class="flex items-end"><flux:button wire:click="export">{{ __('Export to Excel') }}</flux:button></div>
    </div>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Building') }}</flux:table.column>
                <flux:table.column>{{ __('Collected') }}</flux:table.column>
                <flux:table.column>{{ __('Fees') }}</flux:table.column>
                <flux:table.column>{{ __('Income') }}</flux:table.column>
                <flux:table.column>{{ __('Billed') }}</flux:table.column>
                <flux:table.column>{{ __('Head lease') }}</flux:table.column>
                <flux:table.column>{{ __('Expenses') }}</flux:table.column>
                <flux:table.column>{{ __('Result') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($rows as $r)
                    <flux:table.row :key="'b-'.$r['building']->id">
                        <flux:table.cell>{{ $r['building']->code }} <span class="text-zinc-500">{{ $r['building']->name }}</span></flux:table.cell>
                        @foreach (['collected', 'fees', 'income', 'billed', 'head_lease', 'expenses', 'result'] as $k)
                            <flux:table.cell class="text-end tabular-nums">{{ \App\Support\Fils::toDecimal($r['figures'][$k]) }}</flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
    <flux:text class="text-sm">{{ __('Cash basis: income by payment posting, net of VAT; head lease by payment date; expenses by expense date. Billed shows invoices issued less credit notes, for comparison.') }}</flux:text>
</div>
```
Add the route to `routes/property.php`:
```php
    Route::livewire('reports/building-profitability', Reports\BuildingProfitabilityReport::class)->middleware('can:reports.financial')->name('reports.building-profitability');
```
and to the sidebar, after the Finance group:
```blade
                @can('reports.financial')
                    <flux:sidebar.group :heading="__('Reports')" class="grid">
                        <flux:sidebar.item icon="chart-bar" :href="route('reports.building-profitability')" :current="request()->routeIs('reports.building-profitability')" wire:navigate>{{ __('Building profitability') }}</flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan
```
In `app/Livewire/OwnerPayables/Index.php` add (reusing the `render()` query — move it into a private `rows()` method that both call):
```php
    public function export(): BinaryFileResponse
    {
        abort_unless($this->actor()->can('reports.financial'), 403);
        Audit::log('report.exported', properties: ['report' => 'head_lease_due', 'due_by' => $this->dueBy, 'building' => $this->building], causer: $this->actor());

        $path = sys_get_temp_dir().'/rms-head-lease-'.Str::uuid().'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        foreach ($this->rows() as $p) {
            $writer->addRow(['Due' => $p->due_date->toDateString(), 'Contract' => $p->contract->number, 'Building' => $p->contract->building->code,
                'Owner' => $p->contract->owner->name_en, 'From' => $p->period_start->toDateString(), 'To' => $p->period_end->toDateString(), 'Amount' => $p->amount]);
        }
        $writer->close();

        return response()->download($path, "head-lease-due-{$this->dueBy}.xlsx")->deleteFileAfterSend();
    }
```
and in its view, beside the filters: `@can('reports.financial')<div class="flex items-end"><flux:button wire:click="export">{{ __('Export to Excel') }}</flux:button></div>@endcan`.

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Reports tests/Feature/Disbursements
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the building profitability report and Excel exports

Per building and date range: collections net of VAT on owned and
leased units plus finalised management fees, less head-lease payments
and company expenses, with billed figures alongside. The report and
the head-lease payments due list export to Excel, audited.
EOF
```

---

### Task 9: Permission matrix and integrity checks

**Spec:** §14 flow 11 (permission matrix), §8.1 (`finance.view`, `disbursements.manage` covers owner statements, `reports.financial`, `approvals.decide`), §7.11 (nightly integrity check), §7.5, §7.9

**Files:**
- Create: `tests/Feature/Permissions/OwnerFinancePermissionMatrixTest.php`
- Modify: `app/Integrity/IntegrityCheck.php`, `tests/Feature/Integrity/IntegrityCheckTest.php`

**Interfaces:**
- Consumes: everything above

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Permissions/OwnerFinancePermissionMatrixTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\EnsureNumberSequences;
use App\Actions\OwnerStatements\DraftOwnerStatements;
use App\Actions\OwnerStatements\SubmitOwnerStatement;
use App\Enums\RoleName as R;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\OwnerCharge;
use App\Models\OwnerPayable;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Spec §14 flow 11 for M4. Every user is assigned the fixture building.

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-04-02 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);

    $this->building = Building::factory()->create();
    [$u1, $u2, $u3] = Unit::factory()->for($this->building)->count(3)->create()->all();
    $leased = activeOwnerContract(['building_id' => $this->building->id, 'type' => 'leased', 'rent_amount' => '50.000', 'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null, 'start_date' => '2026-04-01', 'end_date' => '2027-03-31'], [$u1]);
    $this->payable = (new OwnerPayable)->forceFill(['owner_contract_id' => $leased->id, 'period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'due_date' => '2026-04-01', 'amount' => '50.000', 'status' => 'scheduled']);
    $this->payable->save();
    $managed = activeOwnerContract(['building_id' => $this->building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'fixed', 'fee_value' => '10.000'], [$u2]);
    OwnerCharge::create(['owner_contract_id' => $managed->id, 'type' => 'opening_balance', 'net' => '500.000', 'tax_amount' => '0.000', 'amount' => '500.000', 'posted_at' => '2026-02-15 10:00:00', 'created_by' => $managed->created_by]);
    $other = activeOwnerContract(['building_id' => $this->building->id, 'type' => 'managed', 'start_date' => '2026-02-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'fixed', 'fee_value' => '10.000'], [$u3]);
    app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-02-01'));
    app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-03-01'));
    $finance = matrixUser(R::Finance, $this->building);
    [$this->february, $this->march] = OwnerStatement::where('owner_contract_id', $managed->id)->orderBy('period_start')->get()->all();
    $this->draft = OwnerStatement::where('owner_contract_id', $other->id)->where('period_start', '2026-02-01')->sole(); // a first statement: nothing before it
    app(DecideApproval::class)->handle(matrixUser(R::Management, $this->building), app(SubmitOwnerStatement::class)->handle($finance, $this->february), true);
    $this->pending = app(SubmitOwnerStatement::class)->handle($finance, $this->march);
});

$view = [R::Admin, R::Management, R::Finance, R::VendorSupport];

test('pages open for exactly the roles the spec allows', function (string $route, Closure $params, array $allowed) {
    foreach (R::cases() as $role) {
        $status = $this->actingAs(matrixUser($role, $this->building))->get(route($route, $params->call($this)))->status();

        expect($status === 200)->toBe(in_array($role, $allowed, true), "{$route} as {$role->value} gave {$status}");
    }
})->with([
    ['owner-payables.index', fn () => [], $view],
    ['owner-statements.index', fn () => [], $view],
    ['owner-statements.show', fn () => [$this->february], $view],
    ['owner-statements.pdf', fn () => [$this->february], $view],
    ['owner-statements.export', fn () => [$this->february], $view],
    ['reports.building-profitability', fn () => [], $view],
]);

test('each protected Action allows exactly the spec roles', function (string $name, Closure $run, array $allowed) {
    foreach (R::cases() as $role) {
        $user = matrixUser($role, $this->building);
        $rollback = new RuntimeException('matrix rollback');
        try {
            DB::transaction(function () use ($run, $user, $rollback) {
                $run->call($this, $user);
                throw $rollback;
            });
            $outcome = 'no rollback';
        } catch (AuthorizationException) {
            $outcome = 'denied';
        } catch (ValidationException $e) {
            $outcome = 'refused: '.json_encode($e->errors());
        } catch (RuntimeException $e) {
            $outcome = $e === $rollback ? 'ran' : throw $e;
        }

        $ran = $outcome === 'ran';
        expect($ran)->toBe(in_array($role, $allowed, true), "{$name} as {$role->value}: {$outcome}")
            ->and($ran || $outcome === 'denied')->toBeTrue("{$name} as {$role->value}: {$outcome}");
    }
})->with([
    ['head-lease payment', fn (User $u) => app(RecordDisbursement::class)->handle($u, ['purpose' => 'head_lease', 'owner_payable_id' => $this->payable->id, 'amount' => '50.000', 'method' => 'cash', 'paid_on' => '2026-04-02']), [R::Finance]],
    ['remittance', fn (User $u) => app(RecordDisbursement::class)->handle($u, ['purpose' => 'owner_remittance', 'owner_statement_id' => $this->february->id, 'amount' => '1.000', 'method' => 'cash', 'paid_on' => '2026-04-02']), [R::Finance]],
    ['submit statement', fn (User $u) => app(SubmitOwnerStatement::class)->handle($u, $this->draft), [R::Finance]],
    ['finalise statement', fn (User $u) => app(DecideApproval::class)->handle($u, $this->pending, true), [R::Management]],
]);
```
In `tests/Feature/Integrity/IntegrityCheckTest.php` add (with the file's existing setup; add the imports it lacks):
```php
test('a paid payable must be paid by its own head-lease payment out', function () {
    $contract = activeOwnerContract(['type' => 'leased', 'rent_amount' => '50.000', 'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null], []);
    $payable = (new \App\Models\OwnerPayable)->forceFill(['owner_contract_id' => $contract->id, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'due_date' => '2026-01-01', 'amount' => '50.000', 'status' => 'scheduled']);
    $payable->save();
    $other = \App\Models\Disbursement::factory()->create(); // any payment out that is not this payable's
    DB::table('owner_payables')->where('id', $payable->id)->update(['status' => 'paid', 'disbursement_id' => $other->id]);

    expect(app(IntegrityCheck::class)->run())->toContain("owner payable {$payable->id}: not paid by its own head-lease payment out");
});

test('a finalised statement must still add up from the ledger', function () {
    $contract = activeOwnerContract(['type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'fee_type' => 'fixed', 'fee_value' => '0.000'], []);
    $s = (new \App\Models\OwnerStatement)->forceFill(['owner_contract_id' => $contract->id, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'cutoff_at' => '2026-01-31 23:59:59', 'status' => 'draft', 'created_by' => $contract->created_by]);
    $s->save();
    DB::table('owner_statements')->where('id', $s->id)->update(['status' => 'pending_approval']);
    DB::table('owner_statements')->where('id', $s->id)->update(['status' => 'finalised', 'number' => 'OS-T-1', 'finalised_at' => now(), 'finalised_by' => $contract->created_by]);
    expect(app(IntegrityCheck::class)->run())->toBe([]);

    // Something posted back into a finalised window.
    \App\Models\OwnerCharge::create(['owner_contract_id' => $contract->id, 'type' => 'opening_balance', 'net' => '5.000', 'tax_amount' => '0.000', 'amount' => '5.000', 'posted_at' => '2026-01-20 10:00:00', 'created_by' => $contract->created_by]);
    expect(app(IntegrityCheck::class)->run())->toContain("owner statement OS-T-1: closing 0.000 but its entries now give 5.000");
});
```
If there is no `Disbursement` factory, create the payment out as M3b's `DisbursementTriggersTest` does. The statement check reads `CompanySetting::current()`: if the file's setup has no company settings row, add `CompanySetting::factory()->create();` to the second test.

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Permissions/OwnerFinancePermissionMatrixTest.php tests/Feature/Integrity/IntegrityCheckTest.php`
Expected: the matrix passes or fails only where a policy check is missing (fix the policy, not the test); the two integrity tests FAIL — the messages are missing.

- [ ] **Step 3: Implement the checks**

In `app/Integrity/IntegrityCheck.php` `run()`, before the trigger count:
```php
        foreach (DB::select(<<<'SQL'
            SELECT p.id FROM owner_payables p
            LEFT JOIN disbursements d ON d.id = p.disbursement_id
            WHERE p.status = 'paid' AND (d.id IS NULL OR d.status <> 'paid' OR d.purpose <> 'head_lease'
                OR d.source_type <> 'owner_payable' OR d.source_id <> p.id OR d.amount <> p.amount)
            SQL) as $row) {
            $failures[] = "owner payable {$row->id}: not paid by its own head-lease payment out";
        }

        // ponytail: recomputes every finalised statement nightly; limit to the last 13 months if it gets slow.
        foreach (OwnerStatement::query()->where('status', OwnerStatementStatus::Finalised)->with('contract')->orderBy('id')->get() as $s) {
            $closing = OwnerStatementCalculator::compute($s)['closing'];
            if ($closing !== Fils::fromDecimal($s->closing_balance)) {
                $failures[] = "owner statement {$s->number}: closing {$s->closing_balance} but its entries now give ".Fils::toDecimal($closing);
            }
        }
```
(imports `App\Billing\OwnerStatementCalculator`, `App\Enums\OwnerStatementStatus`, `App\Models\OwnerStatement`, `App\Support\Fils`.) Update the class docblock's list if it has one.

- [ ] **Step 4: Run the whole suite and the static checks**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test
"$PHP" vendor/bin/pint --test
"$PHP" vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: every test passes; Pint and Larastan report nothing (fix what they report).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Cover owner finance in the permission matrix and integrity check

Every new page and Action is checked against the roles that may use
it. The nightly check now flags a paid payable without its own payment
out and a finalised statement whose entries no longer add up.
EOF
```

---

## Not in M4

| Item | Where |
|---|---|
| Opening owner balances (`owner_charges` type `opening_balance`) and opening deposits from the legacy system | M5 importer (§11) |
| VAT summary including VAT on management fees; deposits held; customer/owner statement reports beyond the screens built here; dashboard tiles | M5 reports (§10) |
| A system user for nightly drafts (statements are attributed to the contract's creator meanwhile, ruling 8) | M5 |
| Emailing statements to owners; an owner portal | v2 |
| A separate tax invoice for the management fee (C2: whether the statement can serve) | open client question |
| Recovering a head-lease payment already paid for a period after an early end (ruling 3) | outside v1; handled by hand |
| Matching one managed owner's statement and one head-lease schedule to the client's current figures (§15 exit criterion) | acceptance, with the client's data |
| M3b carry-overs: "Cheques to return" also lists unmatched cheques; a settlement does not credit a scheduled (unissued) deposit invoice | M5 |
