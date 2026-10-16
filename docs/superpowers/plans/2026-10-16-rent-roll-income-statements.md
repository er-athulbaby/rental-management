# Rent Roll and Income Statements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add three ready-made reports (a rent roll, a company income statement and an owner income statement) built from data already held, with no new tables.

**Architecture:** One calculator, `App\Reports\IncomeStatement`, returns every income-statement line for a scope (buildings, owner) over a period, month by month, on the Collected or Billed basis. Each line is summed in SQL on DECIMAL and grouped by `DATE_FORMAT(…, '%Y-%m')`. `BuildingProfitability`, the company statement screen and the owner statement screen all read from it. The rent roll is a `ReportPage` report: one SQL query over units, joined to `Queries::currentAgreementUnits`, then subtotal rows in PHP. The two statement screens share a small `StatementPage` trait (period, basis, scope, Excel) and one table partial.

**Tech Stack:** Laravel 13, PHP 8.5, MySQL, Livewire 4 + Flux (free), Pest, spatie/simple-excel, spatie/laravel-permission, the existing `App\Audit\Audit`, `App\Support\Fils`, `<x-building-filter>` and `<x-date-input>`.

**Spec:** `docs/superpowers/specs/2026-10-16-rent-roll-income-statements-design.md`. Read it first; this plan argues from it.

## Global Constraints

- Money is BHD in fils with 3 decimals. Sum in SQL on DECIMAL columns. In PHP use `App\Support\Fils` (`fromDecimal`, `toDecimal`) and never floats, except for Excel cell values.
- "every amount net of VAT, BHD 3 dp, sums in SQL on DECIMAL" (spec §4).
- Timezone is Asia/Bahrain. Dates display as dd/mm/yyyy. Date inputs use `<x-date-input>`.
- Reports are read-only. Actions are the only write path. Database triggers keep money records immutable. `IntegrityCheck::EXPECTED_TRIGGERS` stays 61, and this plan adds no migration.
- "Read-only; runs as `rms_app`. Building scope via the existing `visibleTo` / `inBuilding` scopes; owner scope never widens a user's buildings." (spec §7)
- "Inputs are whitelisted (basis, month range, ids that the user can see); tampered input gives a validation message, never a 500." (spec §7) Tampered Livewire input must never cause a 500.
- All UI strings go through `__()`. UI copy says "tenant", never "customer". The building filter component is `<x-building-filter>`.
- Rent roll permission: `reports.operational`; money columns need `reports.financial`. Both statements: `reports.financial`.
- Period: from/to months, at most 12; default "the last 12 complete months". Basis: **Collected** (default) or **Billed**.
- "Empty lines are hidden; a period with no figures shows "Nothing in this period."" (spec §4)
- Fee footnote, verbatim: "Fee not final until the owner statement for this month is finalised."
- Excel exports are audited as `report.exported`, like the other reports.
- `BuildingProfitability`'s existing tests must still pass unchanged (spec §6).
- No charts. The spec does not ask for them.
- Tests run serially (`php artisan test`, never `--parallel`) against `rms_test`. Never run `migrate:fresh` on the dev DB `rms`.
- PHP binary: `/c/Users/ababy/.config/herd/bin/php85/php.exe` (written `php` below). Gates before each commit: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, and `npm run build` when a view changed.
- Blade: do not mix inline `@php(...)` with `@php … @endphp` blocks in one file. Alpine attributes on Flux components need `:x-show`-style binding.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Rulings made while planning

1. **Catalogue groups.** The Reports page has two groups, `operational` and `financial`, and no "Leasing" or "Finance" group. The rent roll goes in Operational. Both statements go in Financial.
2. **Rent "as at the date (amendments applied)".** No amendment changes rent: `AmendmentType` is add_unit, release_unit and terminate, and each one adds or ends `agreement_units` rows that carry their own charges. So the rent as at a date is Σ `agreement_unit_charges.monthly_amount` (type `rent`) of the agreement unit that occupies the unit on that date. The test uses an add_unit amendment.
3. **Status as at the date.** The rent roll uses the occupancy rule (`Queries::currentAgreementUnits`, which applies the same filters as `occupiedUnitIds`). That rule treats a unit released without a recorded move-out as still held (the overstay rule). Blocked is the unit's current `blocked` flag, because blocking has no history. An occupied blocked unit shows "Occupied" and does not count towards the %, as in the Occupancy report.
4. **Balance owed** is the live open balance (`invoices.balance`, issued, not credit notes) of the agreement. Invoice balances have no history. **Deposit held** is as at the date, using the same Σ `deposit_movements` as the Deposits held report.
5. **Rent roll start/end** are the agreement unit's dates. A unit added or released by amendment has its own dates.
6. **Billed head lease "not replaced"** means owner payables with `status <> 'cancelled'`. `RescheduleOwnerPayables` cancels the old payable and sets `replaced_by_payable_id`.
7. **Company statement, owner scope.** The scope is the owner's leased-unit income (lines stamped with their leased contracts) plus their managed fees, less their head lease. It has no expense lines: spec §4 lists only income and head lease, and company expenses carry no owner.
8. **"Not linked to a unit"** appears only when no building is chosen, no owner is chosen and the user has `buildings.view_all`. For a building-scoped user, "All buildings" means their buildings, and an unlinked line could belong to any building.
9. **Company scope inputs.** The screen has a building filter (`<x-building-filter>`) and an owner select. Either or both may be left empty. When both are set, the statement covers the owner's contracts in that building, which never widens the user's scope.
10. **Owner Collected figures by charge type.** `OwnerLedger` entries carry no charge type. So the calculator sums the same allocation rows the ledger reads (`payment_allocations` on the contract's non-deposit lines, amount − tax), grouped by `invoice_lines.charge_type`. A test checks the result against a finalised calendar-month statement.
11. **Expenses charged to the owner** exist only on managed contracts (`RecordExpense` requires one). The query still covers all of the owner's contracts, so the leased "same" cell is simply empty.
12. **Fee "–".** A month shows "–" when a managed contract of the owner (status active/ended/terminated, dates overlapping the month) has no finalised statement with `period_start` = that month's 1st. The amount is held as `null`. Net income counts it as 0, because no fee charge exists until finalisation.
13. **Memo "paid to owner".** Managed contracts: remittance disbursements (paid or reversed) by `paid_on`, with each reversal subtracting in the month of its `reversed_at`. Leased contracts: paid head-lease disbursements by `paid_on`, on both bases.
14. **Month inputs** are selects over the last 36 months, newest first, with labels like "March 2026". They are not `<x-date-input>`, because a month is not a date. Using selects whitelists the values. A `ponytail:` comment records the 36-month ceiling.
15. **Owner "searchable picker".** Flux free has no searchable select, so the screen pairs a search box with an owner select that the search box filters. The currently chosen owner always stays in the list.
16. **Expense category labels** are the headline of the enum value (`ExpenseCategory` has no `label()`), as on the expense screens.
17. **Shared report table.** `resources/views/livewire/reports/table.blade.php` changes its building select to `<x-building-filter>` (project rule), bolds rows that carry `_kind`, and mutes the cell named by `_muted`. Existing reports are unaffected because they set neither key.
18. **Docs.** Neither `docs/new-company-setup.md` nor `docs/go-live-runbook.md` lists the reports, and there is no user guide. So there is no docs change, and suggested task 6 is dropped. The full-suite run moves into Task 5.

## File Structure

```
app/Reports/IncomeStatement.php                       NEW  calculator: company() (Task 1), owner() (Task 2), months(), isEmpty()
app/Reports/BuildingProfitability.php                 MOD  reads its figures from IncomeStatement (Task 1)
app/Livewire/Reports/RentRollReport.php               NEW  ReportPage report (Task 3)
resources/views/livewire/reports/table.blade.php      MOD  <x-building-filter>, total rows, muted cell (Task 3)
app/Livewire/Reports/Concerns/StatementPage.php       NEW  period, basis, owner list, building scope, Excel (Task 4)
app/Livewire/Reports/IncomeStatementReport.php        NEW  company statement screen (Task 4)
app/Livewire/Reports/OwnerIncomeStatementReport.php   NEW  owner statement screen (Task 5)
resources/views/livewire/reports/partials/statement-inputs.blade.php  NEW  from / to / basis / export (Task 4)
resources/views/livewire/reports/partials/statement-table.blade.php   NEW  lines × months table + fee footnote (Task 4)
resources/views/livewire/reports/income-statement.blade.php           NEW  (Task 4)
resources/views/livewire/reports/owner-income-statement.blade.php     NEW  (Task 5)
app/Livewire/Reports/Index.php                        MOD  catalogue entries (Tasks 3, 4, 5)
routes/property.php                                   MOD  three routes (Tasks 3, 4, 5)
tests/Feature/Reports/IncomeStatementTest.php         NEW  fixture + calculator tests (Tasks 1, 2), screen tests (Tasks 4, 5)
tests/Feature/Reports/RentRollTest.php                NEW  (Task 3)
tests/Feature/Permissions/ReportsPermissionMatrixTest.php  MOD  three routes (Tasks 3, 4, 5)
```

---

### Task 1: `IncomeStatement` calculator, company scope; Building profitability reads from it

**Files:**
- Create: `app/Reports/IncomeStatement.php`
- Modify: `app/Reports/BuildingProfitability.php` (whole body of `for()`)
- Test: `tests/Feature/Reports/IncomeStatementTest.php`

**Interfaces:**
- Consumes: the existing tables and `CompanySetting::current()->vat_registered`. Test helpers from `tests/Pest.php`: `activeOwnerContract()`, `activeAgreement()`, `issuedInvoice()`. Actions: `RecordDisbursement`, `RecordPayment`, `RecordExpense`, `DraftOwnerStatements`, `SubmitOwnerStatement`, `SaveCreditNote`, `SubmitCreditNote`, `DecideApproval`.
- Produces:
  - `IncomeStatement::COLLECTED = 'collected'`, `IncomeStatement::BILLED = 'billed'`
  - `IncomeStatement::company(string $from, string $to, string $basis, ?array $buildingIds = null, ?int $ownerId = null): array`. Dates are `Y-m-d`. `$buildingIds` is `list<int>|null`, where null means every building plus the lines without a unit.
  - Return shape (`@phpstan-type Statement`): `array{months: list<string>, rows: array<string, Row>}`, with `Row = array{label: string, kind: 'line'|'total'|'memo', amounts: array<string, int|null>, total: int}`. The `amounts` keys are the `Y-m` months.
  - Company row keys, in order: `rent`, `service_charge`, `parking`, `other_charges`, `unlinked`, `management_fees`, `total_income`, `head_lease`, `expense_<category>` (in `ExpenseCategory::cases()` order), `total_costs`, `result`. Line rows whose amounts are all 0 are left out. The three total rows are always present.
  - `IncomeStatement::months(string $from, string $to): list<string>` and `IncomeStatement::isEmpty(array $statement): bool` (true when only total rows remain).
  - Private helpers used again in Task 2: `sums()`, `headLease()`, `billedLines()`, `row()`, `combine()`, `visible()`.
  - `BuildingProfitability::for()` keeps its signature and return shape.

- [ ] **Step 1: Write the failing test.** The fixture is shared by Tasks 1, 2, 4 and 5. Later tasks append tests and `use` lines to this file.

```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\EnsureNumberSequences;
use App\Actions\Expenses\RecordExpense;
use App\Actions\OwnerStatements\DraftOwnerStatements;
use App\Actions\OwnerStatements\SubmitOwnerStatement;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\OwnerPayable;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\User;
use App\Reports\BuildingProfitability;
use App\Reports\IncomeStatement;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

/** A statement as key => [amount per month], so a whole statement is checked to the fil, in order. */
function statementGrid(array $statement): array
{
    return array_map(fn (array $r) => array_values($r['amounts']), $statement['rows']);
}

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-03-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->building = Building::factory()->create();
    $this->other = Building::factory()->create();
    [$owned, $leasedUnit, $managedUnit] = Unit::factory()->for($this->building)->count(3)->create()->all();
    $this->leased = activeOwnerContract(['owner_id' => Owner::factory()->create(['name_en' => 'Leila Lessor'])->id, 'building_id' => $this->building->id, 'type' => 'leased',
        'rent_amount' => '50.000', 'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null, 'start_date' => '2026-03-01', 'end_date' => '2027-02-28'], [$leasedUnit]);
    $this->managed = activeOwnerContract(['owner_id' => Owner::factory()->create(['name_en' => 'Mansour Manager'])->id, 'building_id' => $this->building->id, 'type' => 'managed',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'percent_collected', 'fee_value' => '10.000'], [$managedUnit]);
    // A draft leased contract in the other building: its owner is listed only to users who can see that building.
    OwnerContract::factory()->leased()->create(['building_id' => $this->other->id, 'owner_id' => Owner::factory()->create(['name_en' => 'Omar Outside'])->id]);

    $payable = fn (string $due, string $amount) => tap((new OwnerPayable)->forceFill(['owner_contract_id' => $this->leased->id, 'period_start' => $due,
        'period_end' => CarbonImmutable::parse($due)->endOfMonth()->toDateString(), 'due_date' => $due, 'amount' => $amount, 'status' => 'scheduled']))->save();
    $march = $payable('2026-03-01', '50.000');
    app(RecordDisbursement::class)->handle($this->finance, ['purpose' => 'head_lease', 'owner_payable_id' => $march->id, 'amount' => '50.000', 'method' => 'bank_transfer', 'paid_on' => '2026-03-05']);

    // Issued 5 March. Owned unit: rent 100 + VAT, service 20 + VAT, damage 15, deposit 200. Leased unit: rent 100 + VAT,
    // parking 10 + VAT. Managed unit: rent 100 + VAT (the owner's money), deposit 100. Plus a manual line with no unit.
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$owned, $leasedUnit, $managedUnit]);
    $this->au = $this->agreement->agreementUnits()->orderBy('id')->get();
    issuedInvoice($this->customer, [
        ['net' => '100.000', 'tax' => 'standard', 'au' => $this->au[0]], ['type' => 'service_charge', 'net' => '20.000', 'tax' => 'standard', 'au' => $this->au[0]],
        ['type' => 'damage', 'net' => '15.000', 'au' => $this->au[0]], ['type' => 'deposit', 'net' => '200.000', 'au' => $this->au[0]],
        ['net' => '100.000', 'tax' => 'standard', 'au' => $this->au[1]], ['type' => 'parking', 'net' => '10.000', 'tax' => 'standard', 'au' => $this->au[1]],
        ['net' => '100.000', 'tax' => 'standard', 'au' => $this->au[2]], ['type' => 'deposit', 'net' => '100.000', 'au' => $this->au[2]],
    ], '2026-03-01', $this->agreement); // 645 net + 33 VAT = 678
    issuedInvoice($this->customer, [['type' => 'other', 'net' => '40.000', 'description' => 'Key replacement']], '2026-03-01');

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00', 'Asia/Bahrain'));
    app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-03-10', 'method' => 'cash', 'amount' => '718.000']); // pays both in full
    $this->expense = fn (array $e) => app(RecordExpense::class)->handle($this->finance, ['description' => 'Fixture', 'expense_date' => now()->toDateString(), ...$e]);
    ($this->expense)(['building_id' => $this->building->id, 'category' => 'cleaning', 'net' => '30.000', 'tax_amount' => '3.000', 'charge_to' => 'company']);
    ($this->expense)(['building_id' => $this->building->id, 'unit_id' => $managedUnit->id, 'category' => 'maintenance', 'net' => '20.000', 'charge_to' => 'owner']);
    ($this->expense)(['building_id' => $this->other->id, 'category' => 'legal', 'net' => '7.000', 'charge_to' => 'company']);

    // The managed contract's March statement, finalised: fee 10.000 + 1.000 VAT, closing 100 − 20 − 11 = 69.000.
    $this->travelTo(CarbonImmutable::parse('2026-04-01 04:00', 'Asia/Bahrain'));
    app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-03-01'));
    app(DecideApproval::class)->handle($this->management, app(SubmitOwnerStatement::class)->handle($this->finance, OwnerStatement::sole()), true);
    $this->march = OwnerStatement::sole();

    // April: rent 100 + VAT on the owned unit, half credited (55 gross = 50 net), unpaid; a security expense; the April
    // head-lease payable of 50 rescheduled to 30 (the old one cancelled and replaced).
    $this->travelTo(CarbonImmutable::parse('2026-04-02 09:00', 'Asia/Bahrain'));
    $april = issuedInvoice($this->customer, [['net' => '100.000', 'tax' => 'standard', 'au' => $this->au[0]]], '2026-04-01', $this->agreement);
    $cn = app(SaveCreditNote::class)->handle($this->finance, $april, null, ['reason' => 'Goodwill', 'lines' => [['credited_line_id' => $april->lines->sole()->id, 'amount' => '55.000']]]);
    app(DecideApproval::class)->handle($this->management, app(SubmitCreditNote::class)->handle($this->finance, $cn), true);
    ($this->expense)(['building_id' => $this->building->id, 'category' => 'security', 'net' => '12.000', 'charge_to' => 'company']);
    $old = $payable('2026-04-01', '50.000');
    $new = $payable('2026-04-01', '30.000');
    $old->forceFill(['status' => 'cancelled'])->save();
    $old->forceFill(['replaced_by_payable_id' => $new->id])->save();
});

test('collected, all buildings: company-earned lines by month, net of VAT, without managed rent or deposits', function () {
    expect(statementGrid(IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::COLLECTED)))->toBe([
        'rent' => [200_000, 0],             // owned + leased; the managed unit's 100 is the owner's
        'service_charge' => [20_000, 0],
        'parking' => [10_000, 0],
        'other_charges' => [15_000, 0],     // damage
        'unlinked' => [40_000, 0],          // the manual line with no unit
        'management_fees' => [10_000, 0],   // posted at the March cutoff
        'total_income' => [295_000, 0],
        'head_lease' => [50_000, 0],        // paid 5 March
        'expense_cleaning' => [30_000, 0],  // net: the company is VAT-registered
        'expense_security' => [0, 12_000],
        'expense_legal' => [7_000, 0],      // the other building
        'total_costs' => [87_000, 12_000],
        'result' => [208_000, -12_000],
    ]);
});

test('billed, all buildings: issued lines less credit notes; head lease due, replaced payables left out', function () {
    expect(statementGrid(IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::BILLED)))->toBe([
        'rent' => [200_000, 50_000],        // April: 100 issued − 50 credited
        'service_charge' => [20_000, 0],
        'parking' => [10_000, 0],
        'other_charges' => [15_000, 0],
        'unlinked' => [40_000, 0],
        'management_fees' => [10_000, 0],
        'total_income' => [295_000, 50_000],
        'head_lease' => [50_000, 30_000],   // the cancelled 50 is not counted
        'expense_cleaning' => [30_000, 0],
        'expense_security' => [0, 12_000],
        'expense_legal' => [7_000, 0],
        'total_costs' => [87_000, 42_000],
        'result' => [208_000, 8_000],
    ]);
});

test('one building: its lines only, never the lines without a unit; its Collected figures equal building profitability', function () {
    $statement = IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::COLLECTED, [$this->building->id]);

    expect(statementGrid($statement))->toBe([
        'rent' => [200_000, 0],
        'service_charge' => [20_000, 0],
        'parking' => [10_000, 0],
        'other_charges' => [15_000, 0],
        'management_fees' => [10_000, 0],
        'total_income' => [255_000, 0],
        'head_lease' => [50_000, 0],
        'expense_cleaning' => [30_000, 0],
        'expense_security' => [0, 12_000],
        'total_costs' => [80_000, 12_000],
        'result' => [175_000, -12_000],
    ]);

    $profitability = BuildingProfitability::for($this->building, '2026-03-01', '2026-04-30');
    expect($profitability)->toBe([
        'collected' => 245_000, 'fees' => 10_000, 'income' => 255_000, 'billed' => 305_000,
        'head_lease' => 50_000, 'expenses' => 42_000, 'costs' => 92_000, 'result' => 163_000,
    ])->and($profitability['income'])->toBe($statement['rows']['total_income']['total'])
        ->and($profitability['result'])->toBe($statement['rows']['result']['total']);
});

test('a company that is not VAT-registered counts the VAT on its expenses as a cost', function () {
    CompanySetting::current()->forceFill(['vat_registered' => false])->save();
    $grid = statementGrid(IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::COLLECTED));

    expect($grid['expense_cleaning'])->toBe([33_000, 0])
        ->and($grid['total_costs'])->toBe([90_000, 12_000])
        ->and($grid['result'])->toBe([205_000, -12_000]);
});

test('owner scope: a leased owner\'s units less their head lease; a managed owner\'s fees only', function () {
    expect(statementGrid(IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::COLLECTED, null, $this->leased->owner_id)))->toBe([
        'rent' => [100_000, 0], 'parking' => [10_000, 0], 'total_income' => [110_000, 0],
        'head_lease' => [50_000, 0], 'total_costs' => [50_000, 0], 'result' => [60_000, 0],
    ])->and(statementGrid(IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::BILLED, null, $this->leased->owner_id)))->toBe([
        'rent' => [100_000, 0], 'parking' => [10_000, 0], 'total_income' => [110_000, 0],
        'head_lease' => [50_000, 30_000], 'total_costs' => [50_000, 30_000], 'result' => [60_000, -30_000],
    ])->and(statementGrid(IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::COLLECTED, null, $this->managed->owner_id)))->toBe([
        'management_fees' => [10_000, 0], 'total_income' => [10_000, 0], 'total_costs' => [0, 0], 'result' => [10_000, 0],
    ]);
});

test('a period with nothing in it keeps only zero totals', function () {
    $statement = IncomeStatement::company('2025-06-01', '2025-06-30', IncomeStatement::COLLECTED);

    expect(statementGrid($statement))->toBe(['total_income' => [0], 'total_costs' => [0], 'result' => [0]])
        ->and(IncomeStatement::isEmpty($statement))->toBeTrue()
        ->and(IncomeStatement::months('2026-03-15', '2026-05-01'))->toBe(['2026-03', '2026-04', '2026-05']);
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test tests/Feature/Reports/IncomeStatementTest.php`
Expected: FAIL with `Class "App\Reports\IncomeStatement" not found`.

- [ ] **Step 3: Write the calculator**

```php
<?php

namespace App\Reports;

use App\Enums\ExpenseCategory;
use App\Models\CompanySetting;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Spec 2026-10-16 §2: every income-statement line for a scope over a period, month by month, on either basis. Both
 * statement screens and BuildingProfitability read from here, so their figures agree. Amounts are fils net of VAT
 * (expenses: their total when the company is not VAT-registered), summed in SQL on DECIMAL. Months are Asia/Bahrain
 * calendar months: timestamps are stored in Bahrain time, so DATE_FORMAT needs no conversion.
 *
 * @phpstan-type Row array{label: string, kind: 'line'|'total'|'memo', amounts: array<string, int|null>, total: int}
 * @phpstan-type Statement array{months: list<string>, rows: array<string, Row>}
 */
final class IncomeStatement
{
    public const string COLLECTED = 'collected';

    public const string BILLED = 'billed';

    /**
     * Spec §4. $buildingIds null = every building plus the lines without a unit ("Not linked to a unit"). $ownerId
     * narrows to that owner's contracts: their leased units' income less the head lease, and their managed fees.
     *
     * @param  list<int>|null  $buildingIds
     * @return Statement
     */
    public static function company(string $from, string $to, string $basis, ?array $buildingIds = null, ?int $ownerId = null): array
    {
        $months = self::months($from, $to);
        $contracts = fn (Builder $q): Builder => $q
            ->when($buildingIds !== null, fn (Builder $w) => $w->whereIn('oc.building_id', $buildingIds))
            ->when($ownerId !== null, fn (Builder $w) => $w->where('oc.owner_id', $ownerId));

        // Company-earned tenant lines: owned units (no contract stamp) or leased-contract units; never managed, never deposits.
        $lines = fn (Builder $q): Builder => $q->leftJoin('units as u', 'u.id', '=', 'l.unit_id')
            ->leftJoin('owner_contracts as oc', 'oc.id', '=', 'l.owner_contract_id')
            ->where('l.charge_type', '<>', 'deposit')
            ->when($ownerId !== null,
                fn (Builder $w) => $w->where('oc.type', 'leased')->where('oc.owner_id', $ownerId),
                fn (Builder $w) => $w->where(fn (Builder $o) => $o->whereNull('l.owner_contract_id')->orWhere('oc.type', 'leased')))
            ->when($buildingIds !== null, fn (Builder $w) => $w->whereIn('u.building_id', $buildingIds));
        $bucket = "CASE WHEN l.unit_id IS NULL THEN 'unlinked' WHEN l.charge_type IN ('rent', 'service_charge', 'parking') THEN l.charge_type ELSE 'other_charges' END";
        $tenant = $basis === self::COLLECTED
            ? self::sums($lines(DB::table('payment_allocations as a')->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id'))
                ->whereBetween('a.posted_at', [$from.' 00:00:00', $to.' 23:59:59']), 'a.posted_at', 'a.amount - a.tax_amount', $bucket)
            : self::billedLines($lines(DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')), $from, $to, $bucket);

        $income = [];
        foreach (['rent' => __('Rent'), 'service_charge' => __('Service charges'), 'parking' => __('Parking'),
            'other_charges' => __('Other tenant charges'), 'unlinked' => __('Not linked to a unit')] as $key => $label) {
            $income[$key] = self::row($label, $tenant[$key] ?? [], $months);
        }
        $income['management_fees'] = self::row(__('Management fees'), self::sums($contracts(DB::table('owner_charges as c')
            ->join('owner_contracts as oc', 'oc.id', '=', 'c.owner_contract_id'))->where('c.type', 'management_fee')
            ->whereBetween('c.posted_at', [$from.' 00:00:00', $to.' 23:59:59']), 'c.posted_at', 'c.net')['all'] ?? [], $months);

        $costs = ['head_lease' => self::row(__('Head lease'), self::headLease($basis, $from, $to, $contracts)['all'] ?? [], $months)];
        if ($ownerId === null) { // plan ruling 7: an owner's scope has no expense lines
            $expenses = self::sums(DB::table('expenses as e')->whereIn('e.charge_to', ['company', 'tenant'])->where('e.status', 'recorded')
                ->whereBetween('e.expense_date', [$from, $to])
                ->when($buildingIds !== null, fn (Builder $w) => $w->whereIn('e.building_id', $buildingIds)),
                'e.expense_date', CompanySetting::current()->vat_registered ? 'e.net' : 'e.total', 'e.category');
            foreach (ExpenseCategory::cases() as $category) {
                $costs['expense_'.$category->value] = self::row(__(str($category->value)->headline()->toString()), $expenses[$category->value] ?? [], $months);
            }
        }

        $rows = [...self::visible($income), 'total_income' => self::combine(__('Total income'), $months, $income),
            ...self::visible($costs), 'total_costs' => self::combine(__('Total costs'), $months, $costs)];
        $rows['result'] = self::combine(__('Result'), $months, [$rows['total_income']], [$rows['total_costs']]);

        return ['months' => $months, 'rows' => $rows];
    }

    /** @return list<string> the Y-m months from $from's month to $to's */
    public static function months(string $from, string $to): array
    {
        $months = [];
        $last = CarbonImmutable::parse($to)->startOfMonth();
        for ($m = CarbonImmutable::parse($from)->startOfMonth(); $m->lessThanOrEqualTo($last); $m = $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        return $months;
    }

    /** @param  Statement  $statement */
    public static function isEmpty(array $statement): bool
    {
        return array_filter($statement['rows'], fn (array $r) => $r['kind'] !== 'total') === [];
    }

    /**
     * Collected: head-lease payments by paid on. Billed: owner payables by due date, without cancelled ones (plan ruling 6).
     *
     * @param  Closure(Builder): Builder  $contracts  narrows the owner contracts (alias oc)
     * @return array<string, array<string, int>>
     */
    private static function headLease(string $basis, string $from, string $to, Closure $contracts): array
    {
        return $basis === self::COLLECTED
            ? self::sums($contracts(DB::table('disbursements as d')->join('owner_payables as p', 'p.id', '=', 'd.source_id')
                ->join('owner_contracts as oc', 'oc.id', '=', 'p.owner_contract_id'))
                ->where('d.source_type', 'owner_payable')->where('d.purpose', 'head_lease')->where('d.status', 'paid')
                ->whereBetween('d.paid_on', [$from, $to]), 'd.paid_on', 'd.amount')
            : self::sums($contracts(DB::table('owner_payables as p')->join('owner_contracts as oc', 'oc.id', '=', 'p.owner_contract_id'))
                ->where('p.status', '<>', 'cancelled')->whereBetween('p.due_date', [$from, $to]), 'p.due_date', 'p.amount');
    }

    /**
     * Issued lines by issue date, credit notes subtracted. $q joins invoice_lines l and invoices i.
     *
     * @param  literal-string  $key
     * @return array<string, array<string, int>>
     */
    private static function billedLines(Builder $q, string $from, string $to, string $key): array
    {
        return self::sums($q->where('i.status', 'issued')->whereBetween('i.issue_date', [$from, $to]),
            'i.issue_date', "CASE WHEN i.type = 'credit_note' THEN -l.net ELSE l.net END", $key);
    }

    /**
     * SUM($amount) grouped by $key and month. Every expression is a literal written in this class, never user input.
     *
     * @param  literal-string  $date
     * @param  literal-string  $amount
     * @param  literal-string  $key
     * @return array<string, array<string, int>> key => Y-m => fils
     */
    private static function sums(Builder $q, string $date, string $amount, string $key = "'all'"): array
    {
        $out = [];
        foreach ($q->selectRaw("{$key} as k, DATE_FORMAT({$date}, '%Y-%m') as m, SUM({$amount}) as t")->groupBy('k', 'm')->get() as $r) {
            $out[(string) $r->k][(string) $r->m] = Fils::fromDecimal((string) $r->t);
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $byMonth
     * @param  list<string>  $months
     * @param  'line'|'memo'  $kind
     * @return Row
     */
    private static function row(string $label, array $byMonth, array $months, string $kind = 'line'): array
    {
        $amounts = [];
        foreach ($months as $m) {
            $amounts[$m] = $byMonth[$m] ?? 0;
        }

        return ['label' => $label, 'kind' => $kind, 'amounts' => $amounts, 'total' => array_sum($amounts)];
    }

    /**
     * Σ $plus − Σ $minus per month; a "–" (null) counts as 0.
     *
     * @param  list<string>  $months
     * @param  array<array-key, Row>  $plus
     * @param  array<array-key, Row>  $minus
     * @return Row
     */
    private static function combine(string $label, array $months, array $plus, array $minus = []): array
    {
        $amounts = [];
        foreach ($months as $m) {
            $amounts[$m] = array_sum(array_map(fn (array $r) => $r['amounts'][$m] ?? 0, $plus))
                - array_sum(array_map(fn (array $r) => $r['amounts'][$m] ?? 0, $minus));
        }

        return ['label' => $label, 'kind' => 'total', 'amounts' => $amounts, 'total' => array_sum($amounts)];
    }

    /**
     * Spec §4: empty lines are hidden. A "–" (null) is not empty.
     *
     * @param  array<string, Row>  $rows
     * @return array<string, Row>
     */
    private static function visible(array $rows): array
    {
        return array_filter($rows, fn (array $r) => array_filter($r['amounts'], fn (?int $v) => $v !== 0) !== []);
    }
}
```

If phpstan reports `argument.type` on the `selectRaw` line, put `// @phpstan-ignore argument.type` on the line above it. `BuildingProfitability` did the same for its interpolated SQL.

- [ ] **Step 4: Switch `BuildingProfitability` to the calculator.** Replace the whole file:

```php
<?php

namespace App\Reports;

use App\Models\Building;

/**
 * Spec §10 (v1) and 2026-10-16 §6: the quick per-building summary, cash basis with a billed column. Every figure comes
 * from IncomeStatement (one building), so it always agrees with the income statement. Lines without a unit cannot be
 * placed in a building and are left out.
 */
final class BuildingProfitability
{
    /** @return array{collected: int, fees: int, income: int, billed: int, head_lease: int, expenses: int, costs: int, result: int} */
    public static function for(Building $building, string $from, string $to): array
    {
        $collected = IncomeStatement::company($from, $to, IncomeStatement::COLLECTED, [$building->id])['rows'];
        $billed = IncomeStatement::company($from, $to, IncomeStatement::BILLED, [$building->id])['rows'];
        $fees = $collected['management_fees']['total'] ?? 0;
        $headLease = $collected['head_lease']['total'] ?? 0;
        $income = $collected['total_income']['total'];
        $costs = $collected['total_costs']['total'];

        return [
            'collected' => $income - $fees,
            'fees' => $fees,
            'income' => $income,
            'billed' => $billed['total_income']['total'], // billed tenant lines + the fees, as before
            'head_lease' => $headLease,
            'expenses' => $costs - $headLease,
            'costs' => $costs,
            'result' => $income - $costs,
        ];
    }
}
```

- [ ] **Step 5: Run the new tests and the unchanged profitability tests**

Run: `php artisan test tests/Feature/Reports/IncomeStatementTest.php tests/Feature/Reports/BuildingProfitabilityTest.php`
Expected: PASS (6 + 3 tests).

- [ ] **Step 6: Gates.** Run `php vendor/bin/pint --dirty` and `php vendor/bin/phpstan analyse --memory-limit=2G`. Both must be clean.

- [ ] **Step 7: Commit**

```bash
git add app/Reports/IncomeStatement.php app/Reports/BuildingProfitability.php tests/Feature/Reports/IncomeStatementTest.php
git commit -m "$(cat <<'EOF'
Income statement calculator (company scope, both bases); building profitability reads from it

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Owner scope in `IncomeStatement` (spec §5 lines)

**Files:**
- Modify: `app/Reports/IncomeStatement.php` (add `owner()` and `feePending()`, plus imports)
- Test: `tests/Feature/Reports/IncomeStatementTest.php` (append)

**Interfaces:**
- Consumes: Task 1's private helpers `sums()`, `headLease()`, `billedLines()`, `row()`, `combine()`, `visible()`, the `Statement` / `Row` types, and `months()`.
- Produces: `IncomeStatement::owner(int $ownerId, string $from, string $to, string $basis, ?array $buildingIds = null): array` (a `Statement`). Row keys, in order:
  - `charge_<InvoiceChargeType value>` for each non-deposit type that has amounts (managed contracts)
  - `head_lease` (label "Head lease earned", leased contracts)
  - `total_income`
  - `fee` (label "Less management fee and VAT" when VAT-registered, otherwise "Less management fee"; positive amounts, `null` for a month whose fee is not final)
  - `expenses` ("Less expenses charged to the owner")
  - `net` ("Net income to owner", kind total)
  - `memo_paid` ("Memo: paid to owner", kind memo)

  The fee, expenses and memo rows are hidden when every amount is 0.

- [ ] **Step 1: Write the failing tests.** Append these `use` lines at the top of the file:

```php
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Support\Fils;
```

Then append the tests:

```php
test('a managed owner: charges on their units less fee with VAT and their expenses; fee "–" until final; memo remittances net of a reversal', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-03 10:00', 'Asia/Bahrain'));
    $remit = fn (string $on) => app(RecordDisbursement::class)->handle($this->finance, ['purpose' => 'owner_remittance', 'owner_statement_id' => $this->march->id,
        'amount' => '69.000', 'method' => 'bank_transfer', 'paid_on' => $on]);
    $first = $remit('2026-04-03');
    $this->travelTo(CarbonImmutable::parse('2026-04-05 10:00', 'Asia/Bahrain'));
    app(DecideApproval::class)->handle($this->management, app(RequestDisbursementReversal::class)->handle($this->finance, $first, 'Bounced by the bank'), true);
    $remit('2026-04-05');

    $collected = IncomeStatement::owner($this->managed->owner_id, '2026-03-01', '2026-04-30', IncomeStatement::COLLECTED);
    expect(statementGrid($collected))->toBe([
        'charge_rent' => [100_000, 0],   // net of VAT; the 100 deposit on their unit is left out
        'total_income' => [100_000, 0],
        'fee' => [11_000, null],         // 10 + 1 VAT; April's statement is not finalised
        'expenses' => [20_000, 0],
        'net' => [69_000, 0],
        'memo_paid' => [0, 69_000],      // 69 + 69 paid − 69 reversed
    ])->and($collected['rows']['fee']['label'])->toBe('Less management fee and VAT');

    expect(statementGrid(IncomeStatement::owner($this->managed->owner_id, '2026-03-01', '2026-04-30', IncomeStatement::BILLED))['charge_rent'])->toBe([100_000, 0]);
});

test('a managed owner\'s collected net income agrees with their finalised calendar-month statement', function () {
    $net = IncomeStatement::owner($this->managed->owner_id, '2026-03-01', '2026-03-31', IncomeStatement::COLLECTED)['rows']['net']['total'];

    expect($net)->toBe(Fils::fromDecimal($this->march->closing_balance) - Fils::fromDecimal($this->march->opening_balance))->and($net)->toBe(69_000);
});

test('a leased owner: the head lease earned, paid or due; memo head-lease payments', function () {
    expect(statementGrid(IncomeStatement::owner($this->leased->owner_id, '2026-03-01', '2026-04-30', IncomeStatement::COLLECTED)))->toBe([
        'head_lease' => [50_000, 0], 'total_income' => [50_000, 0], 'net' => [50_000, 0], 'memo_paid' => [50_000, 0],
    ])->and(statementGrid(IncomeStatement::owner($this->leased->owner_id, '2026-03-01', '2026-04-30', IncomeStatement::BILLED)))->toBe([
        'head_lease' => [50_000, 30_000], 'total_income' => [50_000, 30_000], 'net' => [50_000, 30_000], 'memo_paid' => [50_000, 0],
    ]);
});

test('an owner statement never reaches past the buildings it is given', function () {
    expect(IncomeStatement::isEmpty(IncomeStatement::owner($this->managed->owner_id, '2026-03-01', '2026-04-30', IncomeStatement::COLLECTED, [$this->other->id])))->toBeTrue();
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test tests/Feature/Reports/IncomeStatementTest.php`
Expected: the 4 new tests FAIL with `Call to undefined method App\Reports\IncomeStatement::owner()`. The Task 1 tests still pass.

- [ ] **Step 3: Implement.** Add these imports to `IncomeStatement.php`: `App\Enums\InvoiceChargeType`, `App\Enums\OwnerContractStatus`, `App\Enums\OwnerContractType`, `App\Models\OwnerContract`. Then add after `company()`:

```php
    /**
     * Spec §5: an owner's statement over their contracts in $buildingIds (null = all). Managed contracts: their units'
     * tenant charges by type (no deposits), less the finalised fee with its VAT, less expenses charged to the owner.
     * Leased contracts: the head lease. Memo: remittances (a reversal subtracts in its month) and head-lease payments.
     * Collected sums the same allocation rows OwnerLedger reads, grouped by charge type (plan ruling 10).
     *
     * @param  list<int>|null  $buildingIds
     * @return Statement
     */
    public static function owner(int $ownerId, string $from, string $to, string $basis, ?array $buildingIds = null): array
    {
        $months = self::months($from, $to);
        [$start, $end] = [$from.' 00:00:00', $to.' 23:59:59'];
        $contracts = OwnerContract::query()->where('owner_id', $ownerId)
            ->when($buildingIds !== null, fn ($q) => $q->whereIn('building_id', $buildingIds))->get(['id', 'type']);
        $ids = fn (OwnerContractType $type) => $contracts->filter(fn (OwnerContract $c) => $c->type === $type)->map(fn (OwnerContract $c) => $c->id)->values()->all();
        $managed = $ids(OwnerContractType::Managed);
        $leased = $ids(OwnerContractType::Leased);

        $lines = fn (Builder $q): Builder => $q->whereIn('l.owner_contract_id', $managed)->where('l.charge_type', '<>', 'deposit');
        $charges = $basis === self::COLLECTED
            ? self::sums($lines(DB::table('payment_allocations as a')->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id'))
                ->whereBetween('a.posted_at', [$start, $end]), 'a.posted_at', 'a.amount - a.tax_amount', 'l.charge_type')
            : self::billedLines($lines(DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')), $from, $to, 'l.charge_type');
        $income = [];
        foreach (InvoiceChargeType::cases() as $type) {
            if ($type !== InvoiceChargeType::Deposit) {
                $income['charge_'.$type->value] = self::row(__($type->label()), $charges[$type->value] ?? [], $months);
            }
        }
        $leasedOnly = fn (Builder $q): Builder => $q->whereIn('oc.id', $leased);
        $income['head_lease'] = self::row(__('Head lease earned'), self::headLease($basis, $from, $to, $leasedOnly)['all'] ?? [], $months);

        $fee = self::row(CompanySetting::current()->vat_registered ? __('Less management fee and VAT') : __('Less management fee'),
            self::sums(DB::table('owner_charges as c')->whereIn('c.owner_contract_id', $managed)->where('c.type', 'management_fee')
                ->whereBetween('c.posted_at', [$start, $end]), 'c.posted_at', 'c.net + c.tax_amount')['all'] ?? [], $months);
        foreach (self::feePending($managed, $months) as $m) {
            $fee['amounts'][$m] = null; // plan ruling 12: shown as "–"
        }
        $deductions = [
            'fee' => $fee,
            'expenses' => self::row(__('Less expenses charged to the owner'), self::sums(DB::table('expenses as e')
                ->whereIn('e.owner_contract_id', [...$managed, ...$leased])->where('e.charge_to', 'owner')->where('e.status', 'recorded')
                ->whereBetween('e.expense_date', [$from, $to]), 'e.expense_date', 'e.total')['all'] ?? [], $months),
        ];

        // Plan ruling 13: remittances by paid on, a reversal subtracting at its reversal time; leased: head-lease payments.
        $remittances = fn (Builder $q): Builder => $q->join('owner_statements as s', 's.id', '=', 'd.source_id')
            ->where('d.source_type', 'owner_statement')->where('d.purpose', 'owner_remittance')->whereIn('s.owner_contract_id', $managed);
        $paid = self::sums($remittances(DB::table('disbursements as d'))->whereIn('d.status', ['paid', 'reversed'])
            ->whereBetween('d.paid_on', [$from, $to]), 'd.paid_on', 'd.amount')['all'] ?? [];
        $reversed = self::sums($remittances(DB::table('disbursements as d'))->where('d.status', 'reversed')
            ->whereBetween('d.reversed_at', [$start, $end]), 'd.reversed_at', '-d.amount')['all'] ?? [];
        $headLeasePaid = self::headLease(self::COLLECTED, $from, $to, $leasedOnly)['all'] ?? [];
        $memo = [];
        foreach ($months as $m) {
            $memo[$m] = ($paid[$m] ?? 0) + ($reversed[$m] ?? 0) + ($headLeasePaid[$m] ?? 0);
        }

        $rows = [...self::visible($income), 'total_income' => self::combine(__('Total income'), $months, $income), ...self::visible($deductions)];
        $rows['net'] = self::combine(__('Net income to owner'), $months, [$rows['total_income']], $deductions);

        return ['months' => $months, 'rows' => [...$rows, ...self::visible(['memo_paid' => self::row(__('Memo: paid to owner'), $memo, $months, 'memo')])]];
    }

    /**
     * Months in which a managed contract (attributed, dates overlapping the month) has no finalised statement for it.
     *
     * @param  list<int>  $managed
     * @param  list<string>  $months
     * @return list<string>
     */
    private static function feePending(array $managed, array $months): array
    {
        if ($managed === []) {
            return [];
        }
        $attributed = array_map(fn (OwnerContractStatus $s) => $s->value, OwnerContract::ATTRIBUTED);

        return array_values(array_filter($months, function (string $m) use ($managed, $attributed) {
            $first = $m.'-01';

            return OwnerContract::query()->whereIn('id', $managed)->whereIn('status', $attributed)
                ->where('start_date', '<=', CarbonImmutable::parse($first)->endOfMonth()->toDateString())->where('end_date', '>=', $first)
                ->whereDoesntHave('statements', fn ($q) => $q->where('period_start', $first)->where('status', 'finalised'))
                ->exists();
        }));
    }
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test tests/Feature/Reports/IncomeStatementTest.php tests/Feature/Reports/BuildingProfitabilityTest.php`
Expected: PASS (10 + 3).

- [ ] **Step 5: Gates.** Run Pint `--dirty` and phpstan. Both must be clean.

- [ ] **Step 6: Commit**

```bash
git add app/Reports/IncomeStatement.php tests/Feature/Reports/IncomeStatementTest.php
git commit -m "$(cat <<'EOF'
Income statement: owner scope (charges, head lease, fee with VAT, expenses, net, memo payments)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Rent roll (report, shared table tweaks, route, catalogue, Excel)

**Files:**
- Create: `app/Livewire/Reports/RentRollReport.php`
- Modify: `resources/views/livewire/reports/table.blade.php` (building filter; row `_kind`; cell `_muted`)
- Modify: `routes/property.php` (after the `reports.occupancy` line), `app/Livewire/Reports/Index.php` (operational group, first entry)
- Modify: `tests/Feature/Permissions/ReportsPermissionMatrixTest.php`
- Test: `tests/Feature/Reports/RentRollTest.php`

**Interfaces:**
- Consumes: the `ReportPage` trait (`title()`, `permission()`, `dateMode()`, `columns()`, `numeric()`, `rows()`, and `export()` audited as `report: 'rent_roll'`). Also `Queries::currentAgreementUnits(User, string): QueryBuilder` (columns `unit_id`, `au_id`) and `Unit::visibleTo()`.
- Produces: route `reports.rent-roll`. Row keys `building, unit, type, status, tenant, agreement, start, end`, plus `rent, deposit, balance` with `reports.financial`. Optional row keys `_kind` (`'total'`) and `_muted` (a column key) are understood by `table.blade.php`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\Agreements\SaveAmendment;
use App\Actions\Agreements\SubmitAmendment;
use App\Actions\Approvals\DecideApproval;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Reports\RentRollReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-06-10 09:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    [$this->mine, $this->theirs] = Building::factory()->count(2)->create()->all();
    $unit = fn (Building $b, string $code, array $extra = []) => Unit::factory()->for($b)->create(['code' => $code, ...$extra]);
    $u1 = $unit($this->mine, 'A101');
    $u2 = $unit($this->mine, 'A102');
    $this->spare = $unit($this->mine, 'A103', ['list_rent' => '350.000']);
    $unit($this->mine, 'A104', ['blocked' => true, 'blocked_reason' => 'Works']);
    $unit($this->theirs, 'T201');
    $this->leasing = matrixUser(RoleName::Leasing, $this->mine);
    $this->finance = matrixUser(RoleName::Finance, $this->mine);
    $this->agreement = activeAgreement(['customer_id' => Customer::factory()->create(['name_en' => 'Alice Tenant'])->id, 'start_date' => '2026-01-01',
        'end_date' => '2026-12-31', 'created_by' => $this->leasing->id], [$u1, $u2]);
    $au = $this->agreement->agreementUnits()->orderBy('id')->get();
    DepositMovement::create(['agreement_unit_id' => $au[0]->id, 'type' => 'opening', 'amount' => '400.000', 'source_type' => 'import', 'source_id' => $this->agreement->id, 'posted_at' => '2026-06-01 10:00:00']);
    issuedInvoice($this->agreement->customer, [['net' => '400.000', 'au' => $au[0]], ['net' => '400.000', 'au' => $au[1]]], '2026-06-01', $this->agreement); // unpaid: 800 owed
    $this->number = $this->agreement->number;
});

test('one row per unit as at a date, a subtotal per building and a grand total', function () {
    $html = Livewire::actingAs($this->finance)->test(RentRollReport::class)->html();
    $m = $this->mine->code;

    expect(reportRowText($html, 'A101'))->toBe("{$m} A101 Flat / Apartment Occupied Alice Tenant {$this->number} 01/01/2026 31/12/2026 400.000 400.000 800.000")
        ->and(reportRowText($html, 'A102'))->toBe("{$m} A102 Flat / Apartment Occupied Alice Tenant {$this->number} 01/01/2026 31/12/2026 400.000 0.000 (all units)")
        ->and(reportRowText($html, 'A103'))->toBe("{$m} A103 Flat / Apartment Vacant 350.000")    // list rent, muted
        ->and(reportRowText($html, 'A104'))->toBe("{$m} A104 Flat / Apartment Blocked")
        ->and(reportRowText($html, "Subtotal {$m}"))->toBe("Subtotal {$m} 4 units 2 occupied, 66.7% 800.000 400.000 800.000") // 2 ÷ (4 − 1 blocked)
        ->and(reportRowText($html, 'T201'))->toBe("{$this->theirs->code} T201 Flat / Apartment Vacant 400.000")
        ->and(reportRowText($html, '5 units'))->toBe('Total 5 units 2 occupied, 50.0% 800.000 400.000 800.000');
});

test('without reports.financial the money columns are hidden; a building-scoped user sees only their buildings', function () {
    $html = Livewire::actingAs($this->leasing)->test(RentRollReport::class)->assertDontSee('Monthly rent')->assertDontSee('T201')->html();

    expect(reportRowText($html, 'A101'))->toBe("{$this->mine->code} A101 Flat / Apartment Occupied Alice Tenant {$this->number} 01/01/2026 31/12/2026")
        ->and(reportRowText($html, 'A103'))->toBe("{$this->mine->code} A103 Flat / Apartment Vacant")
        ->and(reportRowText($html, '4 units'))->toBe("Subtotal {$this->mine->code} 4 units 2 occupied, 66.7%")
        ->and($html)->not->toContain('800.000')->not->toContain('350.000');
});

test('a unit added by an amendment shows its own rent from its start; before then it is vacant', function () {
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $draft = app(SaveAmendment::class)->handle($this->leasing, $this->agreement, null, ['type' => 'add_unit', 'unit_id' => $this->spare->id, 'effective_date' => '2026-06-15',
        'reason' => 'Expansion', 'deposit_amount' => '300.000', 'charges' => [['type' => 'rent', 'monthly_amount' => '300.000', 'tax_category' => 'exempt']]]);
    app(DecideApproval::class)->handle($management, app(SubmitAmendment::class)->handle($this->leasing, $draft), true);

    $page = Livewire::actingAs($this->finance)->test(RentRollReport::class);
    expect(reportRowText($page->html(), 'A103'))->toBe("{$this->mine->code} A103 Flat / Apartment Vacant 350.000");

    expect(reportRowText($page->set('to', '2026-06-20')->html(), 'A103'))
        ->toBe("{$this->mine->code} A103 Flat / Apartment Occupied Alice Tenant {$this->number} 15/06/2026 31/12/2026 300.000 0.000 (all units)");
});

test('the rent roll exports to Excel, audited; a bad date shows an error, not a crash; it is listed on the Reports page', function () {
    Livewire::actingAs($this->leasing)->test(RentRollReport::class)->call('export')->assertFileDownloaded('rent_roll-2026-06-10.xlsx');
    expect(Activity::where('event', 'report.exported')->where('properties->report', 'rent_roll')->sole()->properties->only(['building', 'to'])->all())
        ->toEqual(['building' => null, 'to' => '2026-06-10']);

    Livewire::actingAs($this->leasing)->test(RentRollReport::class)->set('to', 'not-a-date')->assertHasErrors('to')->assertOk()
        ->call('export')->assertNoFileDownloaded();

    $this->actingAs($this->leasing)->get(route('reports.index'))->assertSee('Rent roll');
});
```

In `tests/Feature/Permissions/ReportsPermissionMatrixTest.php`, add to the `->with([...])` list after `['reports.occupancy', $operational],`:

```php
    ['reports.rent-roll', $operational],
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test tests/Feature/Reports/RentRollTest.php`
Expected: FAIL with `Class "App\Livewire\Reports\RentRollReport" not found`.

- [ ] **Step 3: The report**

```php
<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Unit;
use App\Reports\Queries;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Spec 2026-10-16 §3: one row per unit as at a date, a subtotal per building and a grand total. Occupancy follows the
 * Occupancy report (Queries::currentAgreementUnits; % = occupied ÷ (units − blocked)). Rent = the occupying agreement
 * unit's rent charges (plan ruling 2); deposit held as at the date; balance owed live, once per agreement (ruling 4).
 */
class RentRollReport extends Component
{
    use ReportPage;

    protected function title(): string
    {
        return __('Rent roll');
    }

    protected function permission(): bool
    {
        return $this->actor()->can('reports.operational');
    }

    protected function dateMode(): string
    {
        return 'single'; // "as at" is $to
    }

    private function showMoney(): bool
    {
        return $this->actor()->can('reports.financial');
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return [
            'building' => __('Building'), 'unit' => __('Unit'), 'type' => __('Type'), 'status' => __('Status'), 'tenant' => __('Tenant'),
            'agreement' => __('Agreement'), 'start' => __('Start'), 'end' => __('End'),
            ...($this->showMoney() ? ['rent' => __('Monthly rent'), 'deposit' => __('Deposit held'), 'balance' => __('Balance owed')] : []),
        ];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['rent', 'deposit', 'balance'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $on = $this->to;
        $user = $this->actor();
        $rent = DB::table('agreement_unit_charges')->where('type', 'rent')->groupBy('agreement_unit_id')
            ->select('agreement_unit_id', DB::raw('SUM(monthly_amount) as rent'));
        $held = DB::table('deposit_movements')->where('posted_at', '<=', $on.' 23:59:59')->groupBy('agreement_unit_id')
            ->select('agreement_unit_id', DB::raw('SUM(amount) as held'));
        $owed = DB::table('invoices')->where('status', 'issued')->where('type', '<>', 'credit_note')->whereNotNull('agreement_id')
            ->groupBy('agreement_id')->select('agreement_id', DB::raw('SUM(balance) as owed'));

        $units = DB::table('units as u')
            ->join('buildings as b', 'b.id', '=', 'u.building_id')
            ->leftJoin('unit_types as ut', 'ut.code', '=', 'u.type')
            ->leftJoinSub(Queries::currentAgreementUnits($user, $on), 'cur', 'cur.unit_id', '=', 'u.id')
            ->leftJoin('agreement_units as au', 'au.id', '=', 'cur.au_id')
            ->leftJoin('agreements as a', 'a.id', '=', 'au.agreement_id')
            ->leftJoin('customers as c', 'c.id', '=', 'a.customer_id')
            ->leftJoinSub($rent, 'r', 'r.agreement_unit_id', '=', 'au.id')
            ->leftJoinSub($held, 'd', 'd.agreement_unit_id', '=', 'au.id')
            ->leftJoinSub($owed, 'o', 'o.agreement_id', '=', 'a.id')
            ->whereIn('u.id', Unit::query()->visibleTo($user)->select('id'))
            ->when($this->building, fn ($q, int $b) => $q->where('u.building_id', $b))
            ->orderBy('b.code')->orderBy('u.code')
            ->get(['u.building_id', 'b.code as building', 'u.code as unit', 'u.blocked', 'u.list_rent', 'u.type', 'ut.name as type_name',
                'au.id as au_id', 'au.start_date', 'au.end_date', 'a.id as agreement_id', 'a.number', 'c.name_en as tenant', 'r.rent', 'd.held', 'o.owed']);

        $money = $this->showMoney();
        $fils = fn (mixed $v) => Fils::fromDecimal((string) ($v ?? '0'));
        $date = fn (mixed $d) => $d === null ? '' : CarbonImmutable::parse((string) $d)->format('d/m/Y');
        $rows = [];
        $seen = [];
        $all = ['units' => 0, 'blocked' => 0, 'occupied' => 0, 'counted' => 0, 'rent' => 0, 'deposit' => 0, 'balance' => 0];
        foreach ($units->groupBy('building_id') as $group) {
            $sub = array_fill_keys(array_keys($all), 0);
            $code = '';
            foreach ($group as $u) {
                $code = (string) $u->building;
                $occupied = $u->au_id !== null;
                $blocked = (bool) $u->blocked;
                $first = $occupied && ! isset($seen[$u->agreement_id]);
                if ($occupied) {
                    $seen[$u->agreement_id] = true;
                }
                $rentFils = $occupied ? $fils($u->rent) : 0;
                $depositFils = $occupied ? $fils($u->held) : 0;
                $balanceFils = $first ? $fils($u->owed) : 0;
                $sub['units']++;
                $sub['blocked'] += (int) $blocked;
                $sub['occupied'] += (int) $occupied;
                $sub['counted'] += (int) ($occupied && ! $blocked); // a blocked unit can't count towards the %
                $sub['rent'] += $rentFils;
                $sub['deposit'] += $depositFils;
                $sub['balance'] += $balanceFils;

                $rows[] = [
                    'building' => $code, 'unit' => $u->unit, 'type' => $u->type_name ?? $u->type,
                    'status' => $occupied ? __('Occupied') : ($blocked ? __('Blocked') : __('Vacant')),
                    'tenant' => $u->tenant ?? '', 'agreement' => $u->number ?? '', 'start' => $date($u->start_date), 'end' => $date($u->end_date),
                    ...($money ? [
                        'rent' => $occupied ? Fils::toDecimal($rentFils) : ($blocked ? '' : Fils::toDecimal($fils($u->list_rent))),
                        'deposit' => $occupied ? Fils::toDecimal($depositFils) : '',
                        'balance' => $occupied ? ($first ? Fils::toDecimal($balanceFils) : __('(all units)')) : '',
                    ] : []),
                    '_muted' => ! $occupied && ! $blocked ? 'rent' : null, // a vacant unit's list rent
                ];
            }
            $rows[] = $this->totalRow(__('Subtotal :building', ['building' => $code]), $sub, $money);
            foreach ($sub as $k => $v) {
                $all[$k] += $v;
            }
        }
        if ($rows !== []) {
            $rows[] = $this->totalRow(__('Total'), $all, $money);
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $t
     * @return array<string, string|int|null>
     */
    private function totalRow(string $label, array $t, bool $money): array
    {
        $available = $t['units'] - $t['blocked'];

        return [
            'building' => $label,
            'unit' => __(':n units', ['n' => $t['units']]),
            'status' => __(':n occupied, :pct', ['n' => $t['occupied'], 'pct' => $available > 0 ? number_format(100 * $t['counted'] / $available, 1).'%' : '—']),
            ...($money ? ['rent' => Fils::toDecimal($t['rent']), 'deposit' => Fils::toDecimal($t['deposit']), 'balance' => Fils::toDecimal($t['balance'])] : []),
            '_kind' => 'total',
        ];
    }
}
```

- [ ] **Step 4: Shared table view.** In `resources/views/livewire/reports/table.blade.php`, replace the building select block:

```blade
        @if ($buildingFilter)
            <flux:select wire:model.live="building" :label="__('Building')">
                <flux:select.option value="">{{ __('All buildings') }}</flux:select.option>
                @foreach ($buildings as $b)
                    <flux:select.option :value="$b->id">{{ $b->code }} — {{ $b->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
```

with:

```blade
        @if ($buildingFilter)
            <x-building-filter :buildings="$buildings" />
        @endif
```

Then replace the row and cell openers:

```blade
                    <flux:table.row :key="'r-'.$i">
                        @foreach (array_keys($columns) as $j => $key)
                            <flux:table.cell :class="in_array($key, $numeric, true) ? 'text-end tabular-nums' : ''">
```

with:

```blade
                    <flux:table.row :key="'r-'.$i" :class="empty($row['_kind']) ? '' : 'font-semibold'">
                        @foreach (array_keys($columns) as $j => $key)
                            <flux:table.cell :class="(in_array($key, $numeric, true) ? 'text-end tabular-nums' : '').(($row['_muted'] ?? null) === $key ? ' text-zinc-400 dark:text-zinc-500' : '')">
```

- [ ] **Step 5: Route and catalogue.** In `routes/property.php`, after the `reports/occupancy` line:

```php
    Route::livewire('reports/rent-roll', Reports\RentRollReport::class)->middleware('can:reports.operational')->name('reports.rent-roll');
```

In `App\Livewire\Reports\Index::catalogue()`, add this as the first entry of `'operational'`:

```php
                ['route' => 'reports.rent-roll', 'label' => __('Rent roll'), 'icon' => 'table-cells', 'about' => __('Every unit with its tenant, rent, deposit and balance, as at a date.')],
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/Reports/RentRollTest.php tests/Feature/Permissions/ReportsPermissionMatrixTest.php tests/Feature/Reports`
Expected: PASS. The existing report tests still pass with the `<x-building-filter>` swap.

- [ ] **Step 7: Gates.** Run Pint `--dirty`, phpstan and `npm run build`. All must be clean.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Reports/RentRollReport.php app/Livewire/Reports/Index.php resources/views/livewire/reports/table.blade.php routes/property.php tests/Feature/Reports/RentRollTest.php tests/Feature/Permissions/ReportsPermissionMatrixTest.php
git commit -m "$(cat <<'EOF'
Rent roll report: units as at a date with rent, deposit held, balance owed and building subtotals

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Company income statement screen

**Files:**
- Create: `app/Livewire/Reports/Concerns/StatementPage.php`, `app/Livewire/Reports/IncomeStatementReport.php`
- Create: `resources/views/livewire/reports/partials/statement-inputs.blade.php`, `resources/views/livewire/reports/partials/statement-table.blade.php`, `resources/views/livewire/reports/income-statement.blade.php`
- Modify: `routes/property.php`, `app/Livewire/Reports/Index.php` (financial group, after Building profitability), `tests/Feature/Permissions/ReportsPermissionMatrixTest.php`
- Test: `tests/Feature/Reports/IncomeStatementTest.php` (append)

**Interfaces:**
- Consumes: `IncomeStatement::company()`, `IncomeStatement::months()`, `IncomeStatement::isEmpty()`, `IncomeStatement::COLLECTED|BILLED`, and the `Statement` type (`@phpstan-import-type Statement from IncomeStatement`).
- Produces:
  - Trait `StatementPage` with public props `$from`, `$to` (`Y-m`) and `$basis`. It provides `mountStatementPage()` and `updatedStatementPage()`. Its protected methods are `monthOptions(): array<string,string>`, `statementRules(): array`, `inputsValid(): bool`, `period(): array{0: string, 1: string}`, `scopeBuildingIds(): ?list<int>`, `ownerOptions(): array<int,string>` and `download(string $report, array $statement, array $properties): BinaryFileResponse`. It declares abstract `scopeRules(): array`.
  - Partials `statement-inputs` (needs `$months`) and `statement-table` (needs `$statement`).
  - Route `reports.income-statement`. Audit report key `income_statement`. Download name `income-statement-{from}-{to}.xlsx`.

- [ ] **Step 1: Write the failing tests.** Append these `use` lines:

```php
use App\Livewire\Reports\IncomeStatementReport;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
```

Then append the tests:

```php
test('the income statement screen: last 12 complete months by default, one column per month, both bases, Excel audited', function () {
    $page = Livewire::actingAs($this->finance)->test(IncomeStatementReport::class)
        ->assertSet('from', '2025-04')->assertSet('to', '2026-03')->assertSet('basis', 'collected');

    $html = $page->set('from', '2026-03')->set('to', '2026-04')->html();
    expect($html)->toContain('03/2026')->toContain('04/2026')
        ->and(reportRowText($html, 'Not linked to a unit'))->toBe('Not linked to a unit 40.000 0.000 40.000')
        ->and(reportRowText($html, 'Result'))->toBe('Result 208.000 -12.000 196.000');

    expect(reportRowText($page->set('basis', 'billed')->html(), 'Head lease'))->toBe('Head lease 50.000 30.000 80.000');

    $page->call('export')->assertFileDownloaded('income-statement-2026-03-2026-04.xlsx');
    expect(Activity::where('event', 'report.exported')->where('properties->report', 'income_statement')->sole()->properties->only(['from', 'to', 'basis', 'building', 'owner'])->all())
        ->toEqual(['from' => '2026-03', 'to' => '2026-04', 'basis' => 'billed', 'building' => null, 'owner' => null]);

    $page->set('from', '2025-06')->set('to', '2025-06')->assertSee('Nothing in this period.');
    $this->actingAs($this->finance)->get(route('reports.index'))->assertSee('Income statement');
});

test('a building-scoped user sees only their buildings: no unlinked line, no other building, no other building\'s owners', function () {
    $scoped = User::factory()->withTwoFactor()->create()->givePermissionTo('reports.financial');
    $scoped->buildings()->attach($this->building->id);

    $html = Livewire::actingAs($scoped)->test(IncomeStatementReport::class)->set('from', '2026-03')->set('to', '2026-04')
        ->assertDontSee('Not linked to a unit')->assertDontSee($this->other->code)->assertDontSee('Omar Outside')->assertSee('Leila Lessor')
        ->set('building', $this->other->id)->assertHasErrors('building')->assertOk()
        ->set('building', null)->html();

    expect(reportRowText($html, 'Result'))->toBe('Result 175.000 -12.000 163.000');
});

test('tampered inputs give a message, never a 500, and no export', function () {
    Livewire::actingAs($this->finance)->test(IncomeStatementReport::class)
        ->set('basis', 'cash')->assertHasErrors('basis')->assertOk()
        ->set('basis', 'billed')->set('from', '2025-01')->set('to', '2026-04')->assertHasErrors('to')->assertOk()   // 16 months
        ->set('from', '2026-04')->set('to', '2026-03')->assertHasErrors('to')
        ->set('owner', 999999)->assertHasErrors('owner')
        ->set('owner', null)->set('from', '1999-01')->assertHasErrors('from')->assertOk()
        ->call('export')->assertNoFileDownloaded();

    expect(Activity::where('event', 'report.exported')->count())->toBe(0);
});

test('owner scope on the screen narrows to that owner\'s contracts', function () {
    $html = Livewire::actingAs($this->finance)->test(IncomeStatementReport::class)->set('from', '2026-03')->set('to', '2026-04')
        ->set('owner', $this->leased->owner_id)->html();

    expect(reportRowText($html, 'Result'))->toBe('Result 60.000 0.000 60.000')->and($html)->not->toContain('Management fees');
});
```

In `ReportsPermissionMatrixTest.php`, add after `['reports.vat', $financial],`:

```php
    ['reports.income-statement', $financial],
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test tests/Feature/Reports/IncomeStatementTest.php`
Expected: the new tests FAIL with `Class "App\Livewire\Reports\IncomeStatementReport" not found`.

- [ ] **Step 3: The trait**

```php
<?php

namespace App\Livewire\Reports\Concerns;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Reports\IncomeStatement;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Spec 2026-10-16 §4–§5: the income statements' period (whole months, at most 12), basis, building scope and Excel
 * file. Inputs are whitelisted; a bad one shows a message and no statement, never a 500.
 *
 * @phpstan-import-type Statement from IncomeStatement
 */
trait StatementPage
{
    use WithActor;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $basis = IncomeStatement::COLLECTED;

    /** @return array<string, list<mixed>> the screen's own inputs */
    abstract protected function scopeRules(): array;

    public function mountStatementPage(): void
    {
        abort_unless($this->actor()->can('reports.financial'), 403);
        $month = CarbonImmutable::now('Asia/Bahrain')->startOfMonth();
        $this->from = $this->from ?: $month->subMonths(12)->format('Y-m'); // the last 12 complete months
        $this->to = $this->to ?: $month->subMonth()->format('Y-m');
    }

    /** A trait hook (Livewire calls updated{TraitName}). */
    public function updatedStatementPage(): void
    {
        $this->validate($this->statementRules());
    }

    /**
     * Plan ruling 14. ponytail: a fixed 36-month window; widen it if anyone needs older statements.
     *
     * @return array<string, string> Y-m => "March 2026", newest first
     */
    protected function monthOptions(): array
    {
        $options = [];
        $month = CarbonImmutable::now('Asia/Bahrain')->startOfMonth();
        for ($i = 0; $i < 36; $i++, $month = $month->subMonth()) {
            $options[$month->format('Y-m')] = $month->translatedFormat('F Y');
        }

        return $options;
    }

    /** @return array<string, list<mixed>> */
    protected function statementRules(): array
    {
        $months = array_keys($this->monthOptions());

        return [
            'from' => ['required', 'string', Rule::in($months)],
            'to' => ['required', 'string', Rule::in($months), function (string $attribute, mixed $value, Closure $fail) use ($months) {
                if (! is_string($value) || ! in_array($this->from, $months, true)) {
                    return;
                }
                if ($value < $this->from) {
                    $fail(__('The last month cannot be before the first.'));
                } elseif (count(IncomeStatement::months($this->from.'-01', $value.'-01')) > 12) {
                    $fail(__('Choose at most 12 months.'));
                }
            }],
            'basis' => ['required', 'string', Rule::in([IncomeStatement::COLLECTED, IncomeStatement::BILLED])],
            ...$this->scopeRules(),
        ];
    }

    protected function inputsValid(): bool
    {
        return Validator::make($this->all(), $this->statementRules())->passes();
    }

    /** @return array{0: string, 1: string} the period's first and last day */
    protected function period(): array
    {
        return [$this->from.'-01', CarbonImmutable::parse($this->to.'-01')->endOfMonth()->toDateString()];
    }

    /** @return list<int>|null the user's buildings; null = every building (buildings.view_all, plan ruling 8) */
    protected function scopeBuildingIds(): ?array
    {
        $user = $this->actor();

        return $user->can(PermissionName::BuildingsViewAll) ? null
            : Building::visibleTo($user)->pluck('id')->map(fn (mixed $id) => (int) $id)->values()->all();
    }

    /** @return array<int, string> owners with at least one contract in a building the user can see (spec §5) */
    protected function ownerOptions(): array
    {
        return Owner::query()->whereIn('id', OwnerContract::query()->visibleTo($this->actor())->select('owner_id'))
            ->orderBy('name_en')->pluck('name_en', 'id')->all();
    }

    /**
     * Spec §4: the same table, one row per line, months as columns. Audited like the other reports.
     *
     * @param  Statement  $statement
     * @param  array<string, int|null>  $properties
     */
    protected function download(string $report, array $statement, array $properties): BinaryFileResponse
    {
        abort_unless($this->actor()->can('reports.financial'), 403);
        Audit::log('report.exported', properties: ['report' => $report, 'from' => $this->from, 'to' => $this->to, 'basis' => $this->basis, ...$properties], causer: $this->actor());

        $path = sys_get_temp_dir().'/rms-'.$report.'-'.Str::uuid().'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        $writer->addHeader([__('Line'), ...array_map(fn (string $m) => CarbonImmutable::parse($m.'-01')->format('m/Y'), $statement['months']), __('Total')]);
        foreach ($statement['rows'] as $row) {
            $writer->addRow([$row['label'], ...array_map(fn (?int $v) => $v === null ? '–' : $v / 1000, array_values($row['amounts'])), $row['total'] / 1000]);
        }
        $writer->close();

        return response()->download($path, Str::slug($report)."-{$this->from}-{$this->to}.xlsx")->deleteFileAfterSend();
    }
}
```

- [ ] **Step 4: The company screen**

```php
<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\StatementPage;
use App\Models\Building;
use App\Reports\IncomeStatement;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Spec 2026-10-16 §4: the company income statement. Scope: all of the user's buildings, one building, one owner, or
 * the owner's contracts in one building (plan ruling 9).
 *
 * @phpstan-import-type Statement from IncomeStatement
 */
class IncomeStatementReport extends Component
{
    use StatementPage;

    #[Url]
    public ?int $building = null;

    #[Url]
    public ?int $owner = null;

    /** @return array<string, list<mixed>> */
    protected function scopeRules(): array
    {
        return [
            'building' => ['nullable', 'integer', Rule::in(Building::visibleTo($this->actor())->pluck('id')->all())],
            'owner' => ['nullable', 'integer', Rule::in(array_keys($this->ownerOptions()))],
        ];
    }

    /** @return Statement */
    private function compute(): array
    {
        [$from, $to] = $this->period();

        return IncomeStatement::company($from, $to, $this->basis, $this->building !== null ? [$this->building] : $this->scopeBuildingIds(), $this->owner);
    }

    public function export(): BinaryFileResponse
    {
        $this->validate($this->statementRules());

        return $this->download('income_statement', $this->compute(), ['building' => $this->building, 'owner' => $this->owner]);
    }

    public function render(): View
    {
        return view('livewire.reports.income-statement', [
            'statement' => $this->inputsValid() ? $this->compute() : null,
            'months' => $this->monthOptions(),
            'buildings' => Building::visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
            'owners' => $this->ownerOptions(),
        ])->title(__('Income statement'));
    }
}
```

- [ ] **Step 5: The views**

`resources/views/livewire/reports/partials/statement-inputs.blade.php`:

```blade
{{-- Period (whole months), basis and the Excel button, shared by both income statements. Needs $months (Y-m => label). --}}
<flux:select wire:model.live="from" :label="__('From')">
    @foreach ($months as $value => $label)
        <option value="{{ $value }}">{{ $label }}</option>
    @endforeach
</flux:select>
<flux:select wire:model.live="to" :label="__('To')">
    @foreach ($months as $value => $label)
        <option value="{{ $value }}">{{ $label }}</option>
    @endforeach
</flux:select>
<flux:select wire:model.live="basis" :label="__('Basis')">
    <option value="collected">{{ __('Collected') }}</option>
    <option value="billed">{{ __('Billed') }}</option>
</flux:select>
<div class="flex items-end"><flux:button wire:click="export">{{ __('Export to Excel') }}</flux:button></div>
```

`resources/views/livewire/reports/partials/statement-table.blade.php`:

```blade
{{-- An income statement: one row per line, one column per month, then Total. A null amount is a fee not yet final. --}}
<div class="overflow-x-auto">
    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Line') }}</flux:table.column>
            @foreach ($statement['months'] as $m)
                <flux:table.column align="end">{{ \Carbon\CarbonImmutable::parse($m.'-01')->format('m/Y') }}</flux:table.column>
            @endforeach
            <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($statement['rows'] as $key => $row)
                <flux:table.row :key="$key" :class="$row['kind'] === 'total' ? 'font-semibold' : ($row['kind'] === 'memo' ? 'italic text-zinc-500' : '')">
                    <flux:table.cell>{{ $row['label'] }}</flux:table.cell>
                    @foreach ($row['amounts'] as $amount)
                        <flux:table.cell class="text-end tabular-nums">{{ $amount === null ? '–' : \App\Support\Fils::toDecimal($amount) }}</flux:table.cell>
                    @endforeach
                    <flux:table.cell class="text-end tabular-nums">{{ \App\Support\Fils::toDecimal($row['total']) }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
@if (collect($statement['rows'])->contains(fn (array $r) => in_array(null, $r['amounts'], true)))
    <flux:text class="text-sm">– {{ __('Fee not final until the owner statement for this month is finalised.') }}</flux:text>
@endif
```

`resources/views/livewire/reports/income-statement.blade.php`:

```blade
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl">{{ __('Income statement') }}</flux:heading>
        <flux:link :href="route('reports.index')" wire:navigate>{{ __('All reports') }}</flux:link>
    </div>

    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <x-building-filter :buildings="$buildings" />
        <flux:select wire:model.live="owner" :label="__('Owner')">
            <option value="">{{ __('All owners') }}</option>
            @foreach ($owners as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </flux:select>
        @include('livewire.reports.partials.statement-inputs')
    </div>

    @if ($statement !== null)
        @if (\App\Reports\IncomeStatement::isEmpty($statement))
            <flux:text>{{ __('Nothing in this period.') }}</flux:text>
        @else
            @include('livewire.reports.partials.statement-table')
        @endif
    @endif

    <flux:text class="text-sm">{{ __('Collected: money received, by posting date, and head lease paid. Billed: invoices issued less credit notes, and head lease due. Amounts are net of VAT; rent on managed units and deposits are left out.') }}</flux:text>
</div>
```

- [ ] **Step 6: Route and catalogue.** In `routes/property.php`, after the `reports/building-profitability` line:

```php
    Route::livewire('reports/income-statement', Reports\IncomeStatementReport::class)->middleware('can:reports.financial')->name('reports.income-statement');
```

In `Index::catalogue()`, in `'financial'`, after the building-profitability entry:

```php
                ['route' => 'reports.income-statement', 'label' => __('Income statement'), 'icon' => 'document-chart-bar', 'about' => __('Income, costs and result by month, collected or billed.')],
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test tests/Feature/Reports/IncomeStatementTest.php tests/Feature/Permissions/ReportsPermissionMatrixTest.php`
Expected: PASS.

- [ ] **Step 8: Gates.** Run Pint `--dirty`, phpstan and `npm run build`.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/Reports/Concerns/StatementPage.php app/Livewire/Reports/IncomeStatementReport.php app/Livewire/Reports/Index.php resources/views/livewire/reports/partials resources/views/livewire/reports/income-statement.blade.php routes/property.php tests/Feature/Reports/IncomeStatementTest.php tests/Feature/Permissions/ReportsPermissionMatrixTest.php
git commit -m "$(cat <<'EOF'
Company income statement screen: months as columns, collected or billed, building and owner scope, Excel

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Owner income statement screen, and the full suite

**Files:**
- Create: `app/Livewire/Reports/OwnerIncomeStatementReport.php`, `resources/views/livewire/reports/owner-income-statement.blade.php`
- Modify: `routes/property.php`, `app/Livewire/Reports/Index.php`, `tests/Feature/Permissions/ReportsPermissionMatrixTest.php`
- Test: `tests/Feature/Reports/IncomeStatementTest.php` (append)

**Interfaces:**
- Consumes: `IncomeStatement::owner()` (Task 2), the `StatementPage` trait and both partials (Task 4).
- Produces: route `reports.owner-income-statement`. Public props `?int $owner` and `string $ownerSearch`. Audit report key `owner_income_statement`. Download name `owner-income-statement-{from}-{to}.xlsx`.

- [ ] **Step 1: Write the failing tests.** Append `use App\Livewire\Reports\OwnerIncomeStatementReport;`, then:

```php
test('the owner screen lists owners, filters them by name, shows the fee as – until final, and exports audited', function () {
    $page = Livewire::actingAs($this->finance)->test(OwnerIncomeStatementReport::class)
        ->assertSee('Choose an owner to see their statement.')->assertSee('Leila Lessor')->assertSee('Mansour Manager')
        ->set('ownerSearch', 'leila')->assertSee('Leila Lessor')->assertDontSee('Mansour Manager')
        ->set('ownerSearch', '')->set('owner', $this->managed->owner_id)->set('from', '2026-03')->set('to', '2026-04');

    $html = $page->html();
    expect(reportRowText($html, 'Less management fee'))->toBe('Less management fee and VAT 11.000 – 11.000')
        ->and(reportRowText($html, 'Net income to owner'))->toBe('Net income to owner 69.000 0.000 69.000')
        ->and($html)->toContain('Fee not final until the owner statement for this month is finalised.');

    $page->call('export')->assertFileDownloaded('owner-income-statement-2026-03-2026-04.xlsx');
    expect(Activity::where('event', 'report.exported')->where('properties->report', 'owner_income_statement')->sole()->properties->only(['owner', 'basis'])->all())
        ->toEqual(['owner' => $this->managed->owner_id, 'basis' => 'collected']);

    $this->actingAs($this->finance)->get(route('reports.index'))->assertSee('Owner income statement');
});

test('an owner is listed only if the user can see one of their buildings; tampered owners give a message', function () {
    $scoped = User::factory()->withTwoFactor()->create()->givePermissionTo('reports.financial');
    $scoped->buildings()->attach($this->building->id);
    $outside = Owner::where('name_en', 'Omar Outside')->sole();

    Livewire::actingAs($scoped)->test(OwnerIncomeStatementReport::class)
        ->assertSee('Leila Lessor')->assertDontSee('Omar Outside')
        ->set('owner', $outside->id)->assertHasErrors('owner')->assertOk()
        ->set('owner', 999999)->assertHasErrors('owner')->assertOk()
        ->set('owner', null)->call('export')->assertHasErrors('owner')->assertNoFileDownloaded();
});

test('a leased owner on the screen: head lease earned and memo payments', function () {
    $html = Livewire::actingAs($this->finance)->test(OwnerIncomeStatementReport::class)
        ->set('owner', $this->leased->owner_id)->set('from', '2026-03')->set('to', '2026-04')->set('basis', 'billed')->html();

    expect(reportRowText($html, 'Head lease earned'))->toBe('Head lease earned 50.000 30.000 80.000')
        ->and(reportRowText($html, 'Memo: paid to owner'))->toBe('Memo: paid to owner 50.000 0.000 50.000')
        ->and($html)->not->toContain('Less management fee');
});
```

In `ReportsPermissionMatrixTest.php`, add after the income-statement row:

```php
    ['reports.owner-income-statement', $financial],
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test tests/Feature/Reports/IncomeStatementTest.php`
Expected: the new tests FAIL with `Class "App\Livewire\Reports\OwnerIncomeStatementReport" not found`.

- [ ] **Step 3: The owner screen**

```php
<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\StatementPage;
use App\Reports\IncomeStatement;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Spec 2026-10-16 §5: one owner's income statement over their contracts in the user's buildings. The owner picker is a
 * search box filtering a select (plan ruling 15).
 *
 * @phpstan-import-type Statement from IncomeStatement
 */
class OwnerIncomeStatementReport extends Component
{
    use StatementPage;

    #[Url]
    public ?int $owner = null;

    public string $ownerSearch = '';

    /** @return array<string, list<mixed>> */
    protected function scopeRules(): array
    {
        return ['owner' => ['nullable', 'integer', Rule::in(array_keys($this->ownerOptions()))]];
    }

    /** @return Statement|null */
    private function compute(): ?array
    {
        if ($this->owner === null) {
            return null;
        }
        [$from, $to] = $this->period();

        return IncomeStatement::owner($this->owner, $from, $to, $this->basis, $this->scopeBuildingIds());
    }

    public function export(): ?BinaryFileResponse
    {
        $this->validate($this->statementRules());
        $statement = $this->compute();
        if ($statement === null) {
            $this->addError('owner', __('Choose an owner.'));

            return null;
        }

        return $this->download('owner_income_statement', $statement, ['owner' => $this->owner]);
    }

    public function render(): View
    {
        $owners = $this->ownerOptions();
        $term = mb_strtolower(trim($this->ownerSearch));

        return view('livewire.reports.owner-income-statement', [
            'statement' => $this->inputsValid() ? $this->compute() : null,
            'months' => $this->monthOptions(),
            'owners' => $term === '' ? $owners : array_filter($owners,
                fn (string $name, int $id) => $id === $this->owner || str_contains(mb_strtolower($name), $term), ARRAY_FILTER_USE_BOTH),
        ])->title(__('Owner income statement'));
    }
}
```

`resources/views/livewire/reports/owner-income-statement.blade.php`:

```blade
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl">{{ __('Owner income statement') }}</flux:heading>
        <flux:link :href="route('reports.index')" wire:navigate>{{ __('All reports') }}</flux:link>
    </div>

    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <flux:input wire:model.live.debounce.300ms="ownerSearch" :label="__('Find an owner')" :placeholder="__('Name')" icon="magnifying-glass" autocomplete="off" />
        <flux:select wire:model.live="owner" :label="__('Owner')">
            <option value="">{{ __('Choose an owner') }}</option>
            @foreach ($owners as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </flux:select>
        @include('livewire.reports.partials.statement-inputs')
    </div>

    @if ($owner === null)
        <flux:text>{{ __('Choose an owner to see their statement.') }}</flux:text>
    @elseif ($statement !== null)
        @if (\App\Reports\IncomeStatement::isEmpty($statement))
            <flux:text>{{ __('Nothing in this period.') }}</flux:text>
        @else
            @include('livewire.reports.partials.statement-table')
        @endif
    @endif

    <flux:text class="text-sm">{{ __('Collected: rent and charges received on the owner\'s units, net of VAT, without deposits, as in the owner ledger. Months agree with finalised owner statements, which cover calendar months.') }}</flux:text>
</div>
```

- [ ] **Step 4: Route and catalogue.** In `routes/property.php`, after the income-statement route:

```php
    Route::livewire('reports/owner-income-statement', Reports\OwnerIncomeStatementReport::class)->middleware('can:reports.financial')->name('reports.owner-income-statement');
```

In `Index::catalogue()`, in `'financial'`, after the income-statement entry:

```php
                ['route' => 'reports.owner-income-statement', 'label' => __('Owner income statement'), 'icon' => 'user-circle', 'about' => __('What an owner earned each month, after fees and expenses.')],
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/Reports/IncomeStatementTest.php tests/Feature/Permissions/ReportsPermissionMatrixTest.php`
Expected: PASS.

- [ ] **Step 6: Full suite and gates.** Run these serially:
  - `php artisan test`: all tests pass, and `IntegrityCheck` still expects 61 triggers.
  - `php vendor/bin/pint --dirty`
  - `php vendor/bin/phpstan analyse --memory-limit=2G`
  - `npm run build`

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Reports/OwnerIncomeStatementReport.php app/Livewire/Reports/Index.php resources/views/livewire/reports/owner-income-statement.blade.php routes/property.php tests/Feature/Reports/IncomeStatementTest.php tests/Feature/Permissions/ReportsPermissionMatrixTest.php
git commit -m "$(cat <<'EOF'
Owner income statement screen: owner picker, fee shown as – until final, memo payments, Excel

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-review notes (done while writing)

- **Spec coverage:**
  - §2 one calculator: Task 1, with BP reading from it.
  - §3 rent roll: Task 3. Every column, subtotal, grand total, sort, Excel and money gate is covered (rulings 2–5).
  - §4 lines on both bases: Task 1. Company-earned rule, expense VAT, owner scope (ruling 7) and "Not linked to a unit" (ruling 8) are covered. Screen, empty message and Excel: Task 4.
  - §5 lines, memo and fee "–" with footnote: Task 2 (calculator) and Task 5 (screen, picker, owner visibility).
  - §6: Task 1 Step 4, with the old BP tests run unchanged in Step 5.
  - §7: whitelisted inputs and tamper tests in Tasks 3–5. Scope comes from `visibleTo` and `ownerOptions()`. No migration.
  - §8: each listed test maps to a test above. One gap: "amended rent" is covered by the add_unit amendment (ruling 2), because no rent-change amendment exists.
- **Placeholder scan:** none. The phpstan note in Task 1 Step 3 is a named fallback that mirrors existing code.
- **Type consistency:**
  - `IncomeStatement::company/owner/months/isEmpty`, `COLLECTED`/`BILLED`, and the `Statement`/`Row` shapes are used the same way in Tasks 1–5.
  - Row keys (`total_income`, `total_costs`, `result`, `management_fees`, `head_lease`, `fee`, `expenses`, `net`, `memo_paid`, `charge_<type>`) match between the calculator, the tests and BP.
  - `StatementPage` methods (`statementRules`, `inputsValid`, `period`, `scopeBuildingIds`, `ownerOptions`, `download`, `monthOptions`) match between Tasks 4 and 5.
  - The route names `reports.rent-roll`, `reports.income-statement` and `reports.owner-income-statement` are the same in the routes, the catalogue and the permission matrix.
- **Fixture arithmetic:**
  - Invoice: 645 net + 33 VAT = 678, plus the manual 40, so the 718 payment settles both.
  - Managed statement: 100 − 20 − (10 + 1) = 69.
  - Building B Collected: 255 income − 92 costs = 163.
  - Billed April rent: 100 − 50 net credited = 50.
