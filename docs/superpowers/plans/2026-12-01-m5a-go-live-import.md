# M5a Go-live Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Take a company live with tenants partway through their leases: import customers, active agreements, opening balances, deposits held, held cheques and owner opening balances as at a formal cutover date, with reconciliation totals, and make the nightly jobs run as a System user that can't stop on one bad record.

**Architecture:** Builds on M4 (branch `m4-owner-finance`, head 986a938). The importer stays one class, `RunImport`: the dry run and the real run are the same code, and everything goes inside one transaction with a savepoint per row (per agreement for grouped agreement rows). The cutover date is the date of `company_settings.go_live_at`. It must be set before importing, and imports close once it passes (that check already exists). Imported agreements and contracts are activated through the normal Actions in an "imported" mode: schedules start from cutover, and agreements get no deposit invoice. Balances, deposits and cheques are written through the same tables the app uses.

**Tech Stack:** as M4; `spatie/simple-excel` for reading and templates.

**Spec:** `docs/superpowers/specs/2026-09-28-rental-management-v1-design.md`. Sections: §15 M5 row (import half); §11 (data import); §4.6 (owner attribution on the cutover date); §5.6, §7.6 (deposits); §7.4 (held cheques); §7.9 and §11 (owner opening balance); §12 (nightly jobs); §8.2 (building scope); §13 (operations, for the runbook).

## Global Constraints

Everything in the M0–M4 Global Constraints still applies:
- Every monetary column is `DECIMAL(12,3)`. PHP money maths is in integer fils (`App\Support\Fils`), half-up to the fil.
- Actions are the only write path. Each top-level Action runs in `DB::transaction(…, attempts: 3)`. Internal Actions assert `DB::transactionLevel() > 0`.
- The importer's own transaction (`DB::beginTransaction` in `RunImport::handle`) is the caller's transaction for every Action it calls.
- Lock order (§7.2): customers → cheques → invoice lines (ascending) → invoices (ascending) → payments → disbursements. Owner side: owner_contracts → owner_payables / owner_statements → disbursements.
- Two DB users. Migrations run with `--database=migrator`.
- This plan adds no triggers, so `IntegrityCheck::EXPECTED_TRIGGERS` stays **59**. A migration that adds a column to a table with a guard trigger must not change that trigger.
- Spec §11 rules (verbatim):
  - "running the import requires `import.run` (Vendor Support only). `company_settings.go_live_at` is set at cutover; after that, the import screens and Actions refuse to run."
  - "ID, phone, IBAN and money columns are formatted as Text in the templates."
  - "every file is validated first, row by row with Laravel's Validator (money read as strings to 3 decimals), and an error report lists each row's problems. Nothing is saved until all files are clean; then the import runs in a single transaction per file set."
  - "Imported agreements are created `active`, with an approval row recorded as "Imported by {user}". They get no deposit invoice."
  - "Scheduled invoices and head-lease payables are generated only for periods starting on or after cutover, on the agreement's (or contract's) own anchor. The period containing cutover counts as billed in the old system; its unpaid part is in the opening balance."
  - "Balances only: historical invoices stay in the old system."
  - "Deposits held are imported as `opening` deposit movements. They do not post to the owner ledger; the imported owner opening balance (`owner_charges`, type `opening_balance`) is the only source for what is owed to the owner."
- §12: every job is idempotent, uses `withoutOverlapping(120)` and pings a Forge heartbeat. The command returns failure when any record failed, so the heartbeat stays silent.
- English UI usable at 375 px. Free Flux only.
- Tests: Pest on MySQL as `rms_app`, run serially (never `--parallel`, never two runs at once). Finance and Management users need `->withTwoFactor()`. A test that activates agreements or issues invoices fakes the local disk.

## Rulings made while planning

1. **Cutover date = the date of `go_live_at`** (Asia/Bahrain). It is set with `php artisan rms:setting go_live_at YYYY-MM-DD` before importing. An import with no go-live date is refused. Once that day starts, imports close; that rule already exists.
2. **The import writes with its own authority.** Vendor Support runs the import but lacks `cheques.manage`, `invoices.manage` and the other finance permissions, by design (§8.1).
   - Held cheques, opening invoices, deposit movements and owner opening charges are therefore written directly by `RunImport`, with the same validation and the same columns the Finance Actions use.
   - Customers and agreements go through `SaveCustomer` and `SaveAgreement`, whose permissions Vendor Support has.
3. **Opening customer balances must be positive (owed by the customer).** A customer credit at cutover is not imported. Finance records it as a payment after go-live, with the reference "Opening credit". `payments.method` has no opening value, and adding one would touch the payments CHECK for a handful of rows.
4. **An opening balance is one opening invoice per row**, issued at import:
   - `issue_date = due_date` = the cutover date;
   - one `opening_balance` line, out of scope for VAT, because the amount already includes any VAT billed in the old system;
   - the line is stamped with the agreement unit when given, so §4.6 attributes it to the unit's owner contract on the cutover date.
5. **Agreement rows are grouped by `import_ref`**:
   - one row per agreement unit, and rows with the same reference make one agreement;
   - the reference is stored on `agreements.import_ref`, so the balances, deposits and cheques files can point at it;
   - an error is reported against the group's first spreadsheet line.
6. **The System user** is a real `users` row (`is_system = true`, `active = false`, random password). It cannot sign in, is hidden from user administration, and is the creator of everything the nightly jobs create. It is created on first use (`User::system()`), so fresh installs and tests need no seed.
7. **Owner opening balance** is one `opening_balance` owner charge per managed contract, posted at import time. A row names the owner (ID) and the building; the managed contract active on the cutover date is the target. A second one for the same contract is refused.

## Working environment

```bash
export PHP="/c/Users/ababy/.config/herd/bin/php85/php.exe"
cd /c/Users/ababy/Documents/RentalManagementSystem
# MySQL stops between sessions: if "connection refused", run C:\Users\ababy\.mysql\start-mysql.cmd
```
Branch: `m5-reports-golive`, from `m4-owner-finance`.

## Conventions carried over (do not re-create)

- **Importer:**
  - `RunImport` (`handle(User, array $paths, bool $commit): ImportResult`, `ensureOpen()`, private `importRow`, `ownerContract`, `buildingId`, `normalise`)
  - `ImportResult(errors, counts, committed)`
  - `ImportKind` (`headers()`, `textColumns()`; case order = processing order)
  - `ImportTemplateController`, the `Livewire\Import\Index` screen and `tests/Feature/Import/ImportTest.php` (its `importFile(ImportKind, rows)` helper)
- **Agreements:**
  - `SaveAgreement::handle(User, ?Agreement, array)` (keys: `customer_id`, `start_date`, `end_date`, `frequency`, `billing_day`, `grace_days`, `notice_period_days`, `units[*].unit_id|deposit_amount|start_date|end_date|charges[*].type|description|monthly_amount|tax_category`; exactly one rent charge per unit)
  - `ActivateAgreement::handle(Agreement, User $approver)` (internal; schedule → deposit invoice or transfer → issue due)
  - `GenerateRentSchedule::handle(Agreement, User)` (internal; stamps `agreement_unit_charge_id` on lines)
- **Owner contracts and billing:**
  - `ActivateOwnerContract::handle(OwnerContract, ?CarbonImmutable $payablesFrom = null)`
  - `IssueInvoice::handle(Invoice, ?User $issuer = null, bool $autoAllocate = true): bool` (stamps owner attribution via `OwnerContract::effectiveOn`)
  - `IssueDueInvoices`
- **Customers and owners:**
  - `SaveCustomer::handle(User, ?Customer, array)` (keys: `type`, `name_en`, `name_ar`, `id_type`, `id_number`, `nationality`, `mobile`, `email`, `address`, `contact_person`, `emergency_contact_name`, `emergency_contact_phone`, `notes`)
  - `Customer`, `Cheque` (`direction`, `customer_id`, `agreement_id`, `cheque_no`, `bank_name`, `account_holder`, `cheque_date`, `amount`, `status` held, `created_by`)
  - `DepositMovement` (`agreement_unit_id`, `owner_contract_id`, `type`, `amount`, `source_type`, `source_id`, `posted_at`)
  - `OwnerCharge`, `OwnerChargeType::OpeningBalance`
  - `Approval`, `ApprovalAction::{AgreementActivation, OwnerContractActivation}`, `ApprovalStatus::Approved`
- **Enums:** `InvoiceType::Opening`, `InvoiceChargeType` value `opening_balance`, `TaxCategory::OutOfScope`
- **Nightly jobs:**
  - `ExpireAgreements` (`__invoke(): int`, `checkOne(int)`, a ponytail stand-in for the settlement creator) and its command `ExpireAgreementsCommand`
  - `DraftOwnerStatements::handle(CarbonImmutable): array{created: int, failed: int}` (attributes drafts to the contract's creator, M4 ruling 8)
- **Other:** `rms:setting`, `CompanySetting::current()->go_live_at`, `Audit::log`, test helpers `activeOwnerContract()`, `activeAgreement()`, `matrixUser()`, `scheduledEvent()`.

---

### Task 1: The System user and fault-tolerant nightly jobs

**Spec:** §12 (every job idempotent; a heartbeat after each run), §8.3 (the approver must differ from the record's creator, so nightly records need a creator no person is), plan ruling 6. M3b/M4 carry-ins: "a system user for nightly jobs" and "per-agreement isolation in the 02:00 job".

**Files:**
- Create: `database/migrations/2026_12_01_000100_add_is_system_to_users.php`, `tests/Feature/Jobs/SystemUserTest.php`
- Modify: `app/Models/User.php`, `app/Livewire/Admin/Users/Index.php`, `app/Livewire/Admin/Users/Form.php`, `app/Actions/Agreements/ExpireAgreements.php`, `app/Console/Commands/ExpireAgreementsCommand.php`, `app/Actions/OwnerStatements/DraftOwnerStatements.php`, `tests/Feature/OwnerStatements/OwnerStatementFiguresTest.php`

**Interfaces:**
- Produces:
  - `User::system(): User`, `users.is_system`
  - `ExpireAgreements::$failed` (public int, set by `__invoke`)
  - nightly settlements and owner-statement drafts are created by `User::system()`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Jobs/SystemUserTest.php`:
```php
<?php

use App\Actions\Agreements\ExpireAgreements;
use App\Actions\Approvals\DecideApproval;
use App\Actions\EnsureNumberSequences;
use App\Actions\OwnerStatements\DraftOwnerStatements;
use App\Actions\OwnerStatements\SubmitOwnerStatement;
use App\Enums\RoleName;
use App\Livewire\Admin\Users\Index;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositSettlement;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-04-01 02:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
});

test('the System user exists once, cannot sign in and is hidden from user administration', function () {
    $system = User::system();

    expect(User::system()->id)->toBe($system->id)
        ->and($system->is_system)->toBeTrue()->and($system->active)->toBeFalse();

    $this->post('/login', ['email' => $system->email, 'password' => 'anything'])->assertSessionHasErrors();
    $this->assertGuest();

    $admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    Livewire::actingAs($admin)->test(Index::class)->assertDontSee($system->email);
    $this->actingAs($admin)->get(route('admin.users.edit', $system))->assertNotFound();
});

test('nightly settlements and statement drafts are created by the System user, so any manager can approve them', function () {
    $manager = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $building = Building::factory()->create();
    [$u1, $u2] = Unit::factory()->for($building)->count(2)->create()->all();
    $contract = activeOwnerContract(['building_id' => $building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'fixed', 'fee_value' => '10.000', 'created_by' => $manager->id], [$u1, $u2]);
    $agreement = activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2025-04-01', 'end_date' => '2026-03-31'], [$u1]);
    $agreement->agreementUnits()->update(['move_out_date' => '2026-03-15']);

    app(ExpireAgreements::class)();
    app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-03-01'));

    $statement = OwnerStatement::where('owner_contract_id', $contract->id)->sole();
    expect(DepositSettlement::where('agreement_id', $agreement->id)->value('created_by'))->toBe(User::system()->id)
        ->and($statement->created_by)->toBe(User::system()->id);

    // The contract's own creator could not approve a draft attributed to them (M4 ruling 8); now they can.
    app(DecideApproval::class)->handle($manager, app(SubmitOwnerStatement::class)->handle($finance, $statement), true);
    expect($statement->fresh()->status->value)->toBe('finalised');
});

test('one agreement failing does not stop the 02:00 run, and the command reports failure', function () {
    Exceptions::fake();
    $make = fn () => activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2025-04-01', 'end_date' => '2026-03-31'], [Unit::factory()->create()]);
    $bad = $make();
    $good = $make();
    Agreement::updating(fn (Agreement $a) => $a->id === $bad->id ? throw new RuntimeException('boom') : null);

    $expire = app(ExpireAgreements::class);
    expect($expire())->toBe(1)->and($expire->failed)->toBe(1)
        ->and($good->fresh()->status->value)->toBe('expired')->and($bad->fresh()->status->value)->toBe('active');
    Exceptions::assertReported(RuntimeException::class);

    $this->artisan('rms:agreements:expire')->assertExitCode(1);
});
```
Before running, check two things and adapt the test (not the code) if they differ:
- the `rms:agreements:expire` signature in `ExpireAgreementsCommand`;
- the users edit route name in `routes/admin.php`, `admin.users.edit`.

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Jobs/SystemUserTest.php`
Expected: FAIL with `Call to undefined method App\Models\User::system()`.

- [ ] **Step 3: Implement**

Create `database/migrations/2026_12_01_000100_add_is_system_to_users.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_system'));
    }
};
```
In `app/Models/User.php`:
- add `'is_system' => 'boolean'` to the casts and `@property bool $is_system` to the docblock;
- add the method below (imports `Illuminate\Support\Facades\Hash`, `Illuminate\Support\Str`):
```php
    /**
     * Plan ruling 6: the creator of what the nightly jobs create. Inactive, so it can never sign in
     * (FortifyServiceProvider checks `active`); created on first use.
     */
    public static function system(): self
    {
        return self::query()->where('is_system', true)->first()
            ?? tap((new self)->forceFill([
                'name' => 'System',
                'email' => 'system@rms.invalid',
                'password' => Hash::make(Str::random(64)),
                'active' => false,
                'is_system' => true,
            ]))->save();
    }
```
In `app/Livewire/Admin/Users/Index.php`, add `->where('is_system', false)` to the users query. In `app/Livewire/Admin/Users/Form.php` `mount()`, add `abort_if($user?->is_system, 404);`. Match the parameter name the component uses.

In `app/Actions/Agreements/ExpireAgreements.php`:
```php
    /** Records that failed in the last run; the command fails when this is above 0 (spec §12). */
    public int $failed = 0;

    public function __invoke(): int
    {
        $today = now('Asia/Bahrain')->toDateString();
        $this->failed = 0;

        $changed = 0;
        $due = Agreement::query()->whereIn('status', [AgreementStatus::Active, AgreementStatus::Expired])->where('end_date', '<', $today)->orderBy('id')->pluck('id');
        foreach ($due as $id) {
            try {
                $changed += DB::transaction(fn () => $this->checkOne($id) !== null ? 1 : 0, attempts: 3);
            } catch (Throwable $e) {
                $this->failed++;
                report($e); // one agreement must not hold back the rest
            }
        }

        // … the $ended query is unchanged …
        foreach ($ended as $agreementId => $units) {
            try {
                DB::transaction(function () use ($agreementId, $units) {
                    $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreementId);
                    $this->settlement->handle($agreement, array_values($units->map(fn (AgreementUnit $au): int => $au->id)->all()), User::system());
                }, attempts: 3);
            } catch (Throwable $e) {
                $this->failed++;
                report($e);
            }
        }

        return $changed;
    }
```
Remove the old `ponytail:` comment and add `use Throwable;`. In `ExpireAgreementsCommand::handle`:
```php
        $changed = $expire();
        $this->info(sprintf('%d agreement(s) expired, closed, terminated or renewed; %d failed.', $changed, $expire->failed));

        return $expire->failed > 0 ? self::FAILURE : self::SUCCESS;
```
In `app/Actions/OwnerStatements/DraftOwnerStatements.php`, replace `'created_by' => $contract->created_by, // plan ruling 8, …` with `'created_by' => User::system()->id, // spec §8.3: no person created a nightly draft`. Then update the M4 assertion in `tests/Feature/OwnerStatements/OwnerStatementFiguresTest.php` that expects `created_by` to equal the contract's creator: it now expects `User::system()->id`.

`User::system()` is called inside the jobs' transactions. If two runs race to create it, the unique `email` makes the second insert fail and retry (`attempts: 3`), and the retry finds the row.

- [ ] **Step 4: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Jobs tests/Feature/OwnerStatements tests/Feature/Agreements tests/Feature/Users tests/Feature/Deposits
"$PHP" artisan test
```
Expected: every test passes. If a users-list or user-count test fails because `User::system()` created a row, scope that test's query to `is_system = false`; don't remove the System user.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Run nightly jobs as a System user, one record at a time

Nightly settlements and statement drafts are now created by an inactive
System user, so any manager can approve them. The 02:00 agreement run
keeps going past a failing agreement and its command reports failure,
which keeps the heartbeat silent.
EOF
```
(End the message with your Co-Authored-By trailer.)

---

### Task 2: The formal cutover date

**Spec:** §11 ("`company_settings.go_live_at` is set at cutover"; "periods starting on or after cutover … The period containing cutover counts as billed in the old system"), plan ruling 1. M4 carry-in: `RunImport` passes today as `payablesFrom`.

**Files:**
- Modify: `app/Actions/Import/RunImport.php`, `app/Livewire/Import/Index.php`, `resources/views/livewire/import/index.blade.php`, `tests/Feature/Import/ImportTest.php`

**Interfaces:**
- Produces:
  - `RunImport::cutover(): CarbonImmutable` (start of the go-live day, Asia/Bahrain; `ValidationException` keyed `import` when unset)
  - `importRow(User $actor, ImportKind $kind, array $row, CarbonImmutable $cutover)`: the cutover is passed down to every row

- [ ] **Step 1: Write the failing test**

In `tests/Feature/Import/ImportTest.php`:
- change the `beforeEach` settings row to `CompanySetting::factory()->create(['go_live_at' => '2026-11-01 00:00:00']);`, so cutover is 1 November 2026 and today is 5 October 2026;
- add a leased contract row to `owner_contracts`: `['owner_id_type' => 'cpr', 'owner_id_number' => '080101234', 'building_code' => 'MT', 'units' => '102', 'type' => 'leased', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'rent_amount' => '300.000', 'payment_frequency' => 'monthly']`;
- give the managed row `'units' => '101'`, so the two contracts don't overlap;
- update any count assertions that change (`owner_contracts` now 2);
- add these tests:
```php
test('imports need the go-live date: it is the cutover date', function () {
    CompanySetting::current()->forceFill(['go_live_at' => null])->save();

    expect(fn () => app(RunImport::class)->handle($this->vendor, $this->files, commit: false))
        ->toThrow(ValidationException::class, 'Set the go-live date first');
});

test('head-lease payables start with the first period on or after cutover', function () {
    app(RunImport::class)->handle($this->vendor, $this->files, commit: true);

    $leased = OwnerContract::where('type', 'leased')->sole();
    expect($leased->payables()->orderBy('period_start')->pluck('period_start')->map->toDateString()->all())
        ->toBe(['2026-11-01', '2026-12-01']);
});

test('the import screen shows the cutover date', function () {
    Livewire::actingAs($this->vendor)->test(Index::class)->assertSee('Cutover date: 01/11/2026');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Import/ImportTest.php`
Expected: the new tests FAIL. The leased contract's payables start on 2026-10-05's period (today) instead of 2026-11-01, and the screen text is missing.

- [ ] **Step 3: Implement**

In `app/Actions/Import/RunImport.php`:
```php
    /** Plan ruling 1: imports are as at the go-live day (spec §11). */
    public static function cutover(): CarbonImmutable
    {
        $goLive = CompanySetting::current()->go_live_at
            ?? throw ValidationException::withMessages(['import' => __('Set the go-live date first (php artisan rms:setting go_live_at YYYY-MM-DD): imports are as at that date.')]);

        return CarbonImmutable::instance($goLive)->timezone('Asia/Bahrain')->startOfDay();
    }
```
- In `handle()`, call `self::ensureOpen();` then `$cutover = self::cutover();` before `DB::beginTransaction()`.
- Pass `$cutover` through `importRow(...)` to `ownerContract($actor, $row, $cutover)`.
- Replace the ponytail line and the `CarbonImmutable::today(...)` argument with `$this->activate->handle($contract, $cutover);`. The other `importRow` arms ignore the argument.

In `app/Livewire/Import/Index.php` `render()`, pass `'cutover' => $closed === null ? rescue(fn () => RunImport::cutover(), null, false) : null`. In the view, inside the `@else` branch, above the file inputs:
```blade
        @if ($cutover)
            <flux:callout icon="calendar" :heading="__('Cutover date: :d', ['d' => $cutover->format('d/m/Y')])">
                {{ __('Balances, deposits and cheques are as at this date. Schedules start with the first period on or after it.') }}
            </flux:callout>
        @else
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Set the go-live date first (php artisan rms:setting go_live_at YYYY-MM-DD).')" />
        @endif
```

- [ ] **Step 4: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Import tests/Feature/OwnerContracts
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Import as at the go-live date

The go-live date is now the cutover date: an import refuses to run
without it, imported head-lease payables start with the first period
on or after it, and the import screen shows it.
EOF
```

---

### Task 3: Importing customers

**Spec:** §11 (customers template; ID and phone columns as Text), §5.1 (customer fields, unique ID).

**Files:**
- Modify: `app/Enums/ImportKind.php`, `app/Actions/Import/RunImport.php`, `tests/Feature/Import/ImportTest.php`

**Interfaces:**
- Produces: `ImportKind::Customers = 'customers'` (processed after `OwnerContracts`)

- [ ] **Step 1: Write the failing test**

In `tests/Feature/Import/ImportTest.php` add to `$this->files`:
```php
        'customers' => importFile(ImportKind::Customers, [
            ['type' => 'person', 'name_en' => 'Sara Ahmed', 'id_type' => 'cpr', 'id_number' => '090202345', 'mobile' => '+97336000000', 'email' => 'sara@example.com'],
            ['type' => 'company', 'name_en' => 'Gulf Trading WLL', 'id_type' => 'cr', 'id_number' => '12345-1', 'mobile' => '+97317000000', 'contact_person' => 'Omar'],
        ]),
```
Update the dry-run count expectation to include `'customers' => 2`. Then add these tests:
```php
test('customers import with their IDs kept as text', function () {
    app(RunImport::class)->handle($this->vendor, $this->files, commit: true);

    expect(Customer::where('id_number', '090202345')->value('name_en'))->toBe('Sara Ahmed')->and(Customer::count())->toBe(2);
});

test('a duplicate customer ID or a numeric mobile is reported against its line', function () {
    $files = [...$this->files, 'customers' => importFile(ImportKind::Customers, [
        ['type' => 'person', 'name_en' => 'A', 'id_type' => 'cpr', 'id_number' => '090202345', 'mobile' => '+97336000000'],
        ['type' => 'person', 'name_en' => 'B', 'id_type' => 'cpr', 'id_number' => '090202345', 'mobile' => '+97336000001'],
        ['type' => 'person', 'name_en' => 'C', 'id_type' => 'cpr', 'id_number' => '090202346', 'mobile' => 97336000002],
    ])];

    $result = app(RunImport::class)->handle($this->vendor, $files, commit: true);

    expect($result->committed)->toBeFalse()->and(array_keys($result->errors['customers']))->toBe([3, 4])
        ->and($result->errors['customers'][4][0])->toContain('formatted as Text');
});
```
(Import `App\Models\Customer`.)

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Import/ImportTest.php`
Expected: FAIL with `Undefined constant App\Enums\ImportKind::Customers`.

- [ ] **Step 3: Implement**

In `app/Enums/ImportKind.php`:
- add `case Customers = 'customers';` after `OwnerContracts`;
- add its headers: `self::Customers => ['type', 'name_en', 'name_ar', 'id_type', 'id_number', 'nationality', 'mobile', 'email', 'address', 'contact_person', 'emergency_contact_name', 'emergency_contact_phone', 'notes'],`;
- add its text columns: `self::Customers => ['id_number', 'mobile', 'emergency_contact_phone'],`.

In `RunImport`, inject `private SaveCustomer $customers` (`App\Actions\Customers\SaveCustomer`) and add the `importRow` arm `ImportKind::Customers => $this->customers->handle($actor, null, $row),`. The template download picks up the new kind by itself (`ImportTemplateController` uses `headers()`), and so does the screen (it lists `ImportKind::cases()`).

- [ ] **Step 4: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Import
```
Expected: every test passes.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Import customers

A customers file goes through the normal customer rules, keeps IDs and
phones as text, and reports duplicates against their spreadsheet line.
EOF
```

---
### Task 4: Importing active agreements

**Spec:** §11 (active agreements with units, charges and deposits; created `active` with an "Imported by {user}" approval; no deposit invoice; scheduled invoices only for periods starting on or after cutover, on the agreement's own anchor), §5.2 (agreement and unit fields), §6.2 (periods), plan ruling 5. M3b carry-in: "the importer must stamp `invoice_lines.agreement_unit_charge_id`" — satisfied by generating the schedule through `GenerateRentSchedule`.

**Files:**
- Create: `database/migrations/2026_12_01_000200_add_import_ref_to_agreements.php`
- Modify: `app/Enums/ImportKind.php`, `app/Actions/Import/RunImport.php`, `app/Actions/Agreements/ActivateAgreement.php`, `app/Actions/Billing/GenerateRentSchedule.php`, `app/Models/Agreement.php`, `tests/Feature/Import/ImportTest.php`

**Interfaces:**
- Consumes: `RunImport::cutover()` and the `$cutover` argument of `importRow` (Task 2); `ImportKind::Customers` (Task 3)
- Produces:
  - `ImportKind::Agreements = 'agreements'` (after `Customers`)
  - `agreements.import_ref` (unique, nullable)
  - `ActivateAgreement::handle(Agreement $agreement, User $approver, ?CarbonImmutable $importedAt = null)` — with `$importedAt`, the schedule starts at the first period on or after it and no deposit invoice or transfer is made
  - `GenerateRentSchedule::handle(Agreement, User, ?CarbonImmutable $from = null)`
  - `importRow(User, ImportKind, list<array<string, mixed>> $rows, CarbonImmutable $cutover)` — one row for every kind except `Agreements`, which receives a group

- [ ] **Step 1: Write the failing test**

In `tests/Feature/Import/ImportTest.php`, add a file helper and these tests:
```php
/** Three units (102 blocked), the two customers, and one two-unit agreement L-001 on 101 and 103. */
function agreementFiles(array $base, array $agreementRows = null): array
{
    return [...$base,
        'units' => importFile(ImportKind::Units, [
            ['building_code' => 'MT', 'code' => '101', 'use' => 'residential', 'type' => 'flat', 'furnishing' => 'unfurnished', 'list_rent' => '450.000', 'blocked' => 'no'],
            ['building_code' => 'MT', 'code' => '102', 'use' => 'residential', 'type' => 'flat', 'furnishing' => 'semi', 'list_rent' => '480.500', 'blocked' => 'yes', 'blocked_reason' => 'Renovation'],
            ['building_code' => 'MT', 'code' => '103', 'use' => 'residential', 'type' => 'flat', 'furnishing' => 'unfurnished', 'list_rent' => '500.000', 'blocked' => 'no'],
        ]),
        'agreements' => importFile(ImportKind::Agreements, $agreementRows ?? [
            ['import_ref' => 'L-001', 'customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'frequency' => 'monthly',
                'building_code' => 'MT', 'unit_code' => '101', 'deposit_amount' => '450.000', 'rent' => '450.000', 'tax_category' => 'exempt'],
            ['import_ref' => 'L-001', 'customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'frequency' => 'monthly',
                'building_code' => 'MT', 'unit_code' => '103', 'deposit_amount' => '500.000', 'rent' => '500.000', 'service_charge' => '20.000', 'tax_category' => 'exempt'],
        ]),
    ];
}

test('rows sharing a reference import as one active agreement, scheduled from cutover, with no deposit invoice', function () {
    Storage::fake('local');
    $result = app(RunImport::class)->handle($this->vendor, agreementFiles($this->files), commit: true);

    expect($result->errors)->toBe([])->and($result->counts['agreements'])->toBe(2);
    $agreement = Agreement::where('import_ref', 'L-001')->sole();
    expect($agreement->status->value)->toBe('active')->and($agreement->number)->not->toBeNull()
        ->and($agreement->agreementUnits()->count())->toBe(2)
        ->and($agreement->approvals()->sole()->comment)->toBe('Imported by '.$this->vendor->name)
        ->and($agreement->invoices()->where('type', 'deposit')->exists())->toBeFalse()
        ->and($agreement->invoices()->where('type', 'rent')->orderBy('period_start')->pluck('period_start')->map->toDateString()->all())->toBe(['2026-11-01', '2026-12-01'])
        ->and(InvoiceLine::whereIn('invoice_id', $agreement->invoices()->pluck('id'))->whereNull('agreement_unit_charge_id')->exists())->toBeFalse();
});

test('an agreement over before cutover, or with an unknown customer, is refused on its first line', function () {
    $row = fn (string $ref, string $id, string $end, string $unit) => ['import_ref' => $ref, 'customer_id_type' => 'cpr', 'customer_id_number' => $id, 'start_date' => '2026-01-01', 'end_date' => $end,
        'frequency' => 'monthly', 'building_code' => 'MT', 'unit_code' => $unit, 'rent' => '450.000', 'tax_category' => 'exempt'];

    $result = app(RunImport::class)->handle($this->vendor, agreementFiles($this->files, [
        $row('L-002', '090202345', '2026-10-31', '101'),
        $row('L-003', '999999999', '2026-12-31', '103'),
    ]), commit: false);

    expect(array_keys($result->errors['agreements']))->toBe([2, 3])
        ->and($result->errors['agreements'][2][0])->toContain('still running at cutover')
        ->and($result->errors['agreements'][3][0])->toContain('No customer');
});

test('an agreement reference can only be imported once', function () {
    Storage::fake('local');
    app(RunImport::class)->handle($this->vendor, agreementFiles($this->files), commit: true);

    $again = app(RunImport::class)->handle($this->vendor, ['agreements' => importFile(ImportKind::Agreements, [
        ['import_ref' => 'L-001', 'customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'frequency' => 'monthly', 'building_code' => 'MT', 'unit_code' => '103', 'rent' => '500.000', 'tax_category' => 'exempt'],
    ])], commit: false);

    expect($again->errors['agreements'][2][0])->toContain('already imported');
});
```
Add the imports `App\Models\Agreement`, `App\Models\InvoiceLine` and `Illuminate\Support\Facades\Storage`.

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Import/ImportTest.php`
Expected: FAIL with `Undefined constant App\Enums\ImportKind::Agreements`.

- [ ] **Step 3: Migration, enum, activation in imported mode**

Create `database/migrations/2026_12_01_000200_add_import_ref_to_agreements.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agreements', function (Blueprint $table) {
            $table->string('import_ref', 40)->nullable()->unique()->after('number'); // the legacy system's reference (spec §11)
        });
    }

    public function down(): void
    {
        Schema::table('agreements', function (Blueprint $table) {
            $table->dropUnique(['import_ref']);
            $table->dropColumn('import_ref');
        });
    }
};
```
If the agreements guard trigger compares a fixed column list, leave it unchanged; the new column is set while the row is still a draft. Add `@property string|null $import_ref` to `app/Models/Agreement.php`.

In `app/Enums/ImportKind.php`, add `case Agreements = 'agreements';` after `Customers`, with:
```php
            self::Agreements => ['import_ref', 'customer_id_type', 'customer_id_number', 'start_date', 'end_date', 'frequency', 'billing_day', 'grace_days',
                'notice_period_days', 'building_code', 'unit_code', 'unit_start_date', 'unit_end_date', 'deposit_amount', 'rent', 'service_charge', 'parking',
                'other', 'other_description', 'tax_category'],
```
and the text columns `self::Agreements => ['customer_id_number', 'deposit_amount', 'rent', 'service_charge', 'parking', 'other'],`.

`RunImport::normalise` converts every column ending in `_date` from dd/mm/yyyy. `unit_start_date` and `unit_end_date` end in `_date`, so they are covered.

In `app/Actions/Billing/GenerateRentSchedule.php`:
```php
    /**
     * @param  CarbonImmutable|null  $from  spec §11: an imported agreement is billed from the first period starting on or
     *                                      after cutover; the period containing cutover was billed in the old system
     * @return Collection<int, Invoice>
     */
    public function handle(Agreement $agreement, User $actor, ?CarbonImmutable $from = null): Collection
    {
        // … the transaction-level guard is unchanged …
        foreach (BillingPeriods::for($agreement->start_date, $agreement->end_date, $agreement->frequency, $agreement->billing_day) as $period) {
            if ($from !== null && $period->start->lessThan($from)) {
                continue;
            }
            // … unchanged …
```
In `app/Actions/Agreements/ActivateAgreement.php`:
```php
    /** @param  CarbonImmutable|null  $importedAt  spec §11: the cutover date of an imported agreement */
    public function handle(Agreement $agreement, User $approver, ?CarbonImmutable $importedAt = null): Agreement
    {
        // … unchanged up to the number and token …
        $this->schedule->handle($agreement, $approver, $importedAt);
        if ($importedAt === null) {
            $agreement->previous_agreement_id !== null
                ? $this->transfer->handle($agreement, $approver)   // spec §5.8
                : $this->deposit->handle($agreement, $approver);
        } // imported agreements get no deposit invoice: deposits held come from the deposits file (spec §11)
        ($this->issueDue)($agreement, $approver);

        return $agreement;
    }
```

- [ ] **Step 4: Grouped rows and the agreement import**

In `RunImport::handle`, replace the per-row loop with a loop over units of work: a group per `import_ref` for agreements, and one row otherwise.
```php
                $items = [];
                foreach ($reader->getRows() as $index => $row) {
                    $items[(int) $index + 2] = $row; // spreadsheet line: header is line 1
                }

                $passed = 0;
                foreach (self::units($kind, $items) as $line => $group) {
                    try {
                        // A savepoint per unit of work: a failed one leaves nothing behind for later ones to trip on.
                        DB::transaction(fn () => $this->importRow($actor, $kind, array_map(fn (array $r) => $this->normalise($kind, $r), $group), $cutover));
                        $passed += count($group);
                    } catch (ValidationException $e) {
                        $errors[$kind->value][$line] = array_values(Arr::flatten($e->errors()));
                    }
                }

                $counts[$kind->value] = $passed;
```
and add:
```php
    /**
     * Plan ruling 5: agreement rows group by import_ref (one row per agreement unit); every other kind is one row each.
     * Keyed by the group's first spreadsheet line, which its errors are reported against.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, list<array<string, mixed>>>
     */
    private static function units(ImportKind $kind, array $items): array
    {
        if ($kind !== ImportKind::Agreements) {
            return array_map(fn (array $row) => [$row], $items);
        }

        $groups = [];
        $firstLine = [];
        foreach ($items as $line => $row) {
            $ref = trim((string) ($row['import_ref'] ?? ''));
            $key = $ref === '' ? "line-{$line}" : $ref; // a row without a reference fails on its own
            $firstLine[$key] ??= $line;
            $groups[$firstLine[$key]][] = $row;
        }

        return $groups;
    }
```
`importRow` now takes `list<array> $rows`. Every arm except agreements uses `$rows[0]`, and the agreements arm is `ImportKind::Agreements => $this->agreement($actor, $rows, $cutover),`. Inject `private SaveAgreement $agreements` and `private ActivateAgreement $activateAgreement`, and add:
```php
    /** Spec §11: an active agreement, approved as "Imported by {user}", billed from cutover. @param  list<array<string, mixed>>  $rows */
    private function agreement(User $actor, array $rows, CarbonImmutable $cutover): void
    {
        $first = $rows[0];
        $ref = (string) ($first['import_ref'] ?? '');
        if ($ref === '') {
            throw ValidationException::withMessages(['import_ref' => __('Give each agreement a reference (import_ref).')]);
        }
        if (Agreement::query()->where('import_ref', $ref)->exists()) {
            throw ValidationException::withMessages(['import_ref' => __('Agreement :ref was already imported.', ['ref' => $ref])]);
        }
        if ((string) ($first['end_date'] ?? '') < $cutover->toDateString()) {
            throw ValidationException::withMessages(['end_date' => __('Only agreements still running at cutover are imported (:ref ends :d).', ['ref' => $ref, 'd' => (string) ($first['end_date'] ?? '')])]);
        }

        $customerId = Customer::query()->where('id_type', $first['customer_id_type'] ?? '')->where('id_number', $first['customer_id_number'] ?? '')->value('id')
            ?? throw ValidationException::withMessages(['customer_id_number' => __('No customer with ID :type :number.', ['type' => $first['customer_id_type'] ?? '', 'number' => $first['customer_id_number'] ?? ''])]);

        $units = array_map(function (array $row) {
            $unit = Unit::query()->where('building_id', $this->buildingId($row['building_code'] ?? null))->where('code', (string) ($row['unit_code'] ?? ''))->first()
                ?? throw ValidationException::withMessages(['unit_code' => __('No unit :code in :building.', ['code' => (string) ($row['unit_code'] ?? ''), 'building' => (string) ($row['building_code'] ?? '')])]);
            $tax = $row['tax_category'] ?? $unit->default_tax_category?->value;
            $charges = [['type' => 'rent', 'monthly_amount' => $row['rent'] ?? null, 'tax_category' => $tax]];
            foreach (['service_charge', 'parking'] as $type) {
                if (filled($row[$type] ?? null)) {
                    $charges[] = ['type' => $type, 'monthly_amount' => $row[$type], 'tax_category' => $tax];
                }
            }
            if (filled($row['other'] ?? null)) {
                $charges[] = ['type' => 'other', 'description' => $row['other_description'] ?? null, 'monthly_amount' => $row['other'], 'tax_category' => $tax];
            }

            return ['unit_id' => $unit->id, 'deposit_amount' => $row['deposit_amount'] ?? null, 'start_date' => $row['unit_start_date'] ?? null,
                'end_date' => $row['unit_end_date'] ?? null, 'charges' => $charges];
        }, $rows);

        $agreement = $this->agreements->handle($actor, null, [
            ...array_intersect_key($first, array_flip(['start_date', 'end_date', 'frequency', 'billing_day', 'grace_days', 'notice_period_days'])),
            'customer_id' => $customerId,
            'units' => $units,
        ]);
        $agreement->forceFill(['status' => AgreementStatus::PendingApproval, 'import_ref' => $ref])->save();

        Approval::create([
            'approvable_type' => $agreement->getMorphClass(),
            'approvable_id' => $agreement->id,
            'action' => ApprovalAction::AgreementActivation,
            'status' => ApprovalStatus::Approved,
            'requested_by' => $actor->id,
            'requested_at' => now(),
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'comment' => __('Imported by :name', ['name' => $actor->name]),
            'ip' => request()->ip(),
        ]);

        $this->activateAgreement->handle($agreement, $actor, $cutover);
    }
```
Use the imports `App\Actions\Agreements\{SaveAgreement, ActivateAgreement}`, `App\Enums\AgreementStatus`, `App\Models\{Agreement, Customer}`.

- If `Unit::default_tax_category` is not cast to an enum, use the raw value.
- If `SaveAgreement` refuses a start date in the past or a blocked unit, those are the real rules: keep them, and say so in the report.
- If `ActivateAgreement`'s caller has to set something else to keep the agreement-units status and occupancy rules happy (compare `AgreementActivation::approve`), mirror it here and name it in the report.

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Import tests/Feature/Agreements tests/Feature/Billing
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Import active agreements as at cutover

Rows sharing an import reference become one multi-unit agreement,
saved through the normal agreement rules, approved as imported, and
billed only from the first period on or after cutover. No deposit
invoice is raised: deposits held come from their own file.
EOF
```

---

### Task 5: Opening balances, with reconciliation totals

**Spec:** §11 (opening balance per customer at cutover, per agreement unit where applicable; opening balance per managed owner, `owner_charges` type `opening_balance`, the only source for what is owed to the owner; balances only), §4.6 (owner attribution on the cutover date), §7.9 (`opening_balance` lines count as rent on managed contracts), plan rulings 2, 3, 4, 7

**Files:**
- Modify: `app/Enums/ImportKind.php`, `app/Actions/Import/RunImport.php`, `app/Actions/Import/ImportResult.php`, `app/Livewire/Import/Index.php`, `resources/views/livewire/import/index.blade.php`, `tests/Feature/Import/ImportTest.php`

**Interfaces:**
- Consumes: `agreements.import_ref` (Task 4)
- Produces:
  - `ImportKind::CustomerBalances = 'customer_balances'` (after `Agreements`), `ImportKind::OwnerBalances = 'owner_balances'` (last)
  - `ImportKind::moneyColumn(): ?string`
  - `ImportResult::$totals` (`array<string, string>`: kind => BHD total of the passed rows' money column)

- [ ] **Step 1: Write the failing test**

```php
test('opening balances become issued opening invoices and an owner opening charge, with totals to reconcile', function () {
    Storage::fake('local');
    $files = [...agreementFiles($this->files),
        'customer_balances' => importFile(ImportKind::CustomerBalances, [
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'agreement_ref' => 'L-001', 'building_code' => 'MT', 'unit_code' => '101', 'amount' => '120.500'],
            ['customer_id_type' => 'cr', 'customer_id_number' => '12345-1', 'amount' => '80.000', 'description' => 'Old invoice 2025/77'],
        ]),
        'owner_balances' => importFile(ImportKind::OwnerBalances, [
            ['owner_id_type' => 'cpr', 'owner_id_number' => '080101234', 'building_code' => 'MT', 'amount' => '-25.000'],
        ]),
    ];

    $result = app(RunImport::class)->handle($this->vendor, $files, commit: true);

    expect($result->errors)->toBe([])
        ->and($result->totals)->toMatchArray(['customer_balances' => '200.500', 'owner_balances' => '-25.000']);

    $managed = OwnerContract::where('type', 'managed')->sole();
    $sara = Invoice::where('type', 'opening')->where('customer_id', Customer::where('id_number', '090202345')->value('id'))->sole();
    $line = $sara->lines->sole();
    expect([$sara->status->value, $sara->issue_date->toDateString(), $sara->due_date->toDateString(), $sara->total, $sara->number !== null])
        ->toBe(['issued', '2026-11-01', '2026-11-01', '120.500', true])
        ->and([$line->charge_type->value, $line->tax_amount, $line->owner_contract_id])->toBe(['opening_balance', '0.000', $managed->id])
        ->and(Invoice::where('type', 'opening')->whereNull('agreement_id')->value('total'))->toBe('80.000')
        ->and(OwnerCharge::where('owner_contract_id', $managed->id)->where('type', 'opening_balance')->value('amount'))->toBe('-25.000');
});

test('a customer credit, an unknown agreement unit or a second owner opening balance is refused', function () {
    $files = [...agreementFiles($this->files),
        'customer_balances' => importFile(ImportKind::CustomerBalances, [
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'amount' => '-10.000'],
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'agreement_ref' => 'L-001', 'building_code' => 'MT', 'unit_code' => '102', 'amount' => '5.000'],
        ]),
        'owner_balances' => importFile(ImportKind::OwnerBalances, [
            ['owner_id_type' => 'cpr', 'owner_id_number' => '080101234', 'building_code' => 'MT', 'amount' => '10.000'],
            ['owner_id_type' => 'cpr', 'owner_id_number' => '080101234', 'building_code' => 'MT', 'amount' => '10.000'],
        ]),
    ];

    $result = app(RunImport::class)->handle($this->vendor, $files, commit: false);

    expect($result->errors['customer_balances'][2][0])->toContain('enter it as a payment after go-live')
        ->and($result->errors['customer_balances'][3][0])->toContain('not on agreement L-001')
        ->and(array_keys($result->errors['owner_balances']))->toBe([3]);
});

test('the screen lists the totals of a dry run', function () {
    // The Livewire screen shows "Customer balances: 200.500 BHD" etc. from ImportResult::$totals.
    Livewire::actingAs($this->vendor)->test(Index::class)
        ->set('result', ['errors' => [], 'counts' => ['customer_balances' => 2], 'totals' => ['customer_balances' => '200.500'], 'committed' => false, 'commit' => false])
        ->assertSee('Customer balances total: 200.500 BHD');
});
```
Add the imports `App\Models\{Invoice, OwnerCharge}`. Also add `'totals' => []` to any existing `ImportResult` expectations, if a test compares the whole object.

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Import/ImportTest.php`
Expected: FAIL with `Undefined constant App\Enums\ImportKind::CustomerBalances`.

- [ ] **Step 3: Enum and result**

In `app/Enums/ImportKind.php`:
- add `case CustomerBalances = 'customer_balances';` after `Agreements`, and `case OwnerBalances = 'owner_balances';` as the last case;
- headers:
```php
            self::CustomerBalances => ['customer_id_type', 'customer_id_number', 'agreement_ref', 'building_code', 'unit_code', 'amount', 'description'],
            self::OwnerBalances => ['owner_id_type', 'owner_id_number', 'building_code', 'amount', 'notes'],
```
- text columns `self::CustomerBalances => ['customer_id_number', 'amount'],` and `self::OwnerBalances => ['owner_id_number', 'amount'],`;
- and:
```php
    /** The column summed into the import's reconciliation totals (spec §11: checked against the old system). */
    public function moneyColumn(): ?string
    {
        return match ($this) {
            self::CustomerBalances, self::OwnerBalances => 'amount',
            default => null,
        };
    }
```
(Task 6 adds its kinds to the `'amount'` arm.)

Replace `app/Actions/Import/ImportResult.php` with:
```php
<?php

namespace App\Actions\Import;

final readonly class ImportResult
{
    /**
     * @param  array<string, array<int, list<string>>>  $errors  kind => spreadsheet line => messages
     * @param  array<string, int>  $counts  rows that passed, per kind
     * @param  array<string, string>  $totals  kind => BHD total of the passed rows' money column
     */
    public function __construct(public array $errors, public array $counts, public bool $committed, public array $totals = []) {}
}
```
In `RunImport::handle`, keep `$totals = []` (kind => fils). After a unit of work passes, add the total when the kind has a money column, then return `new ImportResult($errors, $counts, $committed, array_map(Fils::toDecimal(...), $totals))`:
```php
                        if (($column = $kind->moneyColumn()) !== null) {
                            $totals[$kind->value] = ($totals[$kind->value] ?? 0) + array_sum(array_map(fn (array $r) => Fils::fromDecimal((string) $this->normalise($kind, $r)[$column]), $group));
                        }
```
In `Livewire\Import\Index::run`, add `'totals' => $result->totals` to `$this->result`. In the view, after the counts list:
```blade
        @if (! empty($result['totals']))
            <ul class="text-sm">
                @foreach ($result['totals'] as $kind => $total)
                    <li>{{ __(':kind total: :total BHD', ['kind' => str($kind)->headline(), 'total' => $total]) }}</li>
                @endforeach
            </ul>
        @endif
```

- [ ] **Step 4: Customer and owner balances**

In `RunImport`, inject `private IssueInvoice $issue` and add these arms:
- `ImportKind::CustomerBalances => $this->customerBalance($actor, $rows[0], $cutover),`
- `ImportKind::OwnerBalances => $this->ownerBalance($actor, $rows[0], $cutover),`

and the two methods:
```php
    /** Plan rulings 3–4: what the customer owed at cutover, as one issued opening invoice. @param  array<string, mixed>  $row */
    private function customerBalance(User $actor, array $row, CarbonImmutable $cutover): void
    {
        if (str_starts_with((string) ($row['amount'] ?? ''), '-')) {
            throw ValidationException::withMessages(['amount' => __('A credit is not imported: enter it as a payment after go-live, reference "Opening credit".')]);
        }
        $v = Validator::make($row, [
            'amount' => ['required', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'description' => ['nullable', 'string', 'max:255'],
        ])->validate();
        $amount = Fils::toDecimal(Fils::fromDecimal((string) $v['amount']));

        $customer = $this->customerByRow($row);
        [$agreement, $au] = $this->agreementUnitByRow($row, $customer, unitRequired: false);

        $invoice = (new Invoice)->forceFill([
            'type' => InvoiceType::Opening,
            'customer_id' => $customer->id,
            'agreement_id' => $agreement?->id,
            'issue_date' => $cutover->toDateString(),
            'due_date' => $cutover->toDateString(),
            'status' => InvoiceStatus::Draft,
            'subtotal' => $amount,
            'tax_total' => '0.000',
            'total' => $amount,
            'created_by' => $actor->id,
        ]);
        $invoice->save();
        $invoice->lines()->create([
            'agreement_unit_id' => $au?->id,
            'unit_id' => $au?->unit_id,
            'charge_type' => 'opening_balance',
            'description' => $v['description'] ?? __('Balance brought forward at :d', ['d' => $cutover->format('d/m/Y')]),
            'net' => $amount,
            'tax_category' => TaxCategory::OutOfScope->value, // the old system's figure already includes any VAT billed
            'tax_rate' => '0.00',
            'tax_amount' => '0.000',
            'total' => $amount,
        ]);

        if (! $this->issue->handle($invoice, $actor, autoAllocate: false)) {
            throw ValidationException::withMessages(['unit_code' => __('This balance is held back by a pending owner contract; approve or import that contract first.')]);
        }
    }

    /** Plan ruling 7. @param  array<string, mixed>  $row */
    private function ownerBalance(User $actor, array $row, CarbonImmutable $cutover): void
    {
        if (! preg_match('/^-?\d{1,9}(\.\d{1,3})?$/', (string) ($row['amount'] ?? '')) || Fils::fromDecimal(ltrim((string) $row['amount'], '-')) === 0) {
            throw ValidationException::withMessages(['amount' => __('Enter the balance in BHD (negative when the owner owes the company), not zero.')]);
        }
        $amount = (str_starts_with((string) $row['amount'], '-') ? -1 : 1) * Fils::fromDecimal(ltrim((string) $row['amount'], '-'));

        $ownerId = Owner::query()->where('id_type', $row['owner_id_type'] ?? '')->where('id_number', $row['owner_id_number'] ?? '')->value('id')
            ?? throw ValidationException::withMessages(['owner_id_number' => __('No owner with ID :type :number.', ['type' => $row['owner_id_type'] ?? '', 'number' => $row['owner_id_number'] ?? ''])]);
        $contract = OwnerContract::query()->effectiveOn($cutover)->where('owner_id', $ownerId)->where('building_id', $this->buildingId($row['building_code'] ?? null))
            ->where('type', OwnerContractType::Managed)->first()
            ?? throw ValidationException::withMessages(['building_code' => __('This owner has no managed contract on :b at cutover.', ['b' => (string) ($row['building_code'] ?? '')])]);
        if ($contract->charges()->where('type', OwnerChargeType::OpeningBalance)->exists()) {
            throw ValidationException::withMessages(['owner_id_number' => __('Contract :c already has an opening balance.', ['c' => $contract->number])]);
        }

        OwnerCharge::create([
            'owner_contract_id' => $contract->id,
            'type' => OwnerChargeType::OpeningBalance,
            'net' => Fils::toDecimal($amount),
            'tax_amount' => '0.000',
            'amount' => Fils::toDecimal($amount),
            'posted_at' => now(),
            'created_by' => $actor->id,
        ]);
    }

    /** @param  array<string, mixed>  $row */
    private function customerByRow(array $row): Customer
    {
        return Customer::query()->where('id_type', $row['customer_id_type'] ?? '')->where('id_number', $row['customer_id_number'] ?? '')->first()
            ?? throw ValidationException::withMessages(['customer_id_number' => __('No customer with ID :type :number.', ['type' => $row['customer_id_type'] ?? '', 'number' => $row['customer_id_number'] ?? ''])]);
    }

    /**
     * The imported agreement named by agreement_ref (the customer's), and its unit when building_code/unit_code are given.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: Agreement|null, 1: AgreementUnit|null}
     */
    private function agreementUnitByRow(array $row, Customer $customer, bool $unitRequired): array
    {
        $ref = (string) ($row['agreement_ref'] ?? '');
        if ($ref === '') {
            return $unitRequired
                ? throw ValidationException::withMessages(['agreement_ref' => __('Name the agreement (agreement_ref).')])
                : [null, null];
        }
        $agreement = Agreement::query()->where('import_ref', $ref)->where('customer_id', $customer->id)->first()
            ?? throw ValidationException::withMessages(['agreement_ref' => __('No imported agreement :ref for this customer.', ['ref' => $ref])]);
        if (blank($row['unit_code'] ?? null) && ! $unitRequired) {
            return [$agreement, null];
        }

        $au = $agreement->agreementUnits()->whereHas('unit', fn ($q) => $q->where('code', (string) ($row['unit_code'] ?? ''))->where('building_id', $this->buildingId($row['building_code'] ?? null)))->first()
            ?? throw ValidationException::withMessages(['unit_code' => __('Unit :u is not on agreement :ref.', ['u' => (string) ($row['unit_code'] ?? ''), 'ref' => $ref])]);

        return [$agreement, $au];
    }
```
Imports needed:
- `App\Actions\Billing\IssueInvoice`
- `App\Enums\{InvoiceStatus, InvoiceType, OwnerChargeType, OwnerContractType, TaxCategory}`
- `App\Models\{AgreementUnit, Invoice, OwnerCharge, OwnerContract}`
- `App\Support\Fils`
- `Illuminate\Support\Facades\Validator`

Checks before running:
- **Number sequence:** check that `IssueInvoice` numbers `opening` invoices (it numbers every non-credit type through the invoice sequence). If it does not, make it.
- **Opening-type rules:** check the invoices CHECKs and triggers for anything specific to `opening`, and report what you find.
- **Attribution:** `IssueInvoice` stamps the line's owner contract from `OwnerContract::effectiveOn(<due date>)`. That is the cutover date, as §11 requires.

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Import tests/Feature/Invoices tests/Feature/Owners
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Import opening balances, with totals to reconcile

What each customer owed at cutover becomes one issued opening invoice,
attributed to the unit's owner contract when it names a unit; credits
are refused. Each managed owner gets one opening-balance charge. The
import screen shows each money file's total to check against the old
system.
EOF
```

---

### Task 6: Deposits held and post-dated cheques

**Spec:**
- §11: "Deposits held are imported as `opening` deposit movements. They do not post to the owner ledger". Post-dated cheques still held are imported too.
- §7.6: `opening` uses the §4.6 rule on the cutover date.
- §7.4: cheques are recorded `held`.
- Plan ruling 2.

**Files:**
- Modify: `app/Enums/ImportKind.php`, `app/Actions/Import/RunImport.php`, `tests/Feature/Import/ImportTest.php`

**Interfaces:**
- Consumes: `agreementUnitByRow()`, `customerByRow()`, `moneyColumn()` (Task 5)
- Produces: `ImportKind::DepositsHeld = 'deposits_held'` and `ImportKind::Cheques = 'cheques'` (after `CustomerBalances`, before `OwnerBalances`)

- [ ] **Step 1: Write the failing test**

```php
test('deposits held become opening movements stamped on the cutover date; held cheques are recorded held', function () {
    Storage::fake('local');
    $files = [...agreementFiles($this->files),
        'deposits_held' => importFile(ImportKind::DepositsHeld, [
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'agreement_ref' => 'L-001', 'building_code' => 'MT', 'unit_code' => '101', 'amount' => '450.000'],
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'agreement_ref' => 'L-001', 'building_code' => 'MT', 'unit_code' => '103', 'amount' => '500.000'],
        ]),
        'cheques' => importFile(ImportKind::Cheques, [
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'agreement_ref' => 'L-001', 'cheque_no' => '000123', 'bank_name' => 'NBB', 'cheque_date' => '01/11/2026', 'amount' => '450.000'],
        ]),
    ];

    $result = app(RunImport::class)->handle($this->vendor, $files, commit: true);

    expect($result->errors)->toBe([])->and($result->totals)->toMatchArray(['deposits_held' => '950.000', 'cheques' => '450.000']);
    $agreement = Agreement::where('import_ref', 'L-001')->sole();
    $byUnit = DepositMovement::where('type', 'opening')->get()->keyBy(fn ($m) => $m->agreementUnit->unit->code);
    expect([$byUnit['101']->amount, $byUnit['101']->owner_contract_id])->toBe(['450.000', OwnerContract::where('type', 'managed')->value('id')])
        ->and($byUnit['103']->owner_contract_id)->toBeNull(); // 103 is owned: no contract covers it

    $cheque = Cheque::sole();
    expect([$cheque->status->value, $cheque->direction->value, $cheque->cheque_no, $cheque->cheque_date->toDateString(), $cheque->agreement_id])
        ->toBe(['held', 'received', '000123', '2026-11-01', $agreement->id]);
});

test('a second deposit for a unit, or a cheque number typed as a number, is refused', function () {
    $files = [...agreementFiles($this->files),
        'deposits_held' => importFile(ImportKind::DepositsHeld, [
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'agreement_ref' => 'L-001', 'building_code' => 'MT', 'unit_code' => '101', 'amount' => '450.000'],
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'agreement_ref' => 'L-001', 'building_code' => 'MT', 'unit_code' => '101', 'amount' => '10.000'],
        ]),
        'cheques' => importFile(ImportKind::Cheques, [
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'cheque_no' => 123, 'bank_name' => 'NBB', 'cheque_date' => '2026-11-01', 'amount' => '450.000'],
        ]),
    ];

    $result = app(RunImport::class)->handle($this->vendor, $files, commit: false);

    expect(array_keys($result->errors['deposits_held']))->toBe([3])
        ->and($result->errors['cheques'][2][0])->toContain('formatted as Text');
});
```
Add the imports `App\Models\{Cheque, DepositMovement}`.

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Import/ImportTest.php`
Expected: FAIL with `Undefined constant App\Enums\ImportKind::DepositsHeld`.

- [ ] **Step 3: Implement**

In `app/Enums/ImportKind.php`:
- add `case DepositsHeld = 'deposits_held';` and `case Cheques = 'cheques';` after `CustomerBalances`, before `OwnerBalances`;
- headers:
```php
            self::DepositsHeld => ['customer_id_type', 'customer_id_number', 'agreement_ref', 'building_code', 'unit_code', 'amount'],
            self::Cheques => ['customer_id_type', 'customer_id_number', 'agreement_ref', 'cheque_no', 'bank_name', 'account_holder', 'cheque_date', 'amount', 'notes'],
```
- text columns `self::DepositsHeld => ['customer_id_number', 'amount'],` and `self::Cheques => ['customer_id_number', 'cheque_no', 'amount'],`;
- add both to the `'amount'` arm of `moneyColumn()`.

In `RunImport`, add these arms:
- `ImportKind::DepositsHeld => $this->depositHeld($actor, $rows[0], $cutover),`
- `ImportKind::Cheques => $this->cheque($actor, $rows[0]),`

and the two methods:
```php
    /** Spec §11, §7.6: the deposit each agreement unit holds at cutover. @param  array<string, mixed>  $row */
    private function depositHeld(User $actor, array $row, CarbonImmutable $cutover): void
    {
        $v = Validator::make($row, ['amount' => ['required', Fils::rule(), 'not_regex:/^0+(\.0+)?$/']])->validate();
        [$agreement, $au] = $this->agreementUnitByRow($row, $this->customerByRow($row), unitRequired: true);
        if (DepositMovement::query()->where('agreement_unit_id', $au->id)->exists()) {
            throw ValidationException::withMessages(['unit_code' => __('Unit :u on :ref already has its deposit.', ['u' => (string) $row['unit_code'], 'ref' => (string) $row['agreement_ref']])]);
        }

        DepositMovement::create([
            'agreement_unit_id' => $au->id,
            // Spec §7.6: an opening movement is stamped by the §4.6 rule on the cutover date, and never recomputed.
            'owner_contract_id' => OwnerContract::query()->effectiveOn($cutover)->whereHas('units', fn ($q) => $q->whereKey($au->unit_id))->value('owner_contracts.id'),
            'type' => DepositMovementType::Opening,
            'amount' => Fils::toDecimal(Fils::fromDecimal((string) $v['amount'])),
            'source_type' => 'import',
            'source_id' => $agreement->id,
            'posted_at' => now(),
        ]);
    }

    /** Spec §7.4: a post-dated cheque still held at cutover (plan ruling 2: RecordCheques' rules, the import's authority). @param  array<string, mixed>  $row */
    private function cheque(User $actor, array $row): void
    {
        $v = Validator::make($row, [
            'cheque_no' => ['required', 'string', 'max:30'],
            'bank_name' => ['required', 'string', 'max:100'],
            'account_holder' => ['nullable', 'string', 'max:150'],
            'cheque_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $customer = $this->customerByRow($row);
        [$agreement] = $this->agreementUnitByRow($row, $customer, unitRequired: false);

        (new Cheque)->forceFill([
            'direction' => ChequeDirection::Received,
            'customer_id' => $customer->id,
            'agreement_id' => $agreement?->id,
            'cheque_no' => $v['cheque_no'],
            'bank_name' => $v['bank_name'],
            'account_holder' => $v['account_holder'] ?? null,
            'cheque_date' => $v['cheque_date'],
            'amount' => Fils::toDecimal(Fils::fromDecimal((string) $v['amount'])),
            'notes' => $v['notes'] ?? null,
            'status' => ChequeStatus::Held,
            'created_by' => $actor->id,
        ])->save();
    }
```
Imports needed: `App\Enums\{ChequeDirection, ChequeStatus, DepositMovementType}` and `App\Models\{Cheque, DepositMovement}`.

Before running, check `deposit_movements` and `cheques` for any CHECK or trigger rule on `source_type`, or on the columns written here. If one refuses `'import'`, extend that CHECK in a new migration; triggers and `EXPECTED_TRIGGERS` stay unchanged. Say what you found in the report.

- [ ] **Step 4: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Import tests/Feature/Cheques tests/Feature/Deposits
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Import deposits held and post-dated cheques

Each agreement unit's deposit at cutover becomes an opening movement,
attributed to the unit's owner contract on the cutover date and kept
off the owner ledger. Cheques still held are recorded held against
their customer and agreement. Both files add to the import's totals.
EOF
```

---

### Task 7: Building-scope tests and the go-live runbook

**Spec:**
- §8.2: building assignment limits what a user sees; a custom role can hold `finance.view` without `buildings.view-all`.
- §15 M5 row: UAT, security review, training, cutover.
- §13: operations.
- M4 carry-in: "out-of-scope / 403 tests for the M4 routes".

**Files:**
- Create: `tests/Feature/Permissions/OwnerFinanceScopeTest.php`, `docs/go-live-runbook.md`

**Interfaces:**
- Consumes: the M4 routes and Actions; `RunImport` (Tasks 2–6)

- [ ] **Step 1: Write the test**

Create `tests/Feature/Permissions/OwnerFinanceScopeTest.php`:
```php
<?php

use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\EnsureNumberSequences;
use App\Livewire\OwnerPayables\Index as PayablesIndex;
use App\Livewire\Reports\BuildingProfitabilityReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\OwnerPayable;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

// Spec §8.2: a role without buildings.view-all sees only its assigned buildings, on every M4 page and Action.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-04-02 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);

    [$this->mine, $this->theirs] = Building::factory()->count(2)->create()->all();
    $role = Role::create(['name' => 'Building accountant', 'guard_name' => 'web']);
    $role->givePermissionTo(['finance.view', 'disbursements.manage', 'reports.financial']);
    $this->user = User::factory()->withTwoFactor()->create()->assignRole($role);
    $this->user->buildings()->attach($this->mine->id);

    $managed = activeOwnerContract(['building_id' => $this->theirs->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'fixed', 'fee_value' => '10.000'], [Unit::factory()->for($this->theirs)->create()]);
    $this->statement = (new OwnerStatement)->forceFill(['owner_contract_id' => $managed->id, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'cutoff_at' => '2026-03-31 23:59:59', 'closing_balance' => '100.000', 'status' => 'draft', 'created_by' => $managed->created_by]);
    $this->statement->save();
    DB::table('owner_statements')->where('id', $this->statement->id)->update(['status' => 'pending_approval']);
    DB::table('owner_statements')->where('id', $this->statement->id)->update(['status' => 'finalised', 'number' => 'OS-T-1', 'finalised_at' => now(), 'finalised_by' => $managed->created_by]);

    $leased = activeOwnerContract(['building_id' => $this->theirs->id, 'type' => 'leased', 'rent_amount' => '50.000', 'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null, 'start_date' => '2026-04-01', 'end_date' => '2027-03-31'], [Unit::factory()->for($this->theirs)->create()]);
    $this->payable = (new OwnerPayable)->forceFill(['owner_contract_id' => $leased->id, 'period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'due_date' => '2026-04-01', 'amount' => '50.000', 'status' => 'scheduled']);
    $this->payable->save();
});

test('another building\'s statement pages are refused', function (string $route) {
    $this->actingAs($this->user)->get(route($route, $this->statement))->assertForbidden();
})->with(['owner-statements.show', 'owner-statements.pdf', 'owner-statements.export']);

test('another building\'s payables, statements and figures are not listed', function () {
    $this->actingAs($this->user)->get(route('owner-statements.index'))->assertOk()->assertDontSee('OS-T-1');
    Livewire::actingAs($this->user)->test(PayablesIndex::class)->assertDontSee('50.000');
    Livewire::actingAs($this->user)->test(BuildingProfitabilityReport::class)->assertSee($this->mine->code)->assertDontSee($this->theirs->code);
});

test('paying another building\'s owner is refused', function (array $data) {
    expect(fn () => app(RecordDisbursement::class)->handle($this->user, [...$data, 'method' => 'cash', 'paid_on' => '2026-04-02']))
        ->toThrow(AuthorizationException::class);
})->with([
    'remittance' => [fn () => ['purpose' => 'owner_remittance', 'owner_statement_id' => $this->statement->id, 'amount' => '1.000']],
    'head lease' => [fn () => ['purpose' => 'head_lease', 'owner_payable_id' => $this->payable->id, 'amount' => '50.000']],
]);
```
- **If the test fails:** the failure is a real scope gap. Fix the policy or the query it names, not the test.
- **Dataset closures:** check how Pest 5 resolves closures in datasets that use `$this`. If they are not bound, build the arrays inside the test from a string key instead.

- [ ] **Step 2: Run it**

Run: `"$PHP" artisan test tests/Feature/Permissions/OwnerFinanceScopeTest.php`
Expected: PASS. The M4 code already scopes these, so these tests lock that in. If any fails, fix the scope gap it reveals and say which in the report.

- [ ] **Step 3: Write the runbook**

Create `docs/go-live-runbook.md`:
```markdown
# Go-live runbook

One install per company (spec D2). Times are Asia/Bahrain. Vendor Support runs the import; Finance and Management sign off the figures.

## Before cutover (T−14 to T−1)

1. **Templates.** The client fills every template from the import screen (Data import → Download template). ID, phone, IBAN, cheque-number and money columns must be **Text** in Excel; numbers there are refused.
2. **Staging rehearsal.** Restore last night's production backup to staging, set a go-live date, and run a dry run with the full file set. Repeat until it reports no problems.
3. **Agree the cutover date** with the client. Everything is as at the start of that day:
   - scheduled rent and head-lease payments start with the first period that begins on or after it;
   - the period containing it was billed in the old system, and its unpaid part is in the opening balance.
4. **Freeze the old system's figures** at the close of the day before cutover. Export from it:
   - each customer's balance (per agreement unit where the client tracks it);
   - each deposit held;
   - each cheque still held;
   - each managed owner's balance.
   Keep the totals: they are checked against the import's totals.
5. **Security review** (sign each line):
   - `APP_DEBUG=false`, `APP_ENV=production`, HTTPS only, HSTS on.
   - Every user with Finance, Management, Admin or Vendor Support has two-factor on (enforced at sign-in).
   - The app connects as `rms_app` (no DDL, no DROP); migrations ran as `rms_migrate`; `php artisan rms:integrity-check` reports nothing.
   - `composer audit` reports nothing unresolved; the server packages are current.
   - Last night's backup restored on staging with its documents (`php artisan backup:list`; the restore check passed).
   - Forge heartbeats exist for every scheduled job and alert the vendor.
   - Roles reviewed with the client: who is Finance, Management, Leasing, Property Manager; building assignments for Leasing.
6. **Training** (one session per role, on staging with the rehearsal data):
   - Leasing: customers, agreements, renewals, move-outs.
   - Finance: payments, cheques, payments out, credit notes, settlements, owner statements, remittances.
   - Management: the approvals screen and the reports.

## Cutover day

1. `php artisan rms:setting go_live_at <cutover date>`. Set it **the day before** cutover: imports close once that day starts.
2. Upload the full file set and run a **dry run**. Fix and repeat until it is clean.
3. Compare the dry run's totals with the old system's totals from step 4 above:
   - customer balances;
   - deposits held;
   - cheques;
   - owner balances.
   They must match to the fil. Investigate any difference before importing.
4. **Import.** It runs in one transaction; nothing is saved unless every row of every file passes.
5. Spot-check with the client:
   - three customer statements;
   - one multi-unit agreement's schedule;
   - one managed owner's ledger;
   - one leased contract's head-lease schedule.

## First week

- Day 1, 01:00: invoices due are issued; check the run's log and heartbeat.
- Day 1, 02:30: the integrity check reports nothing.
- **UAT sign-off.** The client walks through the spec §14 flows on production data, as listed in the UAT checklist below, and signs.
- 1st of the next month, 04:00: the first owner statements are drafted. Finance reviews them before submitting.

## UAT checklist (spec §14)

- [ ] Agreement approval → schedule and deposit invoice (flow 1)
- [ ] Payment allocation, partial and full; receipt PDF (flow 2)
- [ ] Credit note on a partly paid line (flow 9)
- [ ] Cheque held → deposited → cleared; a bounce and its replacement (flow 5)
- [ ] Renewal and move-out with a deposit settlement (flow 8)
- [ ] Managed owner statement finalised and remitted (flows 6, 14)
- [ ] Head-lease payment paid once (flows 7, 14)
- [ ] A role without a building cannot see it (flow 11)
```

- [ ] **Step 4: Run the whole suite and the static checks**

```bash
"$PHP" artisan test
"$PHP" vendor/bin/pint --test
"$PHP" vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: every test passes; Pint and Larastan report nothing (fix what they report).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Lock in building scope for owner finance; add the go-live runbook

A role without all-buildings access is refused another building's
statements and owner payments and doesn't see them listed. The runbook
covers the rehearsal, security review, training, cutover-day
reconciliation and the UAT checklist.
EOF
```

---

## Not in M5a (M5b: reports, dashboard, scheduled emails)

| Item | Where |
|---|---|
| Operational reports (§10): availability and occupancy, expiring agreements, overstays, pending approvals, ID documents expiring, cheques to deposit, bounced cheques awaiting action, **held cheques to return** | M5b |
| Financial reports (§10): outstanding by customer, overdue ageing, collections by date and method, deposits held, VAT summary (including VAT on management fees) | M5b |
| Management dashboard, 8 tiles (§10) | M5b |
| Scheduled emails at 07:00 daily and Monday 07:00 (§12), sent from the System user's jobs, filtered by building scope | M5b |
| M3b carry-overs: "cheques to return" also lists unmatched cheques; a deposit settlement doesn't credit a scheduled (unissued) deposit invoice | M5b |
| Customer credits at cutover (plan ruling 3) | entered as payments after go-live |
| UAT sign-off, the security review, training and the cutover itself | the runbook, run with the client |
