# M3b Money Out and the Agreement Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pay money back out — credit refunds, deposit refunds and approved one-off payments, by transfer, cash or a cheque the company writes, reversible with approval — and run an agreement's whole life after activation: add or release units and terminate early with exact re-billing, record move-outs, settle deposits, renew with the deposit carried over, and close agreements at 02:00.

**Architecture:** Builds on M3a (branch `m3-tenant-finance`, head 21a3d43). New tables ship with their triggers. Two internal engines carry the lifecycle: `RebillAgreement` (cancel-and-replace of scheduled invoices plus a credit note or manual invoice for issued ones, spec §5.7/§6.3) and `CloseAgreements` (the closing check of §5.4, run on each move-out and at 02:00). Payments out go through one Action, `RecordDisbursement`, with an approval path only when there is no source.

**Tech Stack:** as M3a. No new packages.

**Spec:** `docs/superpowers/specs/2026-09-28-rental-management-v1-design.md` — §15 M3 row (second half); §5.4, §5.7, §5.8, §5.9, §6.3 (cancel and replace, cheque re-pointing), §7.3 (payment-out reversal), §7.4 (issued cheques), §7.5 (customer-side purposes), §7.6, §7.7, §8.3 items 2, 3, 4, 6, 9, §8.5, §12 (02:00), §14 flows 8, 10, 12, 14.

## Global Constraints

Everything in the M0–M3a Global Constraints still applies:
- Every monetary column `DECIMAL(12,3)`; PHP money maths in **integer fils** (`App\Support\Fils`); half-up to the fil (§2).
- Actions are the only write path; each top-level Action runs in `DB::transaction(…, attempts: 3)` and re-fetches its rows with `lockForUpdate()` inside the closure; internal Actions assert `DB::transactionLevel() > 0`.
- **Lock order (§7.2), always:** `customers` → `cheques` → invoice lines (ascending id) → invoices (ascending id) → `payments` → `disbursements`. Agreement-side locks (`units`, `agreements`, `agreement_units`, `deposit_settlements`, `agreement_amendments`) come after the customer and before any invoice line. Never lock a customer after any of these.
- Two DB users; migrations via `--database=migrator`; one statement per `DB::unprepared()`; triggers `SIGNAL SQLSTATE '45000'`, messages ≤ 128 characters; heredoc tags must not be `SQL` when the body contains `SQL SECURITY` (use `DDL`).
- Policies on every route and Livewire action; building scope via `visibleTo()`; every unit an amendment or move-out touches must be in the actor's scope (§8.2); Livewire ids `#[Locked]`.
- Permissions (§8.1): `disbursements.manage` for payments out; `cheques.manage` for cheques; `agreements.manage` for amendments, move-outs and renewals; Finance (`invoices.manage`) edits deposit-settlement deductions; Management (`approvals.decide`) approves amendments (items 2–3), deposit settlements (4), payment-out reversals (6) and source-less payments out (9).
- Mail and jobs only via `DB::afterCommit`.
- English UI usable at 375 px; free Flux only; numbers in PDFs right-aligned with `table.lines td.num, table.lines th.num, .num { text-align: right; }`.
- Tests: Pest, MySQL as `rms_app`, run serially (`--parallel` cannot create databases as `rms_app`). Finance/Management/PM users need `->withTwoFactor()` for HTTP tests. Any test that approves an agreement or records a payment fakes the local disk (`Storage::fake('local')`).
- Every migration that adds or drops a trigger updates `App\Integrity\IntegrityCheck::EXPECTED_TRIGGERS` in the same commit; `IntegrityCheckTest` enforces it.

## Rulings made while planning

1. **Payments out with a source never need approval and never exceed their source.** A credit refund above the payment's unallocated credit would leave customer credit below zero (§7.2 forbids it) and a deposit refund above the settlement's refund would leave the deposit held below zero, so both are refused, not routed to approval. The approval path (§8.3 item 9) is for payments out **without** a source (`purpose = other`); owner remittances and head-lease payments, whose limits can be exceeded with approval, arrive in M4.
2. **A rejected payment-out request becomes `rejected`** (a terminal status the spec's list lacks; there is no draft to return to).
3. **A plain reversal of a cheque payment is refused** (M3a review): it must go through *Bounced* on the cheque, so the cheque and the payment never disagree.
4. **Partial credits on amendments:** the credit line's net = billed net − the §6.2 amount for the kept days; its gross = that net + its tax at the line's rate (capped at what is left to credit); `CreditNoteSplit` then splits it as for any credit note. A credit of the whole line takes exactly its remaining tax.
5. **`invoice_lines.agreement_unit_charge_id`** is added and stamped by the schedule, so re-billing matches each line to its charge without parsing descriptions.
6. **Inserting units into a non-draft agreement** is allowed by the triggers only for the rows an approved, not-yet-applied `add_unit` amendment creates (`agreement_units.amendment_id`, `agreement_amendments.applied_at`).
7. **Deposit settlements** are created one per occupancy-end event (all units ending together share one); the deductions invoice is due on the unit's last day (move-out date, or end date if later), which is also its §4.6 attribution date.
8. **An `applied` or `refunded` movement** copies `owner_contract_id` from the unit's most recent positive (`received`, `opening` or `transfer_in`) movement (§7.6).
9. **Renewal deposit transfer** happens in `ActivateAgreement` for an agreement with `previous_agreement_id`; the credit note for an unpaid old deposit balance and the leftover settlement are created in the same transaction.

## Working environment

```bash
export PHP="/c/Users/ababy/.config/herd/bin/php85/php.exe"
cd /c/Users/ababy/Documents/RentalManagementSystem
# MySQL stops between sessions: if "connection refused", run C:\Users\ababy\.mysql\start-mysql.cmd
```
Branch: `m3b-lifecycle-money-out` (from `m3-tenant-finance`).

## Conventions carried over (do not re-create)

M3a: `Payment` (`unallocatedFils()`, `allocations()`, `cheque()`), `PaymentAllocation` (`liveFils()`), `DepositMovement`, `CustomerCredit::{fils, openPayments}`, `AllocationPlan::{oldestFirst, explicit}`, `ApplyToInvoices::handle(Payment, array $plan, User)`, `ReverseAllocations::handle(array $cuts, User)`, `PostPayment::handle(Customer $locked, array $attrs, array $plan, User)`, `IssueInvoice::handle(Invoice, ?User, bool $autoAllocate = true)`, `IssueCreditNote::handle(Invoice $pendingCn, User)`, `SaveCreditNote`, `CreditNoteSplit::of(int, InvoiceLine)`, `AllocationTax::between`, `Cheque` (+ `ChequeStatus`, `ChequeDirection`, `visibleTo`), `ChequePolicy`, `RequestPaymentReversal`, `App\Approvals\PaymentReversal`, `CustomerStatement`, `IntegrityCheck` (`EXPECTED_TRIGGERS = 38`), `StoreReceipt`, `PdfRenderer`, test helpers `issuedInvoice()`, `activeAgreement()`, `activeOwnerContract()`, `matrixUser()`, `scheduledEvent()`, `Fils_from()` in `tests/Pest.php`.
M2: `Agreement` (`agreementUnits()`, `invoices()`, `previous()`, `label()`, `depositFils()`), `AgreementUnit` (`effectiveEndSql()`), `AgreementPolicy::allUnitsInScope`, `EnsureNoAgreementOverlap`, `SaveAgreement`, `SubmitAgreement`, `ActivateAgreement`, `GenerateRentSchedule`, `CreateDepositInvoice`, `ExpireAgreements` (+ `rms:agreements:expire`, 02:00), `BillingPeriods::for`, `BillingPeriod`, `Proration::line`, `Tax::amount(int $net, TaxCategory, bool $vatRegistered, string $rate)`.
Approvals: `RequestApproval::handle(User, Model, ApprovalAction, ?string $reason, array $payload)`, `DecideApproval::handle(User $approver, Approval, bool $approve, ?string $comment)`, `ApprovalHandler` (`creatorId`, `approve`, `reject`, `summary`, `url`). `NumberSequenceKey::{PaymentOut, DepositSettlement, CreditNote, Invoice, Receipt}` exist.

---

### Task 1: Payments out and credit refunds

**Spec:** §7.5 (disbursements; with an approved source recorded directly as paid within the limits; else pending_approval → Management → approved → paid; PO number when paid), §7.2 (customer credit = Σ confirmed payments − Σ live allocations − Σ non-reversed credit refunds; a credit refund names its payment; reject a result that leaves credit below zero), §7.10 (credit refunds are debits on the statement), §7.11 (credit ≥ 0), §8.3 item 9, §8.5 (no DELETE on disbursements; amount frozen; reversed is one-way)

**Files:**
- Create: `app/Enums/{DisbursementStatus,DisbursementPurpose,DisbursementMethod,PayeeType}.php`, `database/migrations/2026_10_20_000100_create_disbursements_table.php`, `app/Models/Disbursement.php`, `app/Policies/DisbursementPolicy.php`, `app/Actions/Disbursements/{RecordDisbursement,PayDisbursement,MarkDisbursementPaid}.php`, `app/Approvals/PaymentOut.php`, `tests/Feature/Disbursements/CreditRefundTest.php`, `tests/Feature/Disbursements/PaymentOutApprovalTest.php`, `tests/Feature/Disbursements/DisbursementTriggersTest.php`
- Modify: `app/Enums/ApprovalAction.php`, `app/Models/Payment.php`, `app/Billing/CustomerCredit.php`, `app/Billing/CustomerStatement.php`, `app/Integrity/IntegrityCheck.php`, `app/Approvals/PaymentReversal.php`

**Interfaces:**
- Produces:
  - Enums: `DisbursementStatus` (`PendingApproval`, `Approved`, `Rejected`, `Paid`, `Reversed`), `DisbursementPurpose` (`OwnerRemittance`, `HeadLease`, `DepositRefund`, `CreditRefund`, `Other`; `label()`), `DisbursementMethod` (`BankTransfer`, `Cheque`, `Cash`; `label()`), `PayeeType` (`Owner`, `Customer`)
  - `Disbursement` (`payee(): Customer|Owner`, `creator()`, `recorder()`, `cheque()`, `source(): ?Model`, `label()`), constants `Disbursement::SOURCE_PAYMENT = 'payment'`, `SOURCE_SETTLEMENT = 'deposit_settlement'`
  - `RecordDisbursement::handle(User $actor, array $data): Disbursement` — `$data`: `purpose`, `amount`, `method`, `reference?`, `notes?`, `paid_on?`; for `credit_refund`: `payment_id`; for `other`: `payee_type`, `payee_id`, `reason`; cheque fields `cheque_no`, `bank_name`, `cheque_date` when `method = cheque` (Task 2). Returns a `paid` row (with a source) or a `pending_approval` row (other).
  - `PayDisbursement::handle(User $actor, Disbursement $approved, array $data): Disbursement` — approved → paid; `$data`: `method`, `paid_on`, `reference?`, cheque fields
  - `MarkDisbursementPaid::handle(Disbursement $locked, array $data, User $actor): void` — internal; numbers it and writes the paid fields (Task 2 adds the issued cheque, Task 6 the refunded movements)
  - `Payment::creditRefundedFils(): int`
  - `ApprovalAction::PaymentOut` (`'payment_out.approve'`) → `App\Approvals\PaymentOut`
  - `DisbursementPolicy::{viewAny, view, create, reverse}`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Disbursements/CreditRefundTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RequestPaymentReversal;
use App\Billing\CustomerCredit;
use App\Billing\CustomerStatement;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->customer = Customer::factory()->create();
    issuedInvoice($this->customer, [['net' => '100.000']]);
    $this->payment = app(RecordPayment::class)->handle($this->finance, $this->customer,
        ['received_on' => '2026-10-05', 'method' => 'bank_transfer', 'amount' => '160.000']); // 100 allocated, 60 credit
    $this->refund = fn (string $amount, array $over = []) => app(RecordDisbursement::class)->handle($this->finance, [
        'purpose' => 'credit_refund', 'payment_id' => $this->payment->id, 'amount' => $amount, 'method' => 'bank_transfer',
        'reference' => 'TRX-OUT-1', 'paid_on' => '2026-10-05', ...$over,
    ]);
});

test('a credit refund within the payment\'s credit is paid at once, numbered, and reduces the credit', function () {
    $out = ($this->refund)('40.000');

    expect($out->status->value)->toBe('paid')
        ->and($out->number)->toBe('PO-2026-000001')
        ->and($out->payee_type->value)->toBe('customer')
        ->and($out->payee_id)->toBe($this->customer->id)
        ->and($out->source_type)->toBe('payment')
        ->and($out->source_id)->toBe($this->payment->id)
        ->and($out->recorded_by)->toBe($this->finance->id)
        ->and($this->payment->fresh()->unallocatedFils())->toBe(20_000)
        ->and(CustomerCredit::fils($this->customer->id))->toBe(20_000)
        ->and(app(IntegrityCheck::class)->run())->toBe([]);
});

test('a refund above the payment\'s credit is refused, never sent for approval', function () {
    ($this->refund)('50.000');

    expect(fn () => ($this->refund)('10.001'))->toThrow(ValidationException::class);
    expect(App\Models\Disbursement::count())->toBe(1);
});

test('the statement shows the refund as a debit and still matches the cached figures', function () {
    ($this->refund)('60.000');

    $s = CustomerStatement::receivables($this->customer, '2026-10-01', '2026-10-31');

    expect(array_column($s['rows'], 'kind'))->toBe(['Invoice', 'Payment', 'Credit refund'])
        ->and($s['closing'])->toBe(0)
        ->and(CustomerCredit::fils($this->customer->id))->toBe(0);
});

test('a refunded payment cannot be reversed while its refund stands', function () {
    ($this->refund)('60.000');
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $approval = app(RequestPaymentReversal::class)->handle($this->finance, $this->payment, 'Recalled');

    expect(fn () => app(DecideApproval::class)->handle($management, $approval, true))->toThrow(ValidationException::class);
    expect($this->payment->fresh()->status->value)->toBe('confirmed');
});

test('only disbursements.manage records payments out; amounts, dates and payments are validated', function () {
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    expect(fn () => app(RecordDisbursement::class)->handle($management, ['purpose' => 'credit_refund', 'payment_id' => $this->payment->id, 'amount' => '1', 'method' => 'cash', 'paid_on' => '2026-10-05']))
        ->toThrow(AuthorizationException::class);

    foreach ([['amount' => '0'], ['paid_on' => '2026-10-06'], ['method' => 'card'], ['payment_id' => 999999], ['purpose' => 'head_lease']] as $bad) {
        expect(fn () => ($this->refund)('1', $bad))->toThrow(ValidationException::class);
    }
});
```
Create `tests/Feature/Disbursements/PaymentOutApprovalTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\PayDisbursement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Models\Approval;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->other = fn (array $over = []) => app(RecordDisbursement::class)->handle($this->finance, [
        'purpose' => 'other', 'payee_type' => 'owner', 'payee_id' => Owner::factory()->create()->id, 'amount' => '75.000',
        'method' => 'bank_transfer', 'reason' => 'Owner share of a shared repair', ...$over,
    ]);
});

test('a payment out without a source waits for Management, then Finance pays it', function () {
    $out = ($this->other)();
    expect($out->status->value)->toBe('pending_approval')->and($out->number)->toBeNull();

    app(DecideApproval::class)->handle($this->management, Approval::sole(), true);
    expect($out->fresh()->status->value)->toBe('approved');

    app(PayDisbursement::class)->handle($this->finance, $out->fresh(), ['method' => 'cash', 'paid_on' => '2026-10-05', 'reference' => 'Voucher 12']);

    $paid = $out->fresh();
    expect($paid->status->value)->toBe('paid')
        ->and($paid->number)->toBe('PO-2026-000001')
        ->and($paid->method->value)->toBe('cash')
        ->and($paid->paid_on->toDateString())->toBe('2026-10-05');
});

test('rejecting leaves it rejected for good; unapproved rows cannot be paid', function () {
    $out = ($this->other)();
    expect(fn () => app(PayDisbursement::class)->handle($this->finance, $out, ['method' => 'cash', 'paid_on' => '2026-10-05']))->toThrow(ValidationException::class);

    app(DecideApproval::class)->handle($this->management, Approval::sole(), false, 'Not ours to pay');
    expect($out->fresh()->status->value)->toBe('rejected');
    expect(fn () => app(PayDisbursement::class)->handle($this->finance, $out->fresh(), ['method' => 'cash', 'paid_on' => '2026-10-05']))->toThrow(ValidationException::class);
});

test('the requester cannot approve their own payment out; a customer payee works too', function () {
    $dual = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance)->assignRole(RoleName::Management);
    app(RecordDisbursement::class)->handle($dual, ['purpose' => 'other', 'payee_type' => 'customer', 'payee_id' => Customer::factory()->create()->id, 'amount' => '5', 'method' => 'cash', 'reason' => 'Goodwill']);

    expect(fn () => app(DecideApproval::class)->handle($dual, Approval::sole(), true))->toThrow(ValidationException::class);
    expect(fn () => ($this->other)(['reason' => '']))->toThrow(ValidationException::class);
});
```
Create `tests/Feature/Disbursements/DisbursementTriggersTest.php`:
```php
<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->id = DB::table('disbursements')->insertGetId([
        'payee_type' => 'customer', 'payee_id' => Customer::factory()->create()->id, 'purpose' => 'other', 'amount' => '10.000',
        'method' => 'cash', 'status' => 'pending_approval', 'created_by' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->row = fn () => DB::table('disbursements')->where('id', $this->id);
});

test('payments out are never deleted, their amount never changes, and the status only moves forward', function () {
    expect(fn () => ($this->row)()->delete())->toThrow(QueryException::class, 'disbursements cannot be deleted');
    expect(fn () => ($this->row)()->update(['amount' => '11.000']))->toThrow(QueryException::class, 'disbursements: terms are frozen');
    expect(fn () => ($this->row)()->update(['status' => 'paid']))->toThrow(QueryException::class, 'disbursements: status change not allowed');

    ($this->row)()->update(['status' => 'approved']);
    ($this->row)()->update(['status' => 'paid', 'number' => 'PO-T-1', 'paid_on' => '2026-10-05', 'posted_at' => now(), 'recorded_by' => User::factory()->create()->id]);
    expect(fn () => ($this->row)()->update(['reference' => 'changed']))->toThrow(QueryException::class, 'disbursements: a paid payment out is frozen');
    expect(fn () => ($this->row)()->update(['status' => 'approved']))->toThrow(QueryException::class, 'disbursements: status change not allowed');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Disbursements`
Expected: FAIL — `Class "App\Actions\Disbursements\RecordDisbursement" not found`.

- [ ] **Step 3: Enums and migration**

Create `app/Enums/DisbursementStatus.php`:
```php
<?php

namespace App\Enums;

enum DisbursementStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected'; // plan ruling 2: a rejected request ends here
    case Paid = 'paid';
    case Reversed = 'reversed';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
```
Create `app/Enums/DisbursementPurpose.php`:
```php
<?php

namespace App\Enums;

enum DisbursementPurpose: string
{
    case OwnerRemittance = 'owner_remittance'; // M4
    case HeadLease = 'head_lease';             // M4
    case DepositRefund = 'deposit_refund';     // Task 6
    case CreditRefund = 'credit_refund';
    case Other = 'other';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
```
Create `app/Enums/DisbursementMethod.php`:
```php
<?php

namespace App\Enums;

enum DisbursementMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Cash = 'cash';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
```
Create `app/Enums/PayeeType.php`:
```php
<?php

namespace App\Enums;

enum PayeeType: string
{
    case Owner = 'owner';
    case Customer = 'customer';
}
```
Create `database/migrations/2026_10_20_000100_create_disbursements_table.php`:
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
        Schema::create('disbursements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->string('payee_type', 10);
            $table->unsignedBigInteger('payee_id');
            $table->string('purpose', 20);
            $table->decimal('amount', 12, 3);
            $table->string('method', 20);
            $table->unsignedBigInteger('cheque_id')->nullable(); // FK added with issued cheques (Task 2)
            $table->string('reference', 100)->nullable();
            $table->date('paid_on')->nullable();
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('status', 20);
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
            $table->index(['payee_type', 'payee_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE disbursements
                ADD CONSTRAINT disbursements_payee_chk CHECK (payee_type IN ('owner', 'customer')),
                ADD CONSTRAINT disbursements_purpose_chk CHECK (purpose IN ('owner_remittance', 'head_lease', 'deposit_refund', 'credit_refund', 'other')),
                ADD CONSTRAINT disbursements_method_chk CHECK (method IN ('bank_transfer', 'cheque', 'cash')),
                ADD CONSTRAINT disbursements_status_chk CHECK (status IN ('pending_approval', 'approved', 'rejected', 'paid', 'reversed')),
                ADD CONSTRAINT disbursements_amount_chk CHECK (amount > 0),
                ADD CONSTRAINT disbursements_source_chk CHECK ((source_type IS NULL) = (source_id IS NULL)
                    AND (source_type IS NULL OR source_type IN ('owner_statement', 'owner_payable', 'deposit_settlement', 'payment'))),
                ADD CONSTRAINT disbursements_paid_chk CHECK ((status IN ('paid', 'reversed')) = (number IS NOT NULL)
                    AND (status IN ('paid', 'reversed')) = (paid_on IS NOT NULL)
                    AND (status IN ('paid', 'reversed')) = (posted_at IS NOT NULL)
                    AND (status IN ('paid', 'reversed')) = (recorded_by IS NOT NULL)),
                ADD CONSTRAINT disbursements_reversed_chk CHECK ((status = 'reversed') = (reversed_at IS NOT NULL))
            SQL);

        DB::unprepared("CREATE TRIGGER disbursements_no_delete BEFORE DELETE ON disbursements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'disbursements cannot be deleted'");

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER disbursements_guard BEFORE UPDATE ON disbursements FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'pending_approval' AND NEW.status IN ('approved', 'rejected'))
                    OR (OLD.status = 'approved' AND NEW.status = 'paid')
                    OR (OLD.status = 'paid' AND NEW.status = 'reversed')) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'disbursements: status change not allowed';
                END IF;
                IF NOT (NEW.payee_type <=> OLD.payee_type AND NEW.payee_id <=> OLD.payee_id AND NEW.purpose <=> OLD.purpose
                    AND NEW.amount <=> OLD.amount AND NEW.source_type <=> OLD.source_type AND NEW.source_id <=> OLD.source_id
                    AND NEW.created_by <=> OLD.created_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'disbursements: terms are frozen';
                END IF;
                IF OLD.status IN ('paid', 'reversed', 'rejected') AND NOT (NEW.number <=> OLD.number AND NEW.method <=> OLD.method
                    AND NEW.cheque_id <=> OLD.cheque_id AND NEW.reference <=> OLD.reference AND NEW.paid_on <=> OLD.paid_on
                    AND NEW.posted_at <=> OLD.posted_at AND NEW.recorded_by <=> OLD.recorded_by AND NEW.reason <=> OLD.reason) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'disbursements: a paid payment out is frozen';
                END IF;
            END
            DDL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS disbursements_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS disbursements_no_delete');
        Schema::dropIfExists('disbursements');
    }
};
```
Raise `App\Integrity\IntegrityCheck::EXPECTED_TRIGGERS` from 38 to **40** and update its comment: `// 38 at the end of M3a + 2 (disbursements)`. Later tasks keep extending this comment.

- [ ] **Step 4: Model and policy**

Create `app/Models/Disbursement.php`:
```php
<?php

namespace App\Models;

use App\Enums\DisbursementMethod;
use App\Enums\DisbursementPurpose;
use App\Enums\DisbursementStatus;
use App\Enums\PayeeType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec §7.5. Written only by the disbursement Actions and approval handlers.
 *
 * @property int $id
 * @property string|null $number
 * @property PayeeType $payee_type
 * @property int $payee_id
 * @property DisbursementPurpose $purpose
 * @property string $amount
 * @property DisbursementMethod $method
 * @property int|null $cheque_id
 * @property string|null $reference
 * @property CarbonImmutable|null $paid_on
 * @property string|null $source_type
 * @property int|null $source_id
 * @property DisbursementStatus $status
 * @property string|null $reason
 * @property int $created_by
 * @property int|null $recorded_by
 * @property CarbonImmutable|null $reversed_at
 */
class Disbursement extends Model
{
    use LogsActivity;

    public const string SOURCE_PAYMENT = 'payment';

    public const string SOURCE_SETTLEMENT = 'deposit_settlement';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payee_type' => PayeeType::class,
            'purpose' => DisbursementPurpose::class,
            'method' => DisbursementMethod::class,
            'status' => DisbursementStatus::class,
            'amount' => 'decimal:3',
            'paid_on' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['number', 'status', 'amount', 'method', 'paid_on', 'reference', 'reversed_at'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    public function payee(): Customer|Owner
    {
        return $this->payee_type === PayeeType::Customer
            ? Customer::withTrashed()->findOrFail($this->payee_id)
            : Owner::withTrashed()->findOrFail($this->payee_id);
    }

    public function source(): ?Model
    {
        return match ($this->source_type) {
            self::SOURCE_PAYMENT => Payment::query()->find($this->source_id),
            self::SOURCE_SETTLEMENT => null, // Task 5 returns DepositSettlement::query()->find($this->source_id)
            default => null,
        };
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<Cheque, $this> */
    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
    }

    public function label(): string
    {
        return $this->number ?? __(':status #:id', ['status' => $this->status->label(), 'id' => $this->id]);
    }
}
```

Create `app/Policies/DisbursementPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\PayeeType;
use App\Models\Customer;
use App\Models\Disbursement;
use App\Models\User;

/** Spec §8.1: Finance records payments out; finance.view holders see them. */
class DisbursementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::FinanceView);
    }

    public function view(User $user, Disbursement $disbursement): bool
    {
        if (! $user->can(PermissionName::FinanceView)) {
            return false;
        }

        return $disbursement->payee_type !== PayeeType::Customer
            || Customer::visibleTo($user)->whereKey($disbursement->payee_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::DisbursementsManage);
    }

    public function reverse(User $user, Disbursement $disbursement): bool
    {
        return $user->can(PermissionName::DisbursementsManage) && $this->view($user, $disbursement);
    }
}
```

- [ ] **Step 5: Credit refunds count against credit**

In `app/Models/Payment.php` replace `unallocatedFils()` with:
```php
    /** The part of this payment not allocated and not refunded: customer credit (spec §7.2). */
    public function unallocatedFils(): int
    {
        if ($this->status === PaymentStatus::Reversed) {
            return 0;
        }

        return Fils::fromDecimal($this->amount)
            - Fils::fromDecimal((string) ($this->allocations()->sum('amount') ?: '0'))
            - $this->creditRefundedFils();
    }

    /** Σ non-reversed credit refunds of this payment (spec §7.2). */
    public function creditRefundedFils(): int
    {
        return Fils::fromDecimal((string) (Disbursement::query()
            ->where('source_type', Disbursement::SOURCE_PAYMENT)->where('source_id', $this->id)
            ->where('purpose', DisbursementPurpose::CreditRefund)
            ->whereIn('status', [DisbursementStatus::Paid])
            ->sum('amount') ?: '0'));
    }
```
(import `App\Enums\{DisbursementPurpose, DisbursementStatus}`.) Credit refunds are always created `paid` (ruling 1), so `paid` is the only status that counts; a reversed refund returns its amount to credit.

In `app/Billing/CustomerCredit.php` replace `fils()` and the class comment:
```php
/** Spec §7.2: customer credit = Σ confirmed payment amounts − Σ their live allocations − Σ non-reversed credit refunds. */
final class CustomerCredit
{
    public static function fils(int $customerId): int
    {
        $paid = (string) (Payment::query()->where('customer_id', $customerId)->where('status', PaymentStatus::Confirmed)->sum('amount') ?: '0');
        $allocated = (string) (DB::table('payment_allocations as pa')->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->where('p.customer_id', $customerId)->where('p.status', PaymentStatus::Confirmed->value)->sum('pa.amount') ?: '0');
        $refunded = (string) (DB::table('disbursements as d')->join('payments as p', 'p.id', '=', 'd.source_id')
            ->where('d.source_type', Disbursement::SOURCE_PAYMENT)->where('d.purpose', DisbursementPurpose::CreditRefund->value)
            ->where('d.status', DisbursementStatus::Paid->value)
            ->where('p.customer_id', $customerId)->where('p.status', PaymentStatus::Confirmed->value)->sum('d.amount') ?: '0');

        return Fils::fromDecimal($paid) - Fils::fromDecimal($allocated) - Fils::fromDecimal($refunded);
    }
```
(imports.) A refund of a payment that was later reversed is excluded with its payment; the reversal handler refuses that case anyway (below).

In `app/Integrity/IntegrityCheck.php` replace the customer-credit query with:
```php
        foreach (DB::select(<<<'SQL'
            SELECT p.customer_id, SUM(p.amount) - COALESCE(SUM(a.s), 0) - COALESCE(SUM(r.s), 0) AS credit
            FROM payments p
            LEFT JOIN (SELECT payment_id, SUM(amount) AS s FROM payment_allocations GROUP BY payment_id) a ON a.payment_id = p.id
            LEFT JOIN (SELECT source_id, SUM(amount) AS s FROM disbursements
                       WHERE source_type = 'payment' AND purpose = 'credit_refund' AND status = 'paid' GROUP BY source_id) r ON r.source_id = p.id
            WHERE p.status = 'confirmed'
            GROUP BY p.customer_id
            HAVING credit < 0
            SQL) as $row) {
            $failures[] = "customer {$row->customer_id}: credit is {$row->credit}";
        }
```
In `app/Billing/CustomerStatement.php`, in `entries()` add before the `return` (import `Disbursement`, `DisbursementPurpose`, `DisbursementStatus`):
```php
        // Spec §7.10: credit refunds are debits; a reversed refund shows both its payment and its reversal.
        $refunds = Disbursement::query()
            ->where('purpose', DisbursementPurpose::CreditRefund)->where('payee_type', 'customer')->where('payee_id', $customer->id)
            ->whereIn('status', [DisbursementStatus::Paid, DisbursementStatus::Reversed])->get();
        $refunded = $refunds->map(fn (Disbursement $d) => ['date' => $d->paid_on?->toDateString() ?? '', 'order' => 5, 'id' => $d->id, 'kind' => 'Credit refund', 'reference' => (string) $d->number, 'url' => null, 'debit' => Fils::fromDecimal($d->amount), 'credit' => 0]);
        $refundReversed = $refunds->flatMap(fn (Disbursement $d) => $d->reversed_at === null ? [] : [['date' => $d->reversed_at->timezone('Asia/Bahrain')->toDateString(), 'order' => 6, 'id' => $d->id, 'kind' => 'Credit refund reversal', 'reference' => (string) $d->number, 'url' => null, 'debit' => 0, 'credit' => Fils::fromDecimal($d->amount)]]);
```
and return `$invoices->concat($paid)->concat($reversed)->concat($refunded)->concat($refundReversed);`. Delete the `ponytail: credit refunds (M3b) …` sentence from the class comment. The refund rows carry `url => null` until Task 4 adds the payment-out page; in `resources/views/livewire/customers/statement.blade.php` render the reference as plain text when `$row['url']` is null (`@if ($row['url']) <flux:link …> @else {{ $row['reference'] }} @endif`).

In `app/Approvals/PaymentReversal.php` replace the `// Spec §7.2. ponytail: always true until credit refunds exist (M3b); …` comment with `// Spec §7.2: a payment whose credit was refunded cannot be reversed while the refund stands.`; the check itself is unchanged and now fires (the test above proves it).

- [ ] **Step 6: The Actions and the approval handler**

Create `app/Actions/Disbursements/MarkDisbursementPaid.php`:
```php
<?php

namespace App\Actions\Disbursements;

use App\Actions\NextDocumentNumber;
use App\Enums\DisbursementStatus;
use App\Enums\NumberSequenceKey;
use App\Models\Disbursement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Spec §7.5: the PO number is assigned when the payment out becomes paid. Internal: the caller locked the row. */
final class MarkDisbursementPaid
{
    public function __construct(private NextDocumentNumber $next) {}

    /** @param  array{method: string, paid_on: string, reference?: string|null}  $data */
    public function handle(Disbursement $locked, array $data, User $actor): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('MarkDisbursementPaid must run inside the caller\'s transaction.');
        }

        // Task 2 adds: the issued cheque when method = cheque. Task 6 adds: refunded deposit movements.

        $locked->forceFill([
            'status' => DisbursementStatus::Paid,
            'number' => ($this->next)(NumberSequenceKey::PaymentOut),
            'method' => $data['method'],
            'reference' => $data['reference'] ?? $locked->reference,
            'paid_on' => $data['paid_on'],
            'posted_at' => now(),
            'recorded_by' => $actor->id,
        ])->save();
    }
}
```
Create `app/Actions/Disbursements/RecordDisbursement.php`:
```php
<?php

namespace App\Actions\Disbursements;

use App\Actions\Approvals\RequestApproval;
use App\Enums\ApprovalAction;
use App\Enums\DisbursementMethod;
use App\Enums\DisbursementPurpose;
use App\Enums\DisbursementStatus;
use App\Enums\PayeeType;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Disbursement;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Spec §7.5. With a source (a payment holding credit; a deposit settlement from Task 6): paid at once, within the
 * source's limit. Without one (purpose other): pending → Management (§8.3 item 9) → approved → PayDisbursement.
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
        $v = Validator::make($data, [
            'purpose' => ['required', Rule::in([DisbursementPurpose::CreditRefund->value, DisbursementPurpose::Other->value])], // Task 6 adds deposit_refund
            'amount' => ['required', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'method' => ['required', Rule::enum(DisbursementMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'paid_on' => ['required_unless:purpose,other', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'payment_id' => ['required_if:purpose,credit_refund', 'nullable', 'integer', Rule::exists('payments', 'id')],
            'payee_type' => ['required_if:purpose,other', 'nullable', Rule::enum(PayeeType::class)],
            'payee_id' => ['required_if:purpose,other', 'nullable', 'integer'],
            'reason' => ['required_if:purpose,other', 'nullable', 'string', 'max:2000'],
        ])->validate();

        $amount = Fils::fromDecimal((string) $v['amount']);

        return DB::transaction(function () use ($actor, $v, $amount) {
            return match ($v['purpose']) {
                DisbursementPurpose::CreditRefund->value => $this->creditRefund($actor, $v, $amount),
                default => $this->other($actor, $v, $amount),
            };
        }, attempts: 3);
    }

    /** @param  array<string, mixed>  $v */
    private function creditRefund(User $actor, array $v, int $amount): Disbursement
    {
        $payment = Payment::query()->findOrFail((int) $v['payment_id']);
        $customer = Customer::query()->lockForUpdate()->findOrFail($payment->customer_id); // first lock (spec §7.2)
        if (! Customer::visibleTo($actor)->whereKey($customer->id)->exists()) {
            throw new AuthorizationException;
        }
        $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

        if ($payment->status !== PaymentStatus::Confirmed || $amount > $payment->unallocatedFils()) {
            throw ValidationException::withMessages(['amount' => __('At most :c BHD of this payment is credit that can be refunded.', ['c' => Fils::toDecimal(max(0, $payment->unallocatedFils()))])]);
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
        $this->paid->handle($out, ['method' => $v['method'], 'paid_on' => $v['paid_on'], 'reference' => $v['reference'] ?? null], $actor);

        return $out->refresh();
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
The `approved` → `paid` step inside `creditRefund` goes through the trigger's allowed transition (the row is inserted `approved`, then `MarkDisbursementPaid` flips it).

Create `app/Actions/Disbursements/PayDisbursement.php`:
```php
<?php

namespace App\Actions\Disbursements;

use App\Enums\DisbursementMethod;
use App\Enums\DisbursementStatus;
use App\Models\Disbursement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec §7.5: an approved payment out is recorded as paid by Finance. */
final class PayDisbursement
{
    public function __construct(private MarkDisbursementPaid $paid) {}

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Disbursement $disbursement, array $data): Disbursement
    {
        if (! $actor->can('create', Disbursement::class) || ! $actor->can('view', $disbursement)) {
            throw new AuthorizationException;
        }

        $v = Validator::make($data, [
            'method' => ['required', Rule::enum(DisbursementMethod::class)],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'reference' => ['nullable', 'string', 'max:100'],
        ])->validate();

        return DB::transaction(function () use ($actor, $disbursement, $v) {
            $locked = Disbursement::query()->lockForUpdate()->findOrFail($disbursement->id);
            if ($locked->status !== DisbursementStatus::Approved) {
                throw ValidationException::withMessages(['paid_on' => __('Only an approved payment out can be paid.')]);
            }
            $this->paid->handle($locked, $v, $actor);

            return $locked->refresh();
        }, attempts: 3);
    }
}
```
Create `app/Approvals/PaymentOut.php`:
```php
<?php

namespace App\Approvals;

use App\Enums\DisbursementStatus;
use App\Models\Approval;
use App\Models\Disbursement;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Spec §8.3 item 9: a payment out with no approved source. */
final class PaymentOut implements ApprovalHandler
{
    public function creatorId(Approval $approval): int
    {
        return Disbursement::query()->findOrFail($approval->approvable_id)->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $this->move($approval, DisbursementStatus::Approved);
    }

    public function reject(Approval $approval, User $approver): void
    {
        $this->move($approval, DisbursementStatus::Rejected);
    }

    private function move(Approval $approval, DisbursementStatus $to): void
    {
        $out = Disbursement::query()->lockForUpdate()->findOrFail($approval->approvable_id);
        if ($out->status !== DisbursementStatus::PendingApproval) {
            throw ValidationException::withMessages(['approval' => __('This payment out is no longer waiting for approval.')]);
        }
        $out->forceFill(['status' => $to])->save();
    }

    public function summary(Approval $approval): string
    {
        $out = Disbursement::query()->findOrFail($approval->approvable_id);

        return __('Pay :amount BHD to :payee by :method. Reason: :reason', [
            'amount' => $out->amount, 'payee' => $out->payee()->name_en, 'method' => $out->method->label(), 'reason' => $out->reason,
        ]);
    }

    public function url(Approval $approval): string
    {
        return route('approvals.index'); // Task 4 points this at the payment out
    }
}
```

In `app/Enums/ApprovalAction.php` add `case PaymentOut = 'payment_out.approve';`, label `__('Payment out')`, handler `\App\Approvals\PaymentOut::class` (import as `PaymentOutHandler` to avoid the name clash).

- [ ] **Step 7: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Disbursements tests/Feature/Payments tests/Feature/Customers tests/Feature/Integrity
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add payments out and credit refunds

Finance refunds a payment's unused credit at once, numbered as a
payment out; it reduces customer credit and appears on the statement.
A payment out with no source waits for Management before Finance pays
it. Payments out are never deleted and only move forward.
EOF
```
(End the message with your Co-Authored-By trailer.)

---

### Task 2: Issued cheques and the cheque carry-ins

**Spec:** §7.4 (issued cheques: `issued → cleared | cancelled`, each linked to a disbursement; cancelling one is a payment-out reversal), §6.3 (cheques re-pointed when invoices are replaced — the target must stay movable while held/deposited), M3a final-review carry-ins (lock the target in ClearCheque; freeze a cheque's target once it has left held/deposited; refuse a plain reversal of a cheque payment)

**Files:**
- Create: `database/migrations/2026_10_20_000200_add_issued_cheques.php`, `app/Actions/Cheques/ClearIssuedCheque.php`, `tests/Feature/Cheques/IssuedChequeTest.php`
- Modify: `app/Models/Cheque.php`, `app/Enums/ChequeStatus.php`, `app/Actions/Disbursements/MarkDisbursementPaid.php`, `app/Actions/Disbursements/RecordDisbursement.php`, `app/Actions/Disbursements/PayDisbursement.php`, `app/Actions/Cheques/ClearCheque.php`, `app/Actions/Payments/RequestPaymentReversal.php`, `app/Integrity/IntegrityCheck.php`, `tests/Feature/Cheques/ChequeTriggersTest.php`, `tests/Feature/Payments/PaymentReversalTest.php`

**Interfaces:**
- Consumes: `MarkDisbursementPaid`, `RecordDisbursement`, `PayDisbursement` (Task 1)
- Produces: `ChequeStatus::Issued`; `cheques.disbursement_id`; `Cheque::disbursement()`; `ClearIssuedCheque::handle(User $actor, Cheque $issued, string $clearedOn): void`; cheque fields in disbursement `$data`: `cheque_no`, `bank_name`, `cheque_date` (required when `method = cheque`)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Cheques/IssuedChequeTest.php`:
```php
<?php

use App\Actions\Cheques\ClearIssuedCheque;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->customer = Customer::factory()->create();
    $this->payment = app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '90.000']);
});

test('a refund paid by cheque writes an issued cheque linked both ways, then the cheque clears', function () {
    $out = app(RecordDisbursement::class)->handle($this->finance, [
        'purpose' => 'credit_refund', 'payment_id' => $this->payment->id, 'amount' => '90.000', 'method' => 'cheque',
        'paid_on' => '2026-10-05', 'cheque_no' => '000777', 'bank_name' => 'NBB', 'cheque_date' => '2026-10-07',
    ]);

    $cheque = Cheque::sole();
    expect($cheque->direction->value)->toBe('issued')
        ->and($cheque->status->value)->toBe('issued')
        ->and($cheque->customer_id)->toBe($this->customer->id)
        ->and($cheque->amount)->toBe('90.000')
        ->and($cheque->disbursement_id)->toBe($out->id)
        ->and($out->cheque_id)->toBe($cheque->id);

    app(ClearIssuedCheque::class)->handle($this->finance, $cheque, '2026-10-05');
    expect($cheque->fresh()->status->value)->toBe('cleared')->and($cheque->fresh()->cleared_on->toDateString())->toBe('2026-10-05');
});

test('a cheque payment out needs the cheque details', function () {
    expect(fn () => app(RecordDisbursement::class)->handle($this->finance, [
        'purpose' => 'credit_refund', 'payment_id' => $this->payment->id, 'amount' => '1', 'method' => 'cheque', 'paid_on' => '2026-10-05',
    ]))->toThrow(ValidationException::class);
});

test('a received cheque cannot be cleared as an issued one', function () {
    $received = app(App\Actions\Cheques\RecordCheques::class)->handle($this->finance, $this->customer, null, [['cheque_no' => '1', 'bank_name' => 'BBK', 'cheque_date' => '2026-10-05', 'amount' => '5']])->sole();

    expect(fn () => app(ClearIssuedCheque::class)->handle($this->finance, $received, '2026-10-05'))->toThrow(ValidationException::class);
});
```
Append to `tests/Feature/Cheques/ChequeTriggersTest.php`:
```php
test('a cheque\'s target invoice is fixed once it has left held and deposited', function () {
    $invoiceId = issuedInvoice(App\Models\Customer::find(DB::table('cheques')->value('customer_id')), [['net' => '1.000']])->id;
    ($this->row)()->update(['invoice_id' => $invoiceId]);           // held: movable
    ($this->row)()->update(['status' => 'cancelled']);
    expect(fn () => ($this->row)()->update(['invoice_id' => null]))->toThrow(QueryException::class, 'cheques: the target is fixed once the cheque has left held and deposited');
});

test('issued cheques move only issued → cleared or cancelled, and need exactly one payee', function () {
    $user = User::factory()->create()->id;
    $base = ['direction' => 'issued', 'cheque_no' => '9', 'bank_name' => 'NBB', 'cheque_date' => '2026-10-01', 'amount' => '1.000', 'status' => 'issued', 'created_by' => $user, 'created_at' => now(), 'updated_at' => now()];

    expect(fn () => DB::table('cheques')->insert($base))->toThrow(QueryException::class); // no payee
    $id = DB::table('cheques')->insertGetId([...$base, 'customer_id' => Customer::factory()->create()->id]);

    expect(fn () => DB::table('cheques')->where('id', $id)->update(['status' => 'deposited']))->toThrow(QueryException::class, 'cheques: status change not allowed');
    DB::table('cheques')->where('id', $id)->update(['status' => 'cancelled']);
});
```
Append to `tests/Feature/Payments/PaymentReversalTest.php`:
```php
test('a cheque payment is reversed only by bouncing its cheque', function () {
    $customer = Customer::factory()->create();
    $cheque = app(App\Actions\Cheques\RecordCheques::class)->handle($this->finance, $customer, null, [['cheque_no' => '5', 'bank_name' => 'NBB', 'cheque_date' => '2026-10-05', 'amount' => '5']])->sole();
    app(App\Actions\Cheques\DepositCheques::class)->handle($this->finance, [$cheque->id], '2026-10-05');
    $payment = app(App\Actions\Cheques\ClearCheque::class)->handle($this->finance, $cheque->fresh(), '2026-10-05');

    expect(fn () => app(RequestPaymentReversal::class)->handle($this->finance, $payment, 'Typo'))->toThrow(ValidationException::class);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Cheques tests/Feature/Payments/PaymentReversalTest.php`
Expected: FAIL — `Class "App\Actions\Cheques\ClearIssuedCheque" not found`.

- [ ] **Step 3: Migration**

Create `database/migrations/2026_10_20_000200_add_issued_cheques.php`:
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
        Schema::table('cheques', function (Blueprint $table) {
            $table->foreignId('disbursement_id')->nullable()->after('payment_id')->constrained()->restrictOnDelete();
        });
        Schema::table('disbursements', function (Blueprint $table) {
            $table->foreign('cheque_id')->references('id')->on('cheques')->restrictOnDelete();
        });

        // A received cheque comes from a customer; an issued one goes to exactly one payee (spec §7.4).
        DB::statement('ALTER TABLE cheques DROP CHECK cheques_party_chk');
        DB::statement(<<<'SQL'
            ALTER TABLE cheques ADD CONSTRAINT cheques_party_chk CHECK (
                (direction = 'received' AND customer_id IS NOT NULL AND owner_id IS NULL)
                OR (direction = 'issued' AND ((customer_id IS NULL) <> (owner_id IS NULL))))
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
                IF NOT (NEW.direction <=> OLD.direction AND NEW.customer_id <=> OLD.customer_id AND NEW.owner_id <=> OLD.owner_id) THEN
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
    }

    public function down(): void
    {
        // Restoring M3a's trigger and check is not supported; roll back with migrate:fresh.
        Schema::table('disbursements', fn (Blueprint $table) => $table->dropForeign(['cheque_id']));
        Schema::table('cheques', fn (Blueprint $table) => $table->dropConstrainedForeignId('disbursement_id'));
    }
};
```
The trigger count is unchanged (one dropped, one created).

- [ ] **Step 4: Model, issuing and clearing**

In `app/Enums/ChequeStatus.php` add `case Issued = 'issued'; // an issued cheque not yet presented (spec §7.4)` after `Held`. In `app/Models/Cheque.php` add `@property int|null $disbursement_id`, `'disbursement_id'` to the activity log fields, and:
```php
    /** @return BelongsTo<Disbursement, $this> */
    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(Disbursement::class);
    }
```
In `RecordDisbursement::handle()` add the cheque rules to the validator:
```php
            'cheque_no' => ['required_if:method,cheque', 'nullable', 'string', 'max:30'],
            'bank_name' => ['required_if:method,cheque', 'nullable', 'string', 'max:100'],
            'cheque_date' => ['required_if:method,cheque', 'nullable', 'date_format:Y-m-d'],
```
and pass `cheque_no`, `bank_name`, `cheque_date` through to `MarkDisbursementPaid` in `creditRefund()` (`[...$chequeFields]`, using `array_intersect_key($v, array_flip(['cheque_no', 'bank_name', 'cheque_date']))`). For `purpose = other`, store nothing about the cheque at request time: the cheque details are given when Finance pays it (`PayDisbursement` validator gets the same three rules, and passes them on).

In `app/Actions/Disbursements/MarkDisbursementPaid.php` replace the `// Task 2 adds …` half of the comment with (import `Cheque`, `ChequeDirection`, `ChequeStatus`, `DisbursementMethod`, `PayeeType`):
```php
        $chequeId = null;
        if ($data['method'] === DisbursementMethod::Cheque->value) {
            $cheque = (new Cheque)->forceFill([
                'direction' => ChequeDirection::Issued,
                'customer_id' => $locked->payee_type === PayeeType::Customer ? $locked->payee_id : null,
                'owner_id' => $locked->payee_type === PayeeType::Owner ? $locked->payee_id : null,
                'cheque_no' => $data['cheque_no'],
                'bank_name' => $data['bank_name'],
                'cheque_date' => $data['cheque_date'],
                'amount' => $locked->amount,
                'status' => ChequeStatus::Issued,
                'disbursement_id' => $locked->id,
                'created_by' => $actor->id,
            ]);
            $cheque->save();
            $chequeId = $cheque->id;
        }
```
and add `'cheque_id' => $chequeId,` to the `forceFill`. Widen the docblock type of `$data` to include the three optional cheque keys.

Create `app/Actions/Cheques/ClearIssuedCheque.php`:
```php
<?php

namespace App\Actions\Cheques;

use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Models\Cheque;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Spec §7.4: the payee presented the company's cheque. Nothing else moves; the payment out was already paid. */
final class ClearIssuedCheque
{
    public function handle(User $actor, Cheque $cheque, string $clearedOn): void
    {
        if (! $actor->can('manage', Cheque::class)) {
            throw new AuthorizationException;
        }
        Validator::make(['cleared_on' => $clearedOn], ['cleared_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()]])->validate();

        DB::transaction(function () use ($cheque, $clearedOn) {
            $cheque = Cheque::query()->lockForUpdate()->findOrFail($cheque->id);
            if ($cheque->direction !== ChequeDirection::Issued || $cheque->status !== ChequeStatus::Issued) {
                throw ValidationException::withMessages(['cleared_on' => __('Only an issued cheque that has not cleared can be marked cleared.')]);
            }
            $cheque->forceFill(['status' => ChequeStatus::Cleared, 'cleared_on' => $clearedOn])->save();
        }, attempts: 3);
    }
}
```
`Cheque::visibleTo` already scopes by `customer_id`; issued cheques to owners have `customer_id` NULL. Change its non-view-all branch to `$query->where(fn ($q) => $q->whereIn('customer_id', Customer::query()->visibleTo($user)->select('id'))->orWhereNotNull('owner_id'));` — owners are not building-scoped for finance roles (all finance roles see all buildings).

The received-cheque register (`Cheques\Index`) keeps `where('direction', 'received')`; issued cheques are reached from their payment out (Task 4).

- [ ] **Step 5: The two M3a carry-ins**

In `app/Actions/Cheques/ClearCheque.php` replace the target lines with (the customer and cheque locks are already held; an invoice lock after them is in order):
```php
            $target = $cheque->invoice_id ? Invoice::query()->lockForUpdate()->find($cheque->invoice_id) : null;
            if ($target?->status === InvoiceStatus::Scheduled) {
                $this->issue->handle($target, $actor); // false when held back (spec §4.6): the money then goes oldest-first
            }
```
In `app/Actions/Payments/RequestPaymentReversal.php`, after the `DepositApplied` check:
```php
            // Plan ruling 3: a cheque payment is undone by bouncing its cheque, so cheque and payment never disagree.
            if ($payment->method === PaymentMethod::Cheque && ! isset($payload['bounced_on'])) {
                throw ValidationException::withMessages(['reversalReason' => __('This payment came from a cheque: mark the cheque as bounced instead.')]);
            }
```
On the payment page (`app/Livewire/Payments/Show.php`), hide **Request reversal** for cheque payments: add `&& $payment->method->value !== 'cheque'` to `canReverse`, and show a link to the cheque instead: in the view, `@if ($payment->cheque_id)<flux:button :href="route('cheques.show', $payment->cheque_id)" wire:navigate>{{ __('Cheque') }}</flux:button>@endif`.

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Cheques tests/Feature/Payments tests/Feature/Disbursements tests/Feature/Integrity
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add issued cheques and tighten received ones

A payment out by cheque writes the company's cheque, linked both ways,
which is later marked cleared. A cheque's target invoice can move only
while it is held or deposited, clearing locks the target first, and a
cheque payment is undone only by bouncing its cheque.
EOF
```

---

### Task 3: Payment-out reversal (approval item 6)

**Spec:** §7.3 (payment-out reversal, same approval item as payment reversal: marks the disbursement `reversed` and sets `reversed_at`; for a deposit refund, writes an opposite `refunded` movement; cancels its issued cheque if there is one; reopens its source — a deposit settlement returns to `approved`, a credit refund's amount returns to customer credit; an owner payable returns to scheduled in M4), §7.4 (cancelling an issued cheque is a payment-out reversal), §8.3 (rejecting leaves the record unchanged)

**Files:**
- Create: `app/Actions/Disbursements/RequestDisbursementReversal.php`, `app/Approvals/PaymentOutReversal.php`, `tests/Feature/Disbursements/PaymentOutReversalTest.php`
- Modify: `app/Enums/ApprovalAction.php`

**Interfaces:**
- Consumes: `Disbursement`, `DisbursementPolicy::reverse`, `RequestApproval`
- Produces: `RequestDisbursementReversal::handle(User $actor, Disbursement $paid, string $reason): Approval`; `ApprovalAction::PaymentOutReversal` (`'payment_out.reverse'`) → `App\Approvals\PaymentOutReversal`; the handler's `reopen()` hook that Task 6 extends for deposit refunds

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Disbursements/PaymentOutReversalTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Billing\CustomerCredit;
use App\Enums\RoleName;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->customer = Customer::factory()->create();
    $payment = app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '50.000']);
    $this->refund = app(RecordDisbursement::class)->handle($this->finance, [
        'purpose' => 'credit_refund', 'payment_id' => $payment->id, 'amount' => '50.000', 'method' => 'cheque', 'paid_on' => '2026-10-05',
        'cheque_no' => '000123', 'bank_name' => 'NBB', 'cheque_date' => '2026-10-05',
    ]);
});

test('an approved reversal of a credit refund cancels its cheque and returns the credit', function () {
    expect(CustomerCredit::fils($this->customer->id))->toBe(0);

    $approval = app(RequestDisbursementReversal::class)->handle($this->finance, $this->refund, 'Cheque lost in the post');
    expect($this->refund->fresh()->status->value)->toBe('paid'); // nothing moves until approval

    app(DecideApproval::class)->handle($this->management, $approval, true);

    $out = $this->refund->fresh();
    expect($out->status->value)->toBe('reversed')
        ->and($out->reversed_at)->not->toBeNull()
        ->and(Cheque::sole()->status->value)->toBe('cancelled')
        ->and(CustomerCredit::fils($this->customer->id))->toBe(50_000);
});

test('a cleared issued cheque is not cancelled; rejecting changes nothing; reversals are one-way', function () {
    app(App\Actions\Cheques\ClearIssuedCheque::class)->handle($this->finance, Cheque::sole(), '2026-10-05');
    $approval = app(RequestDisbursementReversal::class)->handle($this->finance, $this->refund, 'x');
    expect(fn () => app(RequestDisbursementReversal::class)->handle($this->finance, $this->refund, 'again'))->toThrow(ValidationException::class);

    app(DecideApproval::class)->handle($this->management, $approval, false, 'Paid correctly');
    expect($this->refund->fresh()->status->value)->toBe('paid');

    app(DecideApproval::class)->handle($this->management, app(RequestDisbursementReversal::class)->handle($this->finance, $this->refund, 'Bank returned it'), true);
    expect(Cheque::sole()->status->value)->toBe('cleared') // a cleared cheque stays as the bank saw it
        ->and($this->refund->fresh()->status->value)->toBe('reversed');
    expect(fn () => app(RequestDisbursementReversal::class)->handle($this->finance, $this->refund->fresh(), 'x'))->toThrow(ValidationException::class);
});

test('only disbursements.manage requests; a reason is required; only paid rows reverse', function () {
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    expect(fn () => app(RequestDisbursementReversal::class)->handle($pm, $this->refund, 'x'))->toThrow(AuthorizationException::class);
    expect(fn () => app(RequestDisbursementReversal::class)->handle($this->finance, $this->refund, ' '))->toThrow(ValidationException::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Disbursements/PaymentOutReversalTest.php`
Expected: FAIL — `Class "App\Actions\Disbursements\RequestDisbursementReversal" not found`.

- [ ] **Step 3: Implement**

Create `app/Actions/Disbursements/RequestDisbursementReversal.php`:
```php
<?php

namespace App\Actions\Disbursements;

use App\Actions\Approvals\RequestApproval;
use App\Enums\ApprovalAction;
use App\Enums\DisbursementStatus;
use App\Models\Approval;
use App\Models\Disbursement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §7.3: Finance asks, with a reason; nothing changes until Management approves (§8.3 item 6). */
final class RequestDisbursementReversal
{
    public function __construct(private RequestApproval $request) {}

    public function handle(User $actor, Disbursement $disbursement, string $reason): Approval
    {
        if (! $actor->can('reverse', $disbursement)) {
            throw new AuthorizationException;
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reversalReason' => __('Give the reason for the reversal.')]);
        }

        return DB::transaction(function () use ($actor, $disbursement, $reason) {
            $locked = Disbursement::query()->lockForUpdate()->findOrFail($disbursement->id);
            if ($locked->status !== DisbursementStatus::Paid) {
                throw ValidationException::withMessages(['reversalReason' => __('Only a paid payment out can be reversed.')]);
            }

            return $this->request->handle($actor, $locked, ApprovalAction::PaymentOutReversal, trim($reason));
        }, attempts: 3);
    }
}
```
Create `app/Approvals/PaymentOutReversal.php`:
```php
<?php

namespace App\Approvals;

use App\Enums\ChequeStatus;
use App\Enums\DisbursementStatus;
use App\Enums\PayeeType;
use App\Models\Approval;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Disbursement;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Spec §7.3, §8.3 item 6 (payments out). Runs inside DecideApproval's transaction. */
final class PaymentOutReversal implements ApprovalHandler
{
    public function creatorId(Approval $approval): int
    {
        $out = Disbursement::query()->findOrFail($approval->approvable_id);

        return $out->recorded_by ?? $out->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $out = Disbursement::query()->findOrFail($approval->approvable_id);
        if ($out->payee_type === PayeeType::Customer) {
            Customer::query()->lockForUpdate()->findOrFail($out->payee_id); // first lock (spec §7.2): credit changes
        }
        $cheque = $out->cheque_id !== null ? Cheque::query()->lockForUpdate()->findOrFail($out->cheque_id) : null;

        $this->reopen($out, $approver); // Task 6: deposit refunds write the opposite movement and reopen the settlement

        $out = Disbursement::query()->lockForUpdate()->findOrFail($out->id); // disbursements lock last
        if ($out->status !== DisbursementStatus::Paid) {
            throw ValidationException::withMessages(['approval' => __('This payment out is no longer paid.')]);
        }
        $out->forceFill(['status' => DisbursementStatus::Reversed, 'reversed_at' => now()])->save();

        // Spec §7.4: an issued cheque not yet presented is cancelled; one the bank already cleared stays cleared.
        if ($cheque?->status === ChequeStatus::Issued) {
            $cheque->forceFill(['status' => ChequeStatus::Cancelled])->save();
        }
    }

    /** A credit refund needs nothing here: its amount counts as credit again once the row is reversed. */
    private function reopen(Disbursement $out, User $approver): void {}

    /** A request about an existing record: rejecting leaves it unchanged (spec §8.3). */
    public function reject(Approval $approval, User $approver): void {}

    public function summary(Approval $approval): string
    {
        $out = Disbursement::query()->findOrFail($approval->approvable_id);

        return __('Reverse payment out :n: :amount BHD to :payee (:purpose). Reason: :reason', [
            'n' => $out->number, 'amount' => $out->amount, 'payee' => $out->payee()->name_en,
            'purpose' => $out->purpose->label(), 'reason' => $approval->reason,
        ]);
    }

    public function url(Approval $approval): string
    {
        return route('approvals.index'); // Task 4 points this at the payment out
    }
}
```
In `app/Enums/ApprovalAction.php` add `case PaymentOutReversal = 'payment_out.reverse';`, label `__('Payment out reversal')`, handler `\App\Approvals\PaymentOutReversal::class`.

- [ ] **Step 4: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Disbursements tests/Feature/Approvals
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Reverse payments out with Management approval

A reversal marks the payment out reversed, cancels its cheque if the
payee has not presented it, and gives a refunded credit back to the
customer. Rejecting a request changes nothing.
EOF
```

---
### Task 4: Payments-out screens

**Spec:** §7.5, §7.3, §7.4 (issued cheques reached from their payment out), §8.1, §2 (375 px)

**Files:**
- Create: `app/Livewire/Disbursements/{Index,Create,Show}.php`, `resources/views/livewire/disbursements/{index,create,show}.blade.php`, `tests/Feature/Disbursements/DisbursementScreensTest.php`
- Modify: `routes/property.php`, `resources/views/layouts/app/sidebar.blade.php`, `app/Livewire/Payments/Show.php`, `resources/views/livewire/payments/show.blade.php`, `app/Approvals/{PaymentOut,PaymentOutReversal}.php`, `app/Billing/CustomerStatement.php`

**Interfaces:**
- Consumes: `RecordDisbursement`, `PayDisbursement`, `RequestDisbursementReversal`, `ClearIssuedCheque`, `DisbursementPolicy`
- Produces: routes `disbursements.index` (`?status=`), `disbursements.create`, `disbursements.show`; a **Refund credit** modal on the payment page; `Disbursements\Show` with `protected function disbursement(): Disbursement`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Disbursements/DisbursementScreensTest.php`:
```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Livewire\Disbursements\Create;
use App\Livewire\Disbursements\Index;
use App\Livewire\Disbursements\Show;
use App\Livewire\Payments\Show as PaymentShow;
use App\Models\Approval;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Disbursement;
use App\Models\Owner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->customer = Customer::factory()->create(['name_en' => 'Noor Aziz']);
    $this->payment = app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '70.000']);
});

test('Finance refunds credit from the payment page', function () {
    Livewire::actingAs($this->finance)->test(PaymentShow::class, ['payment' => $this->payment])
        ->set('refund.amount', '70')
        ->set('refund.method', 'bank_transfer')
        ->set('refund.paid_on', '2026-10-05')
        ->call('refundCredit')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Disbursement::sole()->status->value)->toBe('paid');
});

test('a source-less payment out is requested, approved, then paid from its page', function () {
    $owner = Owner::factory()->create(['name_en' => 'Salman Holdings']);
    Livewire::actingAs($this->finance)->test(Create::class)
        ->set('form.payee_type', 'owner')->set('form.payee_id', $owner->id)
        ->set('form.amount', '25')->set('form.method', 'cash')->set('form.reason', 'Shared repair')
        ->call('save')->assertHasNoErrors()->assertRedirect();

    $out = Disbursement::sole();
    app(App\Actions\Approvals\DecideApproval::class)->handle($this->management, Approval::sole(), true);

    Livewire::actingAs($this->finance)->test(Show::class, ['disbursement' => $out])
        ->assertSee('Salman Holdings')
        ->set('pay.method', 'cash')->set('pay.paid_on', '2026-10-05')
        ->call('payNow')->assertHasNoErrors();
    expect($out->fresh()->status->value)->toBe('paid');

    Livewire::actingAs($this->finance)->test(Show::class, ['disbursement' => $out->fresh()])
        ->set('reversalReason', 'Paid twice')->call('requestReversal')->assertHasNoErrors()
        ->assertSee('Reversal waiting for approval');

    Livewire::actingAs($this->finance)->test(Index::class)->assertSee($out->fresh()->number);
});

test('finance.view holders see payments out; only disbursements.manage creates them', function () {
    $this->actingAs($this->management)->get(route('disbursements.index'))->assertOk();
    $this->actingAs($this->management)->get(route('disbursements.create'))->assertForbidden();
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    $this->actingAs($pm)->get(route('disbursements.index'))->assertForbidden();

    Livewire::actingAs($this->management)->test(PaymentShow::class, ['payment' => $this->payment])
        ->set('refund.amount', '1')->set('refund.method', 'cash')->set('refund.paid_on', '2026-10-05')
        ->call('refundCredit')->assertForbidden();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Disbursements/DisbursementScreensTest.php`
Expected: FAIL — `Class "App\Livewire\Disbursements\Create" not found`.

- [ ] **Step 3: The screens**

Create `app/Livewire/Disbursements/Index.php`:
```php
<?php

namespace App\Livewire\Disbursements;

use App\Enums\DisbursementStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use App\Models\Disbursement;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Payments out')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $status = 'all';

    public function updating(string $property): void
    {
        if ($property === 'status') {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $rows = Disbursement::query()
            ->where(fn ($q) => $q->where('payee_type', 'owner')
                ->orWhereIn('payee_id', Customer::query()->visibleTo($this->actor())->select('id')))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->latest('id')
            ->paginate(50);

        return view('livewire.disbursements.index', ['rows' => $rows, 'statuses' => DisbursementStatus::cases()]);
    }
}
```
Create `resources/views/livewire/disbursements/index.blade.php`:
```blade
<section class="w-full space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <flux:heading size="xl" level="1">{{ __('Payments out') }}</flux:heading>
        @can('create', App\Models\Disbursement::class)
            <flux:button variant="primary" :href="route('disbursements.create')" wire:navigate>{{ __('New payment out') }}</flux:button>
        @endcan
    </div>
    <flux:select wire:model.live="status" :label="__('Status')" class="max-w-48">
        <option value="all">{{ __('All') }}</option>
        @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
    </flux:select>

    <div class="overflow-x-auto">
        <flux:table :paginate="$rows">
            <flux:table.columns>
                <flux:table.column>{{ __('Number') }}</flux:table.column>
                <flux:table.column>{{ __('Payee') }}</flux:table.column>
                <flux:table.column>{{ __('Purpose') }}</flux:table.column>
                <flux:table.column>{{ __('Amount') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($rows as $row)
                    <flux:table.row :key="$row->id">
                        <flux:table.cell><flux:link :href="route('disbursements.show', $row)" wire:navigate>{{ $row->label() }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $row->payee()->name_en }}</flux:table.cell>
                        <flux:table.cell>{{ $row->purpose->label() }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $row->amount }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $row->status->label() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```
Create `app/Livewire/Disbursements/Create.php` (source-less payments out only; credit refunds start on the payment page, deposit refunds on the settlement page):
```php
<?php

namespace App\Livewire\Disbursements;

use App\Actions\Disbursements\RecordDisbursement;
use App\Enums\DisbursementMethod;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use App\Models\Disbursement;
use App\Models\Owner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('New payment out')]
class Create extends Component
{
    use WithActor;

    /** @var array<string, mixed> */
    public array $form = ['payee_type' => 'owner', 'method' => 'bank_transfer'];

    public function mount(): void
    {
        abort_unless($this->actor()->can('create', Disbursement::class), 403);
    }

    public function save(RecordDisbursement $record): void
    {
        try {
            $out = $record->handle($this->actor(), [...$this->form, 'purpose' => 'other']);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
        }

        $this->redirectRoute('disbursements.show', $out, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.disbursements.create', [
            'payees' => ($this->form['payee_type'] ?? 'owner') === 'customer'
                ? Customer::query()->visibleTo($this->actor())->orderBy('name_en')->get(['id', 'name_en'])
                : Owner::query()->orderBy('name_en')->get(['id', 'name_en']),
            'methods' => DisbursementMethod::cases(),
        ]);
    }
}
```
Create `resources/views/livewire/disbursements/create.blade.php`:
```blade
<section class="w-full max-w-xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('New payment out') }}</flux:heading>
    <flux:text>{{ __('A payment out with no invoice, payment or settlement behind it needs Management approval before it is paid.') }}</flux:text>

    <form wire:submit="save" class="space-y-4">
        <flux:radio.group wire:model.live="form.payee_type" :label="__('Pay to')" variant="segmented">
            <flux:radio value="owner" :label="__('Owner')" />
            <flux:radio value="customer" :label="__('Customer')" />
        </flux:radio.group>
        <flux:select wire:model="form.payee_id" :label="__('Payee')">
            <option value="">{{ __('Choose…') }}</option>
            @foreach ($payees as $p)<option value="{{ $p->id }}">{{ $p->name_en }}</option>@endforeach
        </flux:select>
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="form.amount" inputmode="decimal" :label="__('Amount (BHD)')" />
            <flux:select wire:model="form.method" :label="__('Method')">
                @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
            </flux:select>
        </div>
        <flux:textarea wire:model="form.reason" :label="__('Reason')" rows="3" />
        @foreach (['payee_type', 'payee_id', 'amount', 'method', 'reason'] as $f)<flux:error name="form.{{ $f }}" />@endforeach
        <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Send for approval') }}</flux:button>
    </form>
</section>
```
Create `app/Livewire/Disbursements/Show.php`:
```php
<?php

namespace App\Livewire\Disbursements;

use App\Actions\Cheques\ClearIssuedCheque;
use App\Actions\Disbursements\PayDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Enums\ApprovalAction;
use App\Enums\DisbursementMethod;
use App\Livewire\Concerns\WithActor;
use App\Models\Approval;
use App\Models\Disbursement;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $disbursementId;

    /** @var array<string, string> */
    public array $pay = ['method' => 'bank_transfer', 'paid_on' => '', 'reference' => '', 'cheque_no' => '', 'bank_name' => '', 'cheque_date' => ''];

    public string $reversalReason = '';

    public string $clearedOn = '';

    public function mount(Disbursement $disbursement): void
    {
        abort_unless($this->actor()->can('view', $disbursement), 403);
        $this->disbursementId = $disbursement->id;
        $this->pay['paid_on'] = $this->pay['cheque_date'] = $this->clearedOn = now('Asia/Bahrain')->toDateString();
    }

    protected function disbursement(): Disbursement
    {
        return Disbursement::with(['creator:id,name', 'recorder:id,name', 'cheque'])->findOrFail($this->disbursementId);
    }

    public function payNow(PayDisbursement $pay): void
    {
        try {
            $pay->handle($this->actor(), $this->disbursement(), $this->pay);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["pay.$k" => $m])->all());
        }
        Flux::modal('pay')->close();
        Flux::toast(variant: 'success', text: __('Payment out recorded as paid.'));
    }

    public function requestReversal(RequestDisbursementReversal $request): void
    {
        try {
            $request->handle($this->actor(), $this->disbursement(), $this->reversalReason);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['reversalReason' => Arr::flatten($e->errors())]);
        }
        $this->reset('reversalReason');
        Flux::modal('reverse')->close();
        Flux::toast(variant: 'success', text: __('Reversal sent for approval.'));
    }

    public function clearCheque(ClearIssuedCheque $clear): void
    {
        $cheque = $this->disbursement()->cheque;
        abort_if($cheque === null, 404);
        try {
            $clear->handle($this->actor(), $cheque, $this->clearedOn);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['clearedOn' => Arr::flatten($e->errors())]);
        }
        Flux::toast(variant: 'success', text: __('Cheque marked cleared.'));
    }

    public function render(): View
    {
        $out = $this->disbursement();
        $canManage = $this->actor()->can('create', Disbursement::class);
        $pending = fn (ApprovalAction $action) => Approval::query()->pending()->where('approvable_type', $out->getMorphClass())
            ->where('approvable_id', $out->id)->where('action', $action)->exists();

        return view('livewire.disbursements.show', [
            'out' => $out,
            'methods' => DisbursementMethod::cases(),
            'canPay' => $canManage && $out->status->value === 'approved',
            'canReverse' => $this->actor()->can('reverse', $out) && $out->status->value === 'paid',
            'pendingReversal' => $pending(ApprovalAction::PaymentOutReversal),
            'canClearCheque' => $this->actor()->can('manage', \App\Models\Cheque::class) && $out->cheque?->status->value === 'issued',
        ])->title($out->label());
    }
}
```
(import `App\Models\Cheque` rather than fully qualifying.)

Create `resources/views/livewire/disbursements/show.blade.php`:
```blade
<section class="w-full max-w-3xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ $out->label() }}</flux:heading>
            <flux:badge>{{ $out->status->label() }}</flux:badge>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canPay)
                <flux:modal.trigger name="pay"><flux:button variant="primary">{{ __('Record as paid') }}</flux:button></flux:modal.trigger>
            @endif
            @if ($pendingReversal)
                <flux:badge color="amber">{{ __('Reversal waiting for approval') }}</flux:badge>
            @elseif ($canReverse)
                <flux:modal.trigger name="reverse"><flux:button variant="danger">{{ __('Request reversal') }}</flux:button></flux:modal.trigger>
            @endif
        </div>
    </div>

    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
        <div><dt class="text-sm text-zinc-500">{{ __('Payee') }}</dt><dd>{{ $out->payee()->name_en }} ({{ $out->payee_type->value }})</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Purpose') }}</dt><dd>{{ $out->purpose->label() }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Amount') }}</dt><dd class="tabular-nums">{{ $out->amount }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Method') }}</dt><dd>{{ $out->method->label() }}{{ $out->reference ? ' · '.$out->reference : '' }}</dd></div>
        @if ($out->paid_on)<div><dt class="text-sm text-zinc-500">{{ __('Paid on') }}</dt><dd>{{ $out->paid_on->format('d/m/Y') }} · {{ $out->recorder?->name }}</dd></div>@endif
        @if ($out->source_type === 'payment')<div><dt class="text-sm text-zinc-500">{{ __('Refunds credit of') }}</dt><dd><flux:link :href="route('payments.show', $out->source_id)" wire:navigate>{{ $out->source()?->number }}</flux:link></dd></div>@endif
        @if ($out->reason)<div class="sm:col-span-3"><dt class="text-sm text-zinc-500">{{ __('Reason') }}</dt><dd>{{ $out->reason }}</dd></div>@endif
        <div><dt class="text-sm text-zinc-500">{{ __('Requested by') }}</dt><dd>{{ $out->creator->name }}</dd></div>
        @if ($out->reversed_at)<div><dt class="text-sm text-zinc-500">{{ __('Reversed') }}</dt><dd>{{ $out->reversed_at->timezone('Asia/Bahrain')->format('d/m/Y H:i') }}</dd></div>@endif
    </dl>

    @if ($out->cheque)
        <flux:card class="flex flex-wrap items-end justify-between gap-3">
            <div>{{ __('Cheque :n on :b, dated :d', ['n' => $out->cheque->cheque_no, 'b' => $out->cheque->bank_name, 'd' => $out->cheque->cheque_date->format('d/m/Y')]) }} · <flux:badge size="sm">{{ $out->cheque->status->label() }}</flux:badge></div>
            @if ($canClearCheque)
                <form wire:submit="clearCheque" class="flex items-end gap-2">
                    <flux:input wire:model="clearedOn" type="date" :label="__('Cleared on')" />
                    <flux:button type="submit">{{ __('Cleared') }}</flux:button>
                </form>
            @endif
        </flux:card>
        <flux:error name="clearedOn" />
    @endif

    <flux:modal name="pay" class="md:w-96">
        <form wire:submit="payNow" class="space-y-4">
            <flux:heading size="lg">{{ __('Record as paid') }}</flux:heading>
            <flux:select wire:model.live="pay.method" :label="__('Method')">
                @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
            </flux:select>
            <flux:input wire:model="pay.paid_on" type="date" :label="__('Paid on')" />
            <flux:input wire:model="pay.reference" :label="__('Reference')" />
            @if (($pay['method'] ?? '') === 'cheque')
                <flux:input wire:model="pay.cheque_no" :label="__('Cheque no.')" />
                <flux:input wire:model="pay.bank_name" :label="__('Bank')" />
                <flux:input wire:model="pay.cheque_date" type="date" :label="__('Cheque date')" />
            @endif
            @foreach (['method', 'paid_on', 'reference', 'cheque_no', 'bank_name', 'cheque_date'] as $f)<flux:error name="pay.{{ $f }}" />@endforeach
            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="payNow">{{ __('Save') }}</flux:button>
        </form>
    </flux:modal>

    <flux:modal name="reverse" class="md:w-96">
        <form wire:submit="requestReversal" class="space-y-4">
            <flux:heading size="lg">{{ __('Reverse :n', ['n' => $out->label()]) }}</flux:heading>
            <flux:textarea wire:model="reversalReason" :label="__('Reason')" rows="3" />
            <flux:error name="reversalReason" />
            <flux:button variant="danger" type="submit">{{ __('Send for approval') }}</flux:button>
        </form>
    </flux:modal>
</section>
```
In `app/Livewire/Payments/Show.php` add (import `App\Actions\Disbursements\RecordDisbursement`, `App\Models\Disbursement`):
```php
    /** @var array<string, string> */
    public array $refund = ['amount' => '', 'method' => 'bank_transfer', 'paid_on' => '', 'reference' => '', 'cheque_no' => '', 'bank_name' => '', 'cheque_date' => ''];

    public function refundCredit(RecordDisbursement $record): void
    {
        try {
            $out = $record->handle($this->actor(), [...$this->refund, 'purpose' => 'credit_refund', 'payment_id' => $this->paymentId]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["refund.$k" => $m])->all());
        }

        $this->redirectRoute('disbursements.show', $out, navigate: true);
    }
```
set `$this->refund['paid_on'] = $this->refund['cheque_date'] = now('Asia/Bahrain')->toDateString();` in `mount()`, and add to the view data `'canRefund' => $this->actor()->can('create', Disbursement::class) && $payment->unallocatedFils() > 0,`. In `resources/views/livewire/payments/show.blade.php` add next to the receipt button:
```blade
            @if ($canRefund)
                <flux:modal.trigger name="refund-credit"><flux:button>{{ __('Refund credit') }}</flux:button></flux:modal.trigger>
            @endif
```
and before `</section>`:
```blade
    <flux:modal name="refund-credit" class="md:w-96">
        <form wire:submit="refundCredit" class="space-y-4">
            <flux:heading size="lg">{{ __('Refund credit — up to :c BHD', ['c' => $credit]) }}</flux:heading>
            <flux:input wire:model="refund.amount" inputmode="decimal" :label="__('Amount (BHD)')" />
            <flux:select wire:model.live="refund.method" :label="__('Method')">
                @foreach (\App\Enums\DisbursementMethod::cases() as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
            </flux:select>
            <flux:input wire:model="refund.paid_on" type="date" :label="__('Paid on')" />
            <flux:input wire:model="refund.reference" :label="__('Reference')" />
            @if (($refund['method'] ?? '') === 'cheque')
                <flux:input wire:model="refund.cheque_no" :label="__('Cheque no.')" />
                <flux:input wire:model="refund.bank_name" :label="__('Bank')" />
                <flux:input wire:model="refund.cheque_date" type="date" :label="__('Cheque date')" />
            @endif
            @foreach (['amount', 'method', 'paid_on', 'reference', 'cheque_no', 'bank_name', 'cheque_date'] as $f)<flux:error name="refund.{{ $f }}" />@endforeach
            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="refundCredit">{{ __('Pay refund') }}</flux:button>
        </form>
    </flux:modal>
```
In `app/Approvals/PaymentOut.php` and `PaymentOutReversal.php` change `url()` to `return route('disbursements.show', $approval->approvable_id);`. In `app/Billing/CustomerStatement.php` change the two refund rows' `'url' => null` to `'url' => route('disbursements.show', $d)`.

In `routes/property.php` add `use App\Livewire\Disbursements;` and:
```php
    Route::livewire('disbursements', Disbursements\Index::class)->middleware('can:finance.view')->name('disbursements.index');
    Route::livewire('disbursements/create', Disbursements\Create::class)->middleware('can:disbursements.manage')->name('disbursements.create');
    Route::livewire('disbursements/{disbursement}', Disbursements\Show::class)->middleware('can:finance.view')->name('disbursements.show');
```
Sidebar Finance group, after Cheques:
```blade
                        <flux:sidebar.item icon="arrow-up-right" :href="route('disbursements.index')" :current="request()->routeIs('disbursements.*')" wire:navigate>{{ __('Payments out') }}</flux:sidebar.item>
```

- [ ] **Step 4: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Disbursements tests/Feature/Payments tests/Feature/Customers
"$PHP" artisan test
grep -rn "panel:documentable" resources/views   # must print nothing
```
Expected: every test passes.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the payments-out screens

Finance refunds credit from a payment's page, requests a one-off payment
out for approval, records approved ones as paid (by cheque if needed),
marks the company's cheques cleared, and asks for reversals.
EOF
```

---

### Task 5: Deposit settlements

**Spec:** §7.7 (deposit settlements: draft when a unit's occupancy ends, or by a renewal that leaves deposit; Finance completes deductions each with a reason; Management approves, §8.3 item 4; on approval in one transaction: non-rent deductions become one issued manual invoice stamped by §4.6 on the move-out date; under lock re-read held and unpaid_rent balances and cap; `applied` movement per unit = min(held, that unit's deductions); one `deposit_applied` payment for Σ applied allocated directly to the named unpaid_rent lines, then to the deductions invoice; credit auto-allocation suppressed; refund per unit = held − applied; deductions beyond the deposit stay owed), §7.6 (`applied` copies `owner_contract_id`), §7.2 (deposit settlements allocate directly to named lines), §8.5 (no DELETE on deposit_settlements; units and lines frozen once the parent is not draft), §6.8 (DS number on approval)

**Files:**
- Create: `app/Enums/{DepositSettlementStatus,DeductionType}.php`, `database/migrations/2026_10_20_000300_create_deposit_settlements_tables.php`, `app/Models/{DepositSettlement,DepositSettlementUnit,DepositSettlementLine}.php`, `app/Policies/DepositSettlementPolicy.php`, `app/Actions/Deposits/{CreateDepositSettlement,SaveSettlementDeductions,SubmitDepositSettlement,ApproveDepositSettlement}.php`, `app/Approvals/DepositSettlementApproval.php`, `tests/Feature/Deposits/DepositSettlementTest.php`, `tests/Feature/Deposits/DepositSettlementTriggersTest.php`
- Modify: `app/Actions/Payments/ApplyToInvoices.php`, `app/Models/DepositMovement.php`, `app/Models/Disbursement.php`, `app/Enums/ApprovalAction.php`, `app/Integrity/IntegrityCheck.php`

**Interfaces:**
- Consumes: `PostPayment`, `IssueInvoice(…, autoAllocate: false)`, `DepositMovement`, `NextDocumentNumber` + `NumberSequenceKey::DepositSettlement`, `Unit::effectiveTaxCategory()`
- Produces:
  - `DepositSettlementStatus` (`Draft`, `PendingApproval`, `Approved`, `Completed`; `label()`), `DeductionType` (`Damage`, `Cleaning`, `Utilities`, `UnpaidRent`, `Other`; `label()`, `chargeType(): InvoiceChargeType`)
  - `DepositSettlement` (`agreement()`, `units()`, `lines()`, `deductionsInvoice()`, `payment()`, `refundFils(): int`, `refundedFils(): int`, `label()`), `DepositSettlementUnit` (`agreementUnit()`), `DepositSettlementLine` (`agreementUnit()`, `invoiceLine()`)
  - `CreateDepositSettlement::handle(Agreement $agreement, array $agreementUnitIds, User $actor): ?DepositSettlement` — internal; skips units already in a settlement; null when none is left
  - `SaveSettlementDeductions::handle(User $actor, DepositSettlement $draft, array $lines): DepositSettlement` — lines: `agreement_unit_id`, `type`, `description`, `amount`, `invoice_line_id` (unpaid_rent only)
  - `SubmitDepositSettlement::handle(User $actor, DepositSettlement $draft): Approval`
  - `ApproveDepositSettlement::handle(DepositSettlement $pending, User $approver): void` — internal (approval transaction)
  - `ApplyToInvoices::toLines(Payment $payment, array $lineAmounts, User $actor): int` — `$lineAmounts` = `array<int $invoiceLineId, int $fils>`; locks lines ascending then their invoices; same row-writing as `handle()`
  - `DepositMovement::heldFils(int $agreementUnitId): int`, `DepositMovement::ownerContractFor(int $agreementUnitId): ?int`
  - `ApprovalAction::DepositSettlement` (`'deposit_settlement.approve'`) → `App\Approvals\DepositSettlementApproval`
  - `DepositSettlementPolicy::{view, update, create}` (`update` = `invoices.manage` + draft; view = `finance.view`)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Deposits/DepositSettlementTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Deposits\CreateDepositSettlement;
use App\Actions\Deposits\SaveSettlementDeductions;
use App\Actions\Deposits\SubmitDepositSettlement;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Billing\CustomerCredit;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true, 'vat_registered' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30'], [Unit::factory()->create()]);
    $this->au = $this->agreement->agreementUnits()->sole();
    // Deposit 400 paid in full; September rent 400 still unpaid.
    issuedInvoice($this->customer, [['net' => '400.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => $this->au]], '2025-10-01', $this->agreement);
    app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '400.000']);
    $this->rent = issuedInvoice($this->customer, [['net' => '400.000', 'au' => $this->au]], '2026-09-01', $this->agreement);
    $this->au->forceFill(['move_out_date' => '2026-09-30'])->save();
    $this->settlement = DB::transaction(fn () => app(CreateDepositSettlement::class)->handle($this->agreement, [$this->au->id], $this->finance));
});

test('a draft lists each unit with the deposit held, once', function () {
    expect($this->settlement->status->value)->toBe('draft')
        ->and($this->settlement->units->sole()->held_amount)->toBe('400.000')
        ->and(DB::transaction(fn () => app(CreateDepositSettlement::class)->handle($this->agreement, [$this->au->id], $this->finance)))->toBeNull();
});

test('approval invoices the non-rent deductions, applies the deposit to them and the named rent, and leaves the refund', function () {
    $line = $this->rent->lines->sole();
    app(SaveSettlementDeductions::class)->handle($this->finance, $this->settlement, [
        ['agreement_unit_id' => $this->au->id, 'type' => 'unpaid_rent', 'description' => 'September rent', 'amount' => '250.000', 'invoice_line_id' => $line->id],
        ['agreement_unit_id' => $this->au->id, 'type' => 'cleaning', 'description' => 'Deep clean', 'amount' => '60.000'],
    ]);
    app(DecideApproval::class)->handle($this->management, app(SubmitDepositSettlement::class)->handle($this->finance, $this->settlement->fresh()), true);

    $s = $this->settlement->fresh(['units', 'deductionsInvoice', 'payment']);
    expect($s->status->value)->toBe('approved')
        ->and($s->number)->toBe('DS-2026-000001')
        ->and($s->deductionsInvoice->status->value)->toBe('issued')
        ->and($s->deductionsInvoice->total)->toBe('60.000')
        ->and($s->deductionsInvoice->due_date->toDateString())->toBe('2026-09-30')
        ->and($s->deductionsInvoice->balance)->toBe('0.000')
        ->and($s->payment->method->value)->toBe('deposit_applied')
        ->and($s->payment->amount)->toBe('310.000')
        ->and($line->fresh()->allocated)->toBe('250.000')
        ->and($s->units->sole()->applied_amount)->toBe('310.000')
        ->and($s->units->sole()->refund_amount)->toBe('90.000')
        ->and($s->refundFils())->toBe(90_000)
        ->and(DepositMovement::heldFils($this->au->id))->toBe(90_000)
        ->and(DepositMovement::where('type', 'applied')->sole()->amount)->toBe('-310.000')
        ->and(CustomerCredit::fils($this->customer->id))->toBe(0)
        ->and(app(IntegrityCheck::class)->run())->toBe([]);
});

test('deductions beyond the deposit are capped by the held amount and stay owed', function () {
    app(SaveSettlementDeductions::class)->handle($this->finance, $this->settlement, [
        ['agreement_unit_id' => $this->au->id, 'type' => 'unpaid_rent', 'description' => 'September rent', 'amount' => '400.000', 'invoice_line_id' => $this->rent->lines->sole()->id],
        ['agreement_unit_id' => $this->au->id, 'type' => 'damage', 'description' => 'Broken door', 'amount' => '150.000'],
    ]);
    app(DecideApproval::class)->handle($this->management, app(SubmitDepositSettlement::class)->handle($this->finance, $this->settlement->fresh()), true);

    $s = $this->settlement->fresh(['units', 'deductionsInvoice']);
    expect($s->units->sole()->applied_amount)->toBe('400.000')
        ->and($s->units->sole()->refund_amount)->toBe('0.000')
        ->and($s->status->value)->toBe('completed') // nothing to refund
        ->and($this->rent->fresh()->balance)->toBe('0.000')
        ->and($s->deductionsInvoice->balance)->toBe('150.000')
        ->and(DepositMovement::heldFils($this->au->id))->toBe(0);
});

test('no deductions: the whole deposit is the refund and no payment is made', function () {
    app(DecideApproval::class)->handle($this->management, app(SubmitDepositSettlement::class)->handle($this->finance, $this->settlement->fresh()), true);

    $s = $this->settlement->fresh(['units']);
    expect($s->status->value)->toBe('approved')
        ->and($s->payment_id)->toBeNull()
        ->and($s->deductions_invoice_id)->toBeNull()
        ->and($s->units->sole()->refund_amount)->toBe('400.000');
});

test('deductions are validated; only invoices.manage edits; rejecting returns the draft', function () {
    $other = issuedInvoice(Customer::factory()->create(), [['net' => '1.000']]);
    foreach ([
        [['agreement_unit_id' => $this->au->id, 'type' => 'unpaid_rent', 'description' => 'x', 'amount' => '1', 'invoice_line_id' => $other->lines->sole()->id]],
        [['agreement_unit_id' => $this->au->id, 'type' => 'unpaid_rent', 'description' => 'x', 'amount' => '400.001', 'invoice_line_id' => $this->rent->lines->sole()->id]],
        [['agreement_unit_id' => $this->au->id, 'type' => 'damage', 'description' => '', 'amount' => '1']],
        [['agreement_unit_id' => 999999, 'type' => 'damage', 'description' => 'x', 'amount' => '1']],
    ] as $bad) {
        expect(fn () => app(SaveSettlementDeductions::class)->handle($this->finance, $this->settlement, $bad))->toThrow(ValidationException::class);
    }

    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    expect(fn () => app(SaveSettlementDeductions::class)->handle($pm, $this->settlement, []))->toThrow(AuthorizationException::class);

    $approval = app(SubmitDepositSettlement::class)->handle($this->finance, $this->settlement);
    app(DecideApproval::class)->handle($this->management, $approval, false, 'Add the cleaning');
    expect($this->settlement->fresh()->status->value)->toBe('draft');
});
```

Create `tests/Feature/Deposits/DepositSettlementTriggersTest.php`:
```php
<?php

use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $agreement = activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->create()]);
    $this->auId = $agreement->agreementUnits()->value('id');
    $this->id = DB::table('deposit_settlements')->insertGetId(['agreement_id' => $agreement->id, 'status' => 'draft', 'created_by' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('deposit_settlement_units')->insert(['deposit_settlement_id' => $this->id, 'agreement_unit_id' => $this->auId, 'held_amount' => '0.000', 'created_at' => now(), 'updated_at' => now()]);
});

test('settlements are never deleted; units and lines freeze once submitted; status moves along', function () {
    expect(fn () => DB::table('deposit_settlements')->where('id', $this->id)->delete())->toThrow(QueryException::class, 'deposit_settlements cannot be deleted');
    expect(fn () => DB::table('deposit_settlements')->where('id', $this->id)->update(['status' => 'completed']))
        ->toThrow(QueryException::class, 'deposit_settlements: status change not allowed'); // a draft cannot jump to completed

    DB::table('deposit_settlements')->where('id', $this->id)->update(['status' => 'pending_approval']);
    expect(fn () => DB::table('deposit_settlement_lines')->insert(['deposit_settlement_id' => $this->id, 'agreement_unit_id' => $this->auId, 'type' => 'damage', 'description' => 'x', 'amount' => '1.000', 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class, 'deposit_settlement_lines are frozen once submitted');
    expect(fn () => DB::table('deposit_settlement_units')->where('deposit_settlement_id', $this->id)->delete())
        ->toThrow(QueryException::class, 'deposit_settlement_units are frozen once submitted');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Deposits`
Expected: FAIL — `Base table or view not found: … deposit_settlements`.

- [ ] **Step 3: Enums and migration**

Create `app/Enums/DepositSettlementStatus.php`:
```php
<?php

namespace App\Enums;

enum DepositSettlementStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Completed = 'completed';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
```
Create `app/Enums/DeductionType.php`:
```php
<?php

namespace App\Enums;

enum DeductionType: string
{
    case Damage = 'damage';
    case Cleaning = 'cleaning';
    case Utilities = 'utilities';
    case UnpaidRent = 'unpaid_rent';
    case Other = 'other';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }

    /** The deductions-invoice line type (spec §7.7 step 1). Unpaid rent is never invoiced again. */
    public function chargeType(): InvoiceChargeType
    {
        return match ($this) {
            self::Damage => InvoiceChargeType::Damage,
            self::Cleaning => InvoiceChargeType::Cleaning,
            self::Utilities => InvoiceChargeType::Utilities,
            self::Other, self::UnpaidRent => InvoiceChargeType::Other,
        };
    }
}
```
Create `database/migrations/2026_10_20_000300_create_deposit_settlements_tables.php`:
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
        Schema::create('deposit_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('agreement_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->foreignId('deductions_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE deposit_settlements
                ADD CONSTRAINT deposit_settlements_status_chk CHECK (status IN ('draft', 'pending_approval', 'approved', 'completed')),
                ADD CONSTRAINT deposit_settlements_number_chk CHECK ((status IN ('approved', 'completed')) = (number IS NOT NULL)
                    AND (status IN ('approved', 'completed')) = (approved_at IS NOT NULL))
            SQL);

        Schema::create('deposit_settlement_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_settlement_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_unit_id')->unique()->constrained()->restrictOnDelete(); // one settlement per agreement unit
            $table->decimal('held_amount', 12, 3);
            $table->decimal('applied_amount', 12, 3)->nullable();
            $table->decimal('refund_amount', 12, 3)->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE deposit_settlement_units
                ADD CONSTRAINT deposit_settlement_units_money_chk CHECK (held_amount >= 0
                    AND (applied_amount IS NULL OR applied_amount >= 0) AND (refund_amount IS NULL OR refund_amount >= 0)
                    AND (applied_amount IS NULL) = (refund_amount IS NULL))
            SQL);

        Schema::create('deposit_settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_settlement_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_unit_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('description', 255);
            $table->decimal('amount', 12, 3);
            $table->foreignId('invoice_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE deposit_settlement_lines
                ADD CONSTRAINT deposit_settlement_lines_type_chk CHECK (type IN ('damage', 'cleaning', 'utilities', 'unpaid_rent', 'other')),
                ADD CONSTRAINT deposit_settlement_lines_amount_chk CHECK (amount > 0),
                ADD CONSTRAINT deposit_settlement_lines_rent_chk CHECK ((type = 'unpaid_rent') = (invoice_line_id IS NOT NULL))
            SQL);

        DB::unprepared("CREATE TRIGGER deposit_settlements_no_delete BEFORE DELETE ON deposit_settlements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'deposit_settlements cannot be deleted'");

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER deposit_settlements_guard BEFORE UPDATE ON deposit_settlements FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'draft' AND NEW.status = 'pending_approval')
                    OR (OLD.status = 'pending_approval' AND NEW.status IN ('draft', 'approved', 'completed'))
                    OR (OLD.status = 'approved' AND NEW.status = 'completed')
                    OR (OLD.status = 'completed' AND NEW.status = 'approved')) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'deposit_settlements: status change not allowed';
                END IF;
                IF NOT (NEW.agreement_id <=> OLD.agreement_id AND NEW.created_by <=> OLD.created_by)
                    OR (OLD.number IS NOT NULL AND NOT (NEW.number <=> OLD.number AND NEW.approved_at <=> OLD.approved_at
                        AND NEW.deductions_invoice_id <=> OLD.deductions_invoice_id AND NEW.payment_id <=> OLD.payment_id)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'deposit_settlements: an approved settlement is frozen';
                END IF;
            END
            DDL);

        // Children: writable only while the parent is draft; units also take their applied and refund amounts while
        // the parent is pending approval (the approval writes them before flipping the status). Spec §8.5.
        foreach (['units', 'lines'] as $child) {
            $table = "deposit_settlement_{$child}";
            foreach (['INSERT' => 'NEW', 'UPDATE' => 'OLD', 'DELETE' => 'OLD'] as $event => $row) {
                $name = "{$table}_".strtolower($event);
                $allowApproval = $child === 'units' && $event === 'UPDATE'
                    ? "AND NOT (s = 'pending_approval' AND NEW.agreement_unit_id <=> OLD.agreement_unit_id)"
                    : '';
                $moveGuard = $event === 'UPDATE'
                    ? "IF NOT (NEW.deposit_settlement_id <=> OLD.deposit_settlement_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table}: rows cannot move to another settlement'; END IF;"
                    : '';
                DB::unprepared(<<<DDL
                    CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW
                    BEGIN
                        DECLARE s VARCHAR(20);
                        SELECT status INTO s FROM deposit_settlements WHERE id = {$row}.deposit_settlement_id;
                        IF s <> 'draft' {$allowApproval} THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} are frozen once submitted';
                        END IF;
                        {$moveGuard}
                    END
                    DDL);
            }
        }
    }

    public function down(): void
    {
        foreach (['units', 'lines'] as $child) {
            foreach (['insert', 'update', 'delete'] as $event) {
                DB::unprepared("DROP TRIGGER IF EXISTS deposit_settlement_{$child}_{$event}");
            }
        }
        DB::unprepared('DROP TRIGGER IF EXISTS deposit_settlements_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS deposit_settlements_no_delete');
        Schema::dropIfExists('deposit_settlement_lines');
        Schema::dropIfExists('deposit_settlement_units');
        Schema::dropIfExists('deposit_settlements');
    }
};
```
`EXPECTED_TRIGGERS` 40 → **48** (`+ 8 (deposit settlements)`). The `pending_approval → completed` transition covers an approval that leaves nothing to refund.

- [ ] **Step 4: Models, policy, movement helpers**

Create `app/Models/DepositSettlement.php`:
```php
<?php

namespace App\Models;

use App\Enums\DepositSettlementStatus;
use App\Enums\DisbursementPurpose;
use App\Enums\DisbursementStatus;
use App\Support\Fils;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec §7.7.
 *
 * @property int $id
 * @property string|null $number
 * @property int $agreement_id
 * @property DepositSettlementStatus $status
 * @property int|null $deductions_invoice_id
 * @property int|null $payment_id
 * @property int $created_by
 */
class DepositSettlement extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => DepositSettlementStatus::class, 'approved_at' => 'immutable_datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['number', 'status', 'deductions_invoice_id', 'payment_id'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Agreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /** @return HasMany<DepositSettlementUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(DepositSettlementUnit::class);
    }

    /** @return HasMany<DepositSettlementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DepositSettlementLine::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function deductionsInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'deductions_invoice_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Σ refund per unit, set on approval (spec §7.7 step 3). */
    public function refundFils(): int
    {
        return $this->units()->get()->sum(fn (DepositSettlementUnit $u) => Fils::fromDecimal((string) ($u->refund_amount ?? '0')));
    }

    /** Σ non-reversed deposit refunds paid against this settlement. */
    public function refundedFils(): int
    {
        return Fils::fromDecimal((string) (Disbursement::query()
            ->where('source_type', Disbursement::SOURCE_SETTLEMENT)->where('source_id', $this->id)
            ->where('purpose', DisbursementPurpose::DepositRefund)->where('status', DisbursementStatus::Paid)
            ->sum('amount') ?: '0'));
    }

    public function label(): string
    {
        return $this->number ?? __(':status #:id', ['status' => $this->status->label(), 'id' => $this->id]);
    }
}
```
Create `app/Models/DepositSettlementUnit.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $deposit_settlement_id
 * @property int $agreement_unit_id
 * @property string $held_amount
 * @property string|null $applied_amount
 * @property string|null $refund_amount
 */
class DepositSettlementUnit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['held_amount' => 'decimal:3', 'applied_amount' => 'decimal:3', 'refund_amount' => 'decimal:3'];
    }

    /** @return BelongsTo<AgreementUnit, $this> */
    public function agreementUnit(): BelongsTo
    {
        return $this->belongsTo(AgreementUnit::class);
    }
}
```
Create `app/Models/DepositSettlementLine.php`:
```php
<?php

namespace App\Models;

use App\Enums\DeductionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $deposit_settlement_id
 * @property int $agreement_unit_id
 * @property DeductionType $type
 * @property string $description
 * @property string $amount
 * @property int|null $invoice_line_id
 */
class DepositSettlementLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => DeductionType::class, 'amount' => 'decimal:3'];
    }

    /** @return BelongsTo<AgreementUnit, $this> */
    public function agreementUnit(): BelongsTo
    {
        return $this->belongsTo(AgreementUnit::class);
    }

    /** @return BelongsTo<InvoiceLine, $this> */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }
}
```
In `app/Models/DepositMovement.php` add (import `App\Support\Fils`):
```php
    /** Deposit held per agreement unit = Σ amount (spec §7.6). */
    public static function heldFils(int $agreementUnitId): int
    {
        return Fils::fromDecimal((string) (self::query()->where('agreement_unit_id', $agreementUnitId)->sum('amount') ?: '0'));
    }

    /** Spec §7.6, plan ruling 8: applied, refunded and transfer_out copy the stamp of the unit's latest positive movement. */
    public static function ownerContractFor(int $agreementUnitId): ?int
    {
        return self::query()->where('agreement_unit_id', $agreementUnitId)->where('amount', '>', 0)
            ->whereIn('type', ['received', 'opening', 'transfer_in'])->latest('id')->value('owner_contract_id');
    }
```
In `app/Models/Disbursement.php` replace the settlement arm of `source()` with `self::SOURCE_SETTLEMENT => DepositSettlement::query()->find($this->source_id),`.

Create `app/Policies/DepositSettlementPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\DepositSettlementStatus;
use App\Enums\PermissionName;
use App\Models\Agreement;
use App\Models\DepositSettlement;
use App\Models\User;

/** Spec §7.7: Finance completes the deductions; finance.view holders see settlements. */
class DepositSettlementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::FinanceView);
    }

    public function view(User $user, DepositSettlement $settlement): bool
    {
        return $user->can(PermissionName::FinanceView) && Agreement::visibleTo($user)->whereKey($settlement->agreement_id)->exists();
    }

    public function update(User $user, DepositSettlement $settlement): bool
    {
        return $user->can(PermissionName::InvoicesManage) && $settlement->status === DepositSettlementStatus::Draft && $this->view($user, $settlement);
    }
}
```

- [ ] **Step 5: Line-level allocation**

In `app/Actions/Payments/ApplyToInvoices.php`, move the body of the inner `foreach ($lines->whereIn(…) as $line)` loop into a private method and add `toLines()`:
```php
    /**
     * Spec §7.2, §7.7: allocate named amounts to named lines (deposit settlements). Locks the lines ascending, then their
     * invoices; the caller holds the customer lock.
     *
     * @param  array<int, int>  $lineAmounts  invoice line id => fils
     */
    public function toLines(Payment $payment, array $lineAmounts, User $actor): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ApplyToInvoices must run inside the caller\'s transaction.');
        }

        $lineAmounts = array_filter($lineAmounts, fn (int $f) => $f > 0);
        if (array_sum($lineAmounts) > $payment->unallocatedFils()) {
            throw new LogicException('Line allocations exceed the payment.');
        }

        $lines = InvoiceLine::query()->whereKey(array_keys($lineAmounts))->orderBy('id')->lockForUpdate()->get();
        $invoices = Invoice::query()->whereKey($lines->pluck('invoice_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $now = now();
        $perInvoice = [];

        foreach ($lines as $line) {
            $share = $lineAmounts[$line->id];
            if ($share > $line->balanceFils() || $invoices[$line->invoice_id]->customer_id !== $payment->customer_id) {
                throw new LogicException("Cannot allocate {$share} fils to line {$line->id}.");
            }
            $this->writeLine($payment, $line, $share, $actor, $now);
            $perInvoice[$line->invoice_id] = ($perInvoice[$line->invoice_id] ?? 0) + $share;
        }

        foreach ($perInvoice as $invoiceId => $fils) {
            $invoice = $invoices[$invoiceId];
            $invoice->forceFill(['allocated' => Fils::toDecimal(Fils::fromDecimal((string) $invoice->allocated) + $fils)])->save();
        }

        return array_sum($perInvoice);
    }

    /** One positive allocation row, the line's cache, and the deposit movement for a deposit line (spec §7.2, §7.6). */
    private function writeLine(Payment $payment, InvoiceLine $line, int $share, User $actor, \Carbon\CarbonInterface $now): void
    {
        $before = Fils::fromDecimal((string) $line->allocated);
        $tax = AllocationTax::between($before, $before + $share, Fils::fromDecimal($line->tax_amount), Fils::fromDecimal($line->total));

        $allocation = PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_line_id' => $line->id,
            'amount' => Fils::toDecimal($share),
            'tax_amount' => Fils::toDecimal($tax),
            'owner_contract_id' => $line->owner_contract_id,
            'posted_at' => $now,
            'created_by' => $actor->id,
        ]);

        $line->forceFill(['allocated' => Fils::toDecimal($before + $share)])->save();

        if ($line->charge_type === InvoiceChargeType::Deposit && $line->agreement_unit_id !== null) {
            DepositMovement::create([
                'agreement_unit_id' => $line->agreement_unit_id,
                'owner_contract_id' => $line->owner_contract_id,
                'type' => DepositMovementType::Received,
                'amount' => Fils::toDecimal($share),
                'source_type' => 'payment_allocation',
                'source_id' => $allocation->id,
                'posted_at' => $now,
            ]);
        }
    }
```
and replace the body of `handle()`'s inner loop with `$this->writeLine($payment, $line, $shares[$line->id], $actor, $now); $invoiceSum += $shares[$line->id];`. Behaviour of `handle()` is unchanged; run `tests/Feature/Payments` to prove it. Import `Carbon\CarbonInterface` rather than fully qualifying.

- [ ] **Step 6: The settlement Actions and handler**

Create `app/Actions/Deposits/CreateDepositSettlement.php`:
```php
<?php

namespace App\Actions\Deposits;

use App\Enums\DepositSettlementStatus;
use App\Models\Agreement;
use App\Models\DepositMovement;
use App\Models\DepositSettlement;
use App\Models\DepositSettlementUnit;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Spec §7.7: a draft when occupancy ends (move-out, 02:00 job) or a renewal leaves deposit behind. Idempotent per unit. */
final class CreateDepositSettlement
{
    /** @param  list<int>  $agreementUnitIds */
    public function handle(Agreement $agreement, array $agreementUnitIds, User $actor): ?DepositSettlement
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('CreateDepositSettlement must run inside the caller\'s transaction.');
        }

        $taken = DepositSettlementUnit::query()->whereIn('agreement_unit_id', $agreementUnitIds)->pluck('agreement_unit_id')->all();
        $ids = array_values(array_diff($agreementUnitIds, $taken));
        if ($ids === []) {
            return null;
        }

        $settlement = (new DepositSettlement)->forceFill([
            'agreement_id' => $agreement->id,
            'status' => DepositSettlementStatus::Draft,
            'created_by' => $actor->id,
        ]);
        $settlement->save();

        foreach ($ids as $id) {
            $settlement->units()->create(['agreement_unit_id' => $id, 'held_amount' => Fils::toDecimal(DepositMovement::heldFils($id))]);
        }

        return $settlement->load('units');
    }
}
```
The unique key on `deposit_settlement_units.agreement_unit_id` turns a concurrent duplicate into a constraint error that rolls the second transaction back; callers retry through `attempts: 3`, which then finds the unit taken.

Create `app/Actions/Deposits/SaveSettlementDeductions.php`:
```php
<?php

namespace App\Actions\Deposits;

use App\Enums\DeductionType;
use App\Enums\DepositSettlementStatus;
use App\Enums\InvoiceStatus;
use App\Models\DepositSettlement;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec §7.7: Finance completes the deductions, each with a reason (the description). Replaces the draft's lines. */
final class SaveSettlementDeductions
{
    /** @param  list<array<string, mixed>>  $lines */
    public function handle(User $actor, DepositSettlement $draft, array $lines): DepositSettlement
    {
        if (! $actor->can('update', $draft)) {
            throw new AuthorizationException;
        }

        $v = Validator::make(['lines' => $lines], [
            'lines' => ['present', 'array', 'max:50'],
            'lines.*.agreement_unit_id' => ['required', 'integer'],
            'lines.*.type' => ['required', Rule::enum(DeductionType::class)],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.amount' => ['required', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.invoice_line_id' => ['required_if:lines.*.type,unpaid_rent', 'nullable', 'integer'],
        ])->validate()['lines'];

        return DB::transaction(function () use ($draft, $v) {
            $settlement = DepositSettlement::query()->lockForUpdate()->with('units')->findOrFail($draft->id);
            if ($settlement->status !== DepositSettlementStatus::Draft) {
                throw ValidationException::withMessages(['lines' => __('Only a draft settlement can be edited.')]);
            }
            $unitIds = $settlement->units->pluck('agreement_unit_id')->all();

            foreach ($v as $i => $line) {
                if (! in_array((int) $line['agreement_unit_id'], $unitIds, true)) {
                    throw ValidationException::withMessages(["lines.$i.agreement_unit_id" => __('Choose a unit of this settlement.')]);
                }
                if ($line['type'] === DeductionType::UnpaidRent->value) {
                    $invoiceLine = InvoiceLine::query()->with('invoice')->find((int) $line['invoice_line_id']);
                    if ($invoiceLine === null || $invoiceLine->agreement_unit_id !== (int) $line['agreement_unit_id']
                        || $invoiceLine->invoice?->status !== InvoiceStatus::Issued) {
                        throw ValidationException::withMessages(["lines.$i.invoice_line_id" => __('Choose an issued invoice line of this unit.')]);
                    }
                    if (Fils::fromDecimal((string) $line['amount']) > $invoiceLine->balanceFils()) {
                        throw ValidationException::withMessages(["lines.$i.amount" => __('At most :b BHD is unpaid on that line.', ['b' => Fils::toDecimal($invoiceLine->balanceFils())])]);
                    }
                }
            }

            $settlement->lines()->delete(); // draft: the trigger allows it
            foreach ($v as $line) {
                $settlement->lines()->create([
                    'agreement_unit_id' => (int) $line['agreement_unit_id'],
                    'type' => $line['type'],
                    'description' => $line['description'],
                    'amount' => Fils::toDecimal(Fils::fromDecimal((string) $line['amount'])),
                    'invoice_line_id' => $line['type'] === DeductionType::UnpaidRent->value ? (int) $line['invoice_line_id'] : null,
                ]);
            }
            // Re-read the held amounts too: payments may have landed since the draft was made.
            foreach ($settlement->units as $unit) {
                $unit->forceFill(['held_amount' => Fils::toDecimal(\App\Models\DepositMovement::heldFils($unit->agreement_unit_id))])->save();
            }

            return $settlement->load(['units', 'lines']);
        }, attempts: 3);
    }
}
```
(import `App\Models\DepositMovement`.)

Create `app/Actions/Deposits/SubmitDepositSettlement.php`:
```php
<?php

namespace App\Actions\Deposits;

use App\Actions\Approvals\RequestApproval;
use App\Enums\ApprovalAction;
use App\Enums\DepositSettlementStatus;
use App\Models\Approval;
use App\Models\DepositSettlement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Spec §7.7, §8.3 item 4. */
final class SubmitDepositSettlement
{
    public function __construct(private RequestApproval $request) {}

    public function handle(User $actor, DepositSettlement $draft): Approval
    {
        return DB::transaction(function () use ($actor, $draft) {
            $settlement = DepositSettlement::query()->lockForUpdate()->findOrFail($draft->id);
            if (! $actor->can('update', $settlement)) {
                throw new AuthorizationException;
            }
            $settlement->forceFill(['status' => DepositSettlementStatus::PendingApproval])->save();

            return $this->request->handle($actor, $settlement, ApprovalAction::DepositSettlement);
        }, attempts: 3);
    }
}
```
Create `app/Actions/Deposits/ApproveDepositSettlement.php`:
```php
<?php

namespace App\Actions\Deposits;

use App\Actions\Billing\IssueInvoice;
use App\Actions\NextDocumentNumber;
use App\Actions\Payments\ApplyToInvoices;
use App\Actions\Payments\PostPayment;
use App\Enums\DeductionType;
use App\Enums\DepositMovementType;
use App\Enums\DepositSettlementStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\NumberSequenceKey;
use App\Enums\PaymentMethod;
use App\Models\AgreementUnit;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\DepositSettlement;
use App\Models\DepositSettlementLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Spec §7.7 on approval, in one transaction. Locks: customer → settlement → (deductions invoice, new) → named lines →
 * invoices → payment, the global order. Credit auto-allocation is suppressed: the deductions invoice issues with
 * autoAllocate false, and the deposit_applied payment is allocated explicitly.
 */
final class ApproveDepositSettlement
{
    public function __construct(
        private IssueInvoice $issue,
        private PostPayment $post,
        private ApplyToInvoices $apply,
        private NextDocumentNumber $next,
    ) {}

    public function handle(DepositSettlement $pending, User $approver): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ApproveDepositSettlement must run inside the caller\'s transaction.');
        }

        $agreement = $pending->agreement()->firstOrFail();
        $customer = Customer::query()->lockForUpdate()->findOrFail($agreement->customer_id);
        $settlement = DepositSettlement::query()->lockForUpdate()->with(['units', 'lines'])->findOrFail($pending->id);
        if ($settlement->status !== DepositSettlementStatus::PendingApproval) {
            throw ValidationException::withMessages(['approval' => __('This settlement is no longer waiting for approval.')]);
        }

        // 1. Non-rent deductions → one issued manual invoice, attributed on the unit's last day (plan ruling 7).
        $invoice = $this->deductionsInvoice($settlement, $agreement->id, $customer->id, $approver);
        $deductionLines = $invoice ? InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('id')->get() : collect();

        // 2. Per unit: held, capped deductions, applied (re-read under the locks above).
        $plan = [];
        $applied = [];
        foreach ($settlement->units as $unit) {
            $held = DepositMovement::heldFils($unit->agreement_unit_id);
            $left = $held;
            $rentLines = $settlement->lines->where('agreement_unit_id', $unit->agreement_unit_id)->where('type', DeductionType::UnpaidRent);
            foreach ($rentLines as $line) {
                $invoiceLine = InvoiceLine::query()->findOrFail($line->invoice_line_id);
                $take = min($left, Fils::fromDecimal($line->amount), $invoiceLine->balanceFils());
                if ($take > 0) {
                    $plan[$invoiceLine->id] = ($plan[$invoiceLine->id] ?? 0) + $take;
                    $left -= $take;
                }
            }
            foreach ($deductionLines->where('agreement_unit_id', $unit->agreement_unit_id) as $invoiceLine) {
                $take = min($left, $invoiceLine->balanceFils());
                if ($take > 0) {
                    $plan[$invoiceLine->id] = ($plan[$invoiceLine->id] ?? 0) + $take;
                    $left -= $take;
                }
            }
            $applied[$unit->id] = [$unit, $held, $held - $left];
        }

        // 3. One deposit_applied payment for Σ applied, allocated to the named lines.
        $total = array_sum(array_column($applied, 2));
        $payment = null;
        if ($total > 0) {
            $payment = $this->post->handle($customer, [
                'received_on' => now('Asia/Bahrain')->toDateString(),
                'method' => PaymentMethod::DepositApplied,
                'amount' => Fils::toDecimal($total),
                'reference' => __('Deposit settlement'),
            ], [], $approver);
            $this->apply->toLines($payment, $plan, $approver);
        }

        // 4. applied movements, per-unit amounts, number and status.
        $now = now();
        $refund = 0;
        foreach ($applied as [$unit, $held, $use]) {
            if ($use > 0) {
                DepositMovement::create([
                    'agreement_unit_id' => $unit->agreement_unit_id,
                    'owner_contract_id' => DepositMovement::ownerContractFor($unit->agreement_unit_id),
                    'type' => DepositMovementType::Applied,
                    'amount' => Fils::toDecimal(-$use),
                    'source_type' => 'deposit_settlement',
                    'source_id' => $settlement->id,
                    'posted_at' => $now,
                ]);
            }
            $unit->forceFill(['held_amount' => Fils::toDecimal($held), 'applied_amount' => Fils::toDecimal($use), 'refund_amount' => Fils::toDecimal($held - $use)])->save();
            $refund += $held - $use;
        }

        $settlement->forceFill([
            'number' => ($this->next)(NumberSequenceKey::DepositSettlement),
            'approved_at' => $now,
            'deductions_invoice_id' => $invoice?->id,
            'payment_id' => $payment?->id,
            'status' => $refund === 0 ? DepositSettlementStatus::Completed : DepositSettlementStatus::Approved,
        ])->save();
    }

    private function deductionsInvoice(DepositSettlement $settlement, int $agreementId, int $customerId, User $approver): ?Invoice
    {
        $lines = $settlement->lines->filter(fn (DepositSettlementLine $l) => $l->type !== DeductionType::UnpaidRent)->values();
        if ($lines->isEmpty()) {
            return null;
        }

        $aus = AgreementUnit::query()->with('unit')->whereKey($lines->pluck('agreement_unit_id'))->get()->keyBy('id');
        $lastDay = $aus->map(fn (AgreementUnit $au) => $au->move_out_date && $au->move_out_date->greaterThan($au->end_date) ? $au->move_out_date : $au->end_date)->max();
        $net = $lines->sum(fn (DepositSettlementLine $l) => Fils::fromDecimal($l->amount));

        $invoice = (new Invoice)->forceFill([
            'type' => InvoiceType::Manual,
            'customer_id' => $customerId,
            'agreement_id' => $agreementId,
            'issue_date' => now('Asia/Bahrain')->toDateString(),
            'due_date' => $lastDay->toDateString(), // the §4.6 attribution date for these lines
            'status' => InvoiceStatus::Draft,
            'subtotal' => Fils::toDecimal($net),
            'tax_total' => '0.000',
            'total' => Fils::toDecimal($net),
            'created_by' => $approver->id,
        ]);
        $invoice->save();

        foreach ($lines as $l) {
            $au = $aus[$l->agreement_unit_id];
            $invoice->lines()->create([
                'agreement_unit_id' => $au->id,
                'unit_id' => $au->unit_id,
                'charge_type' => $l->type->chargeType(),
                'description' => $l->description,
                'net' => $l->amount,
                'tax_category' => $au->unit->effectiveTaxCategory(),
                'tax_rate' => '0.00',
                'tax_amount' => '0.000',
                'total' => $l->amount,
            ]);
        }

        if (! $this->issue->handle($invoice, $approver, autoAllocate: false)) {
            throw ValidationException::withMessages(['approval' => __('The deductions invoice is held back: an owner contract covering the unit is waiting for approval.')]);
        }

        return $invoice->refresh();
    }
}
```
When the company is VAT-registered and the unit's category is standard, `IssueInvoice` adds tax on top of each deduction: the deposit then covers the gross. The tests run unregistered so the figures stay round.

Create `app/Approvals/DepositSettlementApproval.php`:
```php
<?php

namespace App\Approvals;

use App\Actions\Deposits\ApproveDepositSettlement;
use App\Enums\DepositSettlementStatus;
use App\Models\Approval;
use App\Models\DepositSettlement;
use App\Models\User;
use App\Support\Fils;

/** Spec §8.3 item 4. */
final class DepositSettlementApproval implements ApprovalHandler
{
    public function __construct(private ApproveDepositSettlement $approve) {}

    public function creatorId(Approval $approval): int
    {
        return DepositSettlement::query()->findOrFail($approval->approvable_id)->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $this->approve->handle(DepositSettlement::query()->findOrFail($approval->approvable_id), $approver);
    }

    public function reject(Approval $approval, User $approver): void
    {
        DepositSettlement::query()->lockForUpdate()->findOrFail($approval->approvable_id)
            ->forceFill(['status' => DepositSettlementStatus::Draft])->save();
    }

    public function summary(Approval $approval): string
    {
        $s = DepositSettlement::query()->with(['agreement.customer', 'units', 'lines'])->findOrFail($approval->approvable_id);
        $held = $s->units->sum(fn ($u) => Fils::fromDecimal($u->held_amount));
        $deductions = $s->lines->sum(fn ($l) => Fils::fromDecimal($l->amount));

        return __('Deposit settlement for :agreement (:customer): held :held BHD, deductions :ded BHD.', [
            'agreement' => $s->agreement->label(), 'customer' => $s->agreement->customer->name_en,
            'held' => Fils::toDecimal($held), 'ded' => Fils::toDecimal($deductions),
        ]);
    }

    public function url(Approval $approval): string
    {
        return route('approvals.index'); // Task 6 points this at the settlement page
    }
}
```

In `app/Enums/ApprovalAction.php` add `case DepositSettlement = 'deposit_settlement.approve';`, label `__('Deposit settlement')`, handler `\App\Approvals\DepositSettlementApproval::class`.

- [ ] **Step 7: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Deposits tests/Feature/Payments tests/Feature/Integrity
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add deposit settlements

A draft settlement lists each unit's deposit held. Finance adds the
deductions; on Management approval the non-rent ones are invoiced, the
deposit pays the named rent and those deductions (capped at what is
held), and what is left becomes the refund.
EOF
```

---

### Task 6: Deposit refunds and the settlement screens

**Spec:** §7.7 (Finance records the refund as a disbursement with source = the settlement, which writes the `refunded` movement; `completed` when the refund is paid, or immediately if zero), §7.5 (Σ non-reversed deposit refunds ≤ the settlement's refund), §7.3 (a deposit-refund reversal writes an opposite `refunded` movement and returns the settlement to `approved`), §7.6

**Files:**
- Create: `app/Livewire/DepositSettlements/{Index,Show}.php`, `resources/views/livewire/deposit-settlements/{index,show}.blade.php`, `tests/Feature/Deposits/DepositRefundTest.php`, `tests/Feature/Deposits/SettlementScreensTest.php`
- Modify: `app/Actions/Disbursements/RecordDisbursement.php`, `app/Actions/Disbursements/MarkDisbursementPaid.php`, `app/Approvals/PaymentOutReversal.php`, `app/Approvals/DepositSettlementApproval.php`, `routes/property.php`, `resources/views/layouts/app/sidebar.blade.php`, `resources/views/livewire/agreements/show.blade.php`, `app/Livewire/Agreements/Show.php`

**Interfaces:**
- Consumes: Task 5's settlements, Task 1–3's disbursements
- Produces: `RecordDisbursement` purpose `deposit_refund` with `deposit_settlement_id`; routes `deposit-settlements.index`, `deposit-settlements.show`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Deposits/DepositRefundTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Deposits\CreateDepositSettlement;
use App\Actions\Deposits\SubmitDepositSettlement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->customer = Customer::factory()->create();
    $agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30'], [Unit::factory()->create()]);
    $this->au = $agreement->agreementUnits()->sole();
    issuedInvoice($this->customer, [['net' => '400.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => $this->au]], '2025-10-01', $agreement);
    app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '400.000']);
    $this->settlement = DB::transaction(fn () => app(CreateDepositSettlement::class)->handle($agreement, [$this->au->id], $this->finance));
    app(DecideApproval::class)->handle($this->management, app(SubmitDepositSettlement::class)->handle($this->finance, $this->settlement), true);
    $this->pay = fn (string $amount) => app(RecordDisbursement::class)->handle($this->finance, [
        'purpose' => 'deposit_refund', 'deposit_settlement_id' => $this->settlement->id, 'amount' => $amount, 'method' => 'bank_transfer', 'paid_on' => '2026-10-05',
    ]);
});

test('refunds up to the settlement\'s refund write refunded movements and complete the settlement', function () {
    ($this->pay)('150.000');
    expect($this->settlement->fresh()->status->value)->toBe('approved')
        ->and(DepositMovement::heldFils($this->au->id))->toBe(250_000);

    $last = ($this->pay)('250.000');
    expect($last->payee_id)->toBe($this->customer->id)
        ->and($this->settlement->fresh()->status->value)->toBe('completed')
        ->and(DepositMovement::where('type', 'refunded')->count())->toBe(2)
        ->and(DepositMovement::heldFils($this->au->id))->toBe(0)
        ->and(app(IntegrityCheck::class)->run())->toBe([]);

    expect(fn () => ($this->pay)('0.001'))->toThrow(ValidationException::class);
});

test('reversing a deposit refund puts the deposit back and reopens the settlement', function () {
    $out = ($this->pay)('400.000');
    app(DecideApproval::class)->handle($this->management, app(RequestDisbursementReversal::class)->handle($this->finance, $out, 'Wrong account'), true);

    expect($this->settlement->fresh()->status->value)->toBe('approved')
        ->and(DepositMovement::heldFils($this->au->id))->toBe(400_000)
        ->and(DepositMovement::where('type', 'refunded')->where('amount', '>', 0)->count())->toBe(1);
});
```
Create `tests/Feature/Deposits/SettlementScreensTest.php`:
```php
<?php

use App\Actions\Deposits\CreateDepositSettlement;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\DepositSettlements\Index;
use App\Livewire\DepositSettlements\Show;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $agreement = activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30'], [Unit::factory()->create()]);
    $this->au = $agreement->agreementUnits()->sole();
    $this->settlement = DB::transaction(fn () => app(CreateDepositSettlement::class)->handle($agreement, [$this->au->id], $this->finance));
});

test('Finance adds deductions on the draft and sends it for approval', function () {
    Livewire::actingAs($this->finance)->test(Show::class, ['settlement' => $this->settlement])
        ->call('addLine')
        ->set('lines.0.agreement_unit_id', $this->au->id)->set('lines.0.type', 'cleaning')
        ->set('lines.0.description', 'Deep clean')->set('lines.0.amount', '35')
        ->call('saveLines')->assertHasNoErrors()
        ->call('submit')->assertHasNoErrors()
        ->assertSee('Pending Approval');

    expect($this->settlement->fresh()->lines()->sole()->amount)->toBe('35.000');
    Livewire::actingAs($this->finance)->test(Index::class)->assertSee($this->settlement->agreement->label());
});

test('view-only roles see settlements but cannot edit them', function () {
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->actingAs($management)->get(route('deposit-settlements.show', $this->settlement))->assertOk();
    Livewire::actingAs($management)->test(Show::class, ['settlement' => $this->settlement])->call('saveLines')->assertForbidden();
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Deposits`
Expected: FAIL — the `deposit_refund` purpose is refused by validation, and the screens do not exist.

- [ ] **Step 3: Deposit refunds**

In `RecordDisbursement::handle()`:
- widen the purpose rule to `Rule::in([DisbursementPurpose::CreditRefund->value, DisbursementPurpose::DepositRefund->value, DisbursementPurpose::Other->value])`;
- add `'deposit_settlement_id' => ['required_if:purpose,deposit_refund', 'nullable', 'integer', Rule::exists('deposit_settlements', 'id')],`;
- add the match arm `DisbursementPurpose::DepositRefund->value => $this->depositRefund($actor, $v, $amount),`;
- add the method (imports `DepositSettlement`, `DepositSettlementStatus`):
```php
    /** @param  array<string, mixed>  $v */
    private function depositRefund(User $actor, array $v, int $amount): Disbursement
    {
        $settlement = DepositSettlement::query()->with('agreement')->findOrFail((int) $v['deposit_settlement_id']);
        $customer = Customer::query()->lockForUpdate()->findOrFail($settlement->agreement->customer_id); // first lock
        if (! $actor->can('view', $settlement)) {
            throw new AuthorizationException;
        }
        $settlement = DepositSettlement::query()->lockForUpdate()->findOrFail($settlement->id);

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
```
In `MarkDisbursementPaid::handle()`, after the `forceFill(...)->save()` that marks it paid, replace the `Task 6 adds …` half of the comment with (imports `DepositMovement`, `DepositMovementType`, `DepositSettlement`, `DepositSettlementStatus`, `DisbursementPurpose`, `Fils`):
```php
        // Spec §7.7: a deposit refund writes refunded movements, unit by unit up to each unit's refund, and completes the
        // settlement once fully refunded.
        if ($locked->purpose === DisbursementPurpose::DepositRefund) {
            $settlement = DepositSettlement::query()->lockForUpdate()->with('units')->findOrFail($locked->source_id);
            $left = Fils::fromDecimal($locked->amount);
            foreach ($settlement->units as $unit) {
                $refundedHere = -Fils::fromDecimal((string) (DepositMovement::query()->where('agreement_unit_id', $unit->agreement_unit_id)
                    ->where('type', DepositMovementType::Refunded)->sum('amount') ?: '0'));
                $take = min($left, Fils::fromDecimal((string) $unit->refund_amount) - $refundedHere);
                if ($take > 0) {
                    DepositMovement::create([
                        'agreement_unit_id' => $unit->agreement_unit_id,
                        'owner_contract_id' => DepositMovement::ownerContractFor($unit->agreement_unit_id),
                        'type' => DepositMovementType::Refunded,
                        'amount' => Fils::toDecimal(-$take),
                        'source_type' => 'disbursement',
                        'source_id' => $locked->id,
                        'posted_at' => now(),
                    ]);
                    $left -= $take;
                }
            }
            if ($settlement->refundedFils() >= $settlement->refundFils()) {
                $settlement->forceFill(['status' => DepositSettlementStatus::Completed])->save();
            }
        }
```
The settlement row is locked after the disbursement here; the disbursement was created by the same transaction, and the reversal path (below) locks settlement before disbursement only through `reopen()` which runs before the disbursement lock — both orders hold a lock on the customer first, so they are serialised.

In `app/Approvals/PaymentOutReversal.php` replace `reopen()` with:
```php
    /** Spec §7.3: reopen the source. A credit refund needs nothing (its amount counts as credit again once reversed). */
    private function reopen(Disbursement $out, User $approver): void
    {
        if ($out->purpose !== DisbursementPurpose::DepositRefund) {
            return;
        }

        $settlement = DepositSettlement::query()->lockForUpdate()->findOrFail($out->source_id);
        foreach (DepositMovement::query()->where('source_type', 'disbursement')->where('source_id', $out->id)->where('type', DepositMovementType::Refunded)->get() as $m) {
            DepositMovement::create([
                'agreement_unit_id' => $m->agreement_unit_id,
                'owner_contract_id' => $m->owner_contract_id,
                'type' => DepositMovementType::Refunded,
                'amount' => Fils::toDecimal(-Fils::fromDecimal($m->amount)), // the opposite entry (spec §7.3)
                'source_type' => 'disbursement_reversal',
                'source_id' => $out->id,
                'posted_at' => now(),
            ]);
        }
        if ($settlement->status === DepositSettlementStatus::Completed) {
            $settlement->forceFill(['status' => DepositSettlementStatus::Approved])->save();
        }
    }
```
(imports.) The `refundedHere` sum in `MarkDisbursementPaid` counts reversal rows too (they are `refunded` with a positive amount), so a re-paid refund after a reversal is measured correctly.

- [ ] **Step 4: The screens**

Create `app/Livewire/DepositSettlements/Index.php`:
```php
<?php

namespace App\Livewire\DepositSettlements;

use App\Enums\DepositSettlementStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\Agreement;
use App\Models\DepositSettlement;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Deposit settlements')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $status = 'open';

    public function render(): View
    {
        $rows = DepositSettlement::query()
            ->whereIn('agreement_id', Agreement::query()->visibleTo($this->actor())->select('id'))
            ->when($this->status === 'open', fn ($q) => $q->whereIn('status', [DepositSettlementStatus::Draft, DepositSettlementStatus::PendingApproval, DepositSettlementStatus::Approved]))
            ->when(! in_array($this->status, ['open', 'all'], true), fn ($q) => $q->where('status', $this->status))
            ->with('agreement.customer')
            ->latest('id')->paginate(50);

        return view('livewire.deposit-settlements.index', ['rows' => $rows, 'statuses' => DepositSettlementStatus::cases()]);
    }
}
```
Create `resources/views/livewire/deposit-settlements/index.blade.php`:
```blade
<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">{{ __('Deposit settlements') }}</flux:heading>
    <flux:select wire:model.live="status" :label="__('Show')" class="max-w-48">
        <option value="open">{{ __('Open') }}</option>
        <option value="all">{{ __('All') }}</option>
        @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
    </flux:select>
    <div class="overflow-x-auto">
        <flux:table :paginate="$rows">
            <flux:table.columns>
                <flux:table.column>{{ __('Settlement') }}</flux:table.column>
                <flux:table.column>{{ __('Agreement') }}</flux:table.column>
                <flux:table.column>{{ __('Customer') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($rows as $row)
                    <flux:table.row :key="$row->id">
                        <flux:table.cell><flux:link :href="route('deposit-settlements.show', $row)" wire:navigate>{{ $row->label() }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $row->agreement->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $row->agreement->customer->name_en }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $row->status->label() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```
Create `app/Livewire/DepositSettlements/Show.php`:
```php
<?php

namespace App\Livewire\DepositSettlements;

use App\Actions\Deposits\SaveSettlementDeductions;
use App\Actions\Deposits\SubmitDepositSettlement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Enums\DeductionType;
use App\Enums\DisbursementMethod;
use App\Enums\InvoiceStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\DepositSettlement;
use App\Models\Disbursement;
use App\Models\InvoiceLine;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $settlementId;

    /** @var array<int, array<string, mixed>> */
    public array $lines = [];

    /** @var array<string, string> */
    public array $refund = ['amount' => '', 'method' => 'bank_transfer', 'paid_on' => '', 'reference' => '', 'cheque_no' => '', 'bank_name' => '', 'cheque_date' => ''];

    public function mount(DepositSettlement $settlement): void
    {
        abort_unless($this->actor()->can('view', $settlement), 403);
        $this->settlementId = $settlement->id;
        $this->lines = $settlement->lines()->get()->map(fn ($l) => [
            'agreement_unit_id' => $l->agreement_unit_id, 'type' => $l->type->value, 'description' => $l->description,
            'amount' => $l->amount, 'invoice_line_id' => $l->invoice_line_id,
        ])->all();
        $this->refund['paid_on'] = $this->refund['cheque_date'] = now('Asia/Bahrain')->toDateString();
    }

    private function settlement(): DepositSettlement
    {
        return DepositSettlement::with(['agreement.customer', 'units.agreementUnit.unit.building', 'deductionsInvoice', 'payment'])->findOrFail($this->settlementId);
    }

    public function addLine(): void
    {
        $this->lines[] = ['agreement_unit_id' => '', 'type' => 'damage', 'description' => '', 'amount' => '', 'invoice_line_id' => null];
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    public function saveLines(SaveSettlementDeductions $save): void
    {
        try {
            $save->handle($this->actor(), $this->settlement(), array_values($this->lines));
        } catch (AuthorizationException) {
            abort(403);
        }
        Flux::toast(variant: 'success', text: __('Deductions saved.'));
    }

    public function submit(SaveSettlementDeductions $save, SubmitDepositSettlement $submit): void
    {
        try {
            $save->handle($this->actor(), $this->settlement(), array_values($this->lines));
            $submit->handle($this->actor(), $this->settlement());
        } catch (AuthorizationException) {
            abort(403);
        }
        Flux::toast(variant: 'success', text: __('Sent for approval.'));
    }

    public function payRefund(RecordDisbursement $record): void
    {
        try {
            $out = $record->handle($this->actor(), [...$this->refund, 'purpose' => 'deposit_refund', 'deposit_settlement_id' => $this->settlementId]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["refund.$k" => $m])->all());
        }
        $this->redirectRoute('disbursements.show', $out, navigate: true);
    }

    public function render(): View
    {
        $s = $this->settlement();
        $auIds = $s->units->pluck('agreement_unit_id');

        return view('livewire.deposit-settlements.show', [
            's' => $s,
            'canEdit' => $this->actor()->can('update', $s),
            'canRefund' => $this->actor()->can('create', Disbursement::class) && $s->status->value === 'approved',
            'left' => Fils::toDecimal($s->refundFils() - $s->refundedFils()),
            'types' => DeductionType::cases(),
            'methods' => DisbursementMethod::cases(),
            'rentLines' => InvoiceLine::query()->whereIn('agreement_unit_id', $auIds)
                ->whereHas('invoice', fn ($q) => $q->where('status', InvoiceStatus::Issued)->where('type', '!=', 'credit_note'))
                ->get()->filter(fn (InvoiceLine $l) => $l->balanceFils() > 0)->values(),
        ])->title($s->label());
    }
}
```
Create `resources/views/livewire/deposit-settlements/show.blade.php`:
```blade
<section class="w-full max-w-4xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ __('Deposit settlement :n', ['n' => $s->label()]) }}</flux:heading>
            <flux:badge>{{ $s->status->label() }}</flux:badge>
        </div>
        @if ($canRefund)
            <flux:modal.trigger name="refund"><flux:button variant="primary">{{ __('Pay refund (:c BHD left)', ['c' => $left]) }}</flux:button></flux:modal.trigger>
        @endif
    </div>
    <flux:text>{{ $s->agreement->label() }} · {{ $s->agreement->customer->name_en }}</flux:text>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Unit') }}</flux:table.column>
                <flux:table.column>{{ __('Held') }}</flux:table.column>
                <flux:table.column>{{ __('Applied') }}</flux:table.column>
                <flux:table.column>{{ __('Refund') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($s->units as $u)
                    <flux:table.row :key="'u'.$u->id">
                        <flux:table.cell>{{ $u->agreementUnit->unit->building->code }} / {{ $u->agreementUnit->unit->code }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $u->held_amount }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $u->applied_amount ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $u->refund_amount ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:heading size="lg">{{ __('Deductions') }}</flux:heading>
    @foreach ($lines as $i => $line)
        <flux:card class="space-y-3" wire:key="ded-{{ $i }}">
            <div class="grid gap-3 sm:grid-cols-4">
                <flux:select wire:model="lines.{{ $i }}.agreement_unit_id" :label="__('Unit')" :disabled="! $canEdit">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($s->units as $u)<option value="{{ $u->agreement_unit_id }}">{{ $u->agreementUnit->unit->code }}</option>@endforeach
                </flux:select>
                <flux:select wire:model.live="lines.{{ $i }}.type" :label="__('Type')" :disabled="! $canEdit">
                    @foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="lines.{{ $i }}.amount" inputmode="decimal" :label="__('Amount (BHD)')" :disabled="! $canEdit" />
                @if (($line['type'] ?? '') === 'unpaid_rent')
                    <flux:select wire:model="lines.{{ $i }}.invoice_line_id" :label="__('Unpaid line')" :disabled="! $canEdit">
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($rentLines as $rl)<option value="{{ $rl->id }}">{{ $rl->description }} ({{ \App\Support\Fils::toDecimal($rl->balanceFils()) }})</option>@endforeach
                    </flux:select>
                @endif
            </div>
            <flux:input wire:model="lines.{{ $i }}.description" :label="__('Reason')" :disabled="! $canEdit" />
            @foreach (['agreement_unit_id', 'type', 'description', 'amount', 'invoice_line_id'] as $f)<flux:error name="lines.{{ $i }}.{{ $f }}" />@endforeach
            @if ($canEdit)<flux:button size="sm" variant="ghost" wire:click="removeLine({{ $i }})">{{ __('Remove') }}</flux:button>@endif
        </flux:card>
    @endforeach
    <flux:error name="lines" />
    @if ($canEdit)
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="addLine">{{ __('Add deduction') }}</flux:button>
            <flux:button wire:click="saveLines">{{ __('Save') }}</flux:button>
            <flux:button variant="primary" wire:click="submit" wire:confirm="{{ __('Send this settlement for Management approval?') }}">{{ __('Send for approval') }}</flux:button>
        </div>
    @endif

    @if ($s->deductionsInvoice)
        <flux:text>{{ __('Deductions invoice') }} <flux:link :href="route('invoices.show', $s->deductionsInvoice)" wire:navigate>{{ $s->deductionsInvoice->label() }}</flux:link>@if ($s->payment) · {{ __('deposit applied') }} <flux:link :href="route('payments.show', $s->payment)" wire:navigate>{{ $s->payment->number }}</flux:link>@endif</flux:text>
    @endif

    <flux:modal name="refund" class="md:w-96">
        <form wire:submit="payRefund" class="space-y-4">
            <flux:heading size="lg">{{ __('Refund the deposit') }}</flux:heading>
            <flux:input wire:model="refund.amount" inputmode="decimal" :label="__('Amount (BHD)')" />
            <flux:select wire:model.live="refund.method" :label="__('Method')">
                @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
            </flux:select>
            <flux:input wire:model="refund.paid_on" type="date" :label="__('Paid on')" />
            <flux:input wire:model="refund.reference" :label="__('Reference')" />
            @if (($refund['method'] ?? '') === 'cheque')
                <flux:input wire:model="refund.cheque_no" :label="__('Cheque no.')" />
                <flux:input wire:model="refund.bank_name" :label="__('Bank')" />
                <flux:input wire:model="refund.cheque_date" type="date" :label="__('Cheque date')" />
            @endif
            @foreach (['amount', 'method', 'paid_on', 'reference', 'cheque_no', 'bank_name', 'cheque_date'] as $f)<flux:error name="refund.{{ $f }}" />@endforeach
            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="payRefund">{{ __('Pay refund') }}</flux:button>
        </form>
    </flux:modal>
</section>
```
In `app/Approvals/DepositSettlementApproval.php` change `url()` to `return route('deposit-settlements.show', $approval->approvable_id);`.

In `routes/property.php` add `use App\Livewire\DepositSettlements;` and:
```php
    Route::livewire('deposit-settlements', DepositSettlements\Index::class)->middleware('can:finance.view')->name('deposit-settlements.index');
    Route::livewire('deposit-settlements/{settlement}', DepositSettlements\Show::class)->middleware('can:finance.view')->name('deposit-settlements.show');
```
Sidebar Finance group, after Payments out:
```blade
                        <flux:sidebar.item icon="shield-check" :href="route('deposit-settlements.index')" :current="request()->routeIs('deposit-settlements.*')" wire:navigate>{{ __('Deposit settlements') }}</flux:sidebar.item>
```
On the agreement page list its settlements: in `app/Livewire/Agreements/Show.php` `render()` add `'settlements' => $this->actor()->can('viewAny', \App\Models\DepositSettlement::class) ? \App\Models\DepositSettlement::query()->where('agreement_id', $agreement->id)->latest('id')->get() : collect(),` (import the class), and in the view after the invoices block:
```blade
    @if ($settlements->isNotEmpty())
        <div class="space-y-1">
            <flux:heading size="lg">{{ __('Deposit settlements') }}</flux:heading>
            @foreach ($settlements as $st)
                <div class="text-sm"><flux:link :href="route('deposit-settlements.show', $st)" wire:navigate>{{ $st->label() }}</flux:link> · {{ $st->status->label() }}</div>
            @endforeach
        </div>
    @endif
```

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Deposits tests/Feature/Disbursements tests/Feature/Agreements
"$PHP" artisan test
grep -rn "panel:documentable" resources/views   # must print nothing
```
Expected: every test passes.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Refund settled deposits and add the settlement screens

Finance pays the refund of an approved settlement, in one go or in
parts, which moves the deposit out; reversing a refund puts it back and
reopens the settlement. Settlements are listed and edited on their own
screens and linked from the agreement.
EOF
```

---
### Task 7: Re-billing engine (cancel and replace)

**Spec:** §6.3 (scheduled invoices are never edited: any change cancels the affected scheduled invoices and creates replacements, `replaced_by_invoice_id`; cheques matched to a cancelled invoice are re-pointed to its replacement automatically; with no replacement the cheque's `invoice_id` is cleared and it appears on "Held cheques to return"), §5.7 billing effect (each unit's lines cover only the part of each period inside that unit's dates, prorated by §6.2; in the approval transaction: 1. every scheduled invoice whose period ends on or after `effective_date` is cancelled and replaced; 2. every issued invoice whose period ends on or after `effective_date`: the difference is billed on one manual invoice (add_unit) or credited on one credit note per affected invoice (release_unit, terminate), created and issued as part of the approval; a credit line's net = billed net − the §6.2 amount for the kept days, tax per §6.5), plan rulings 4 and 5

**Files:**
- Create: `database/migrations/2026_10_20_000400_add_charge_to_invoice_lines.php`, `app/Billing/InvoicePeriod.php`, `app/Actions/Billing/{BuildCreditNote,RebillAgreement}.php`, `tests/Feature/Billing/RebillAgreementTest.php`
- Modify: `app/Actions/Billing/GenerateRentSchedule.php`, `app/Actions/Billing/SaveCreditNote.php`, `app/Models/InvoiceLine.php`, `app/Integrity/IntegrityCheck.php`

**Interfaces:**
- Consumes: `IssueCreditNote`, `IssueInvoice`, `Proration::line`, `Tax::amount`, `BillingPeriods::for`
- Produces:
  - `invoice_lines.agreement_unit_charge_id` (nullable FK, frozen once the invoice is not draft); `InvoiceLine::charge()`
  - `GenerateRentSchedule::linesFor(Agreement $agreement, BillingPeriod $period, ?int $onlyAgreementUnitId = null): list<array<string, mixed>>` and `GenerateRentSchedule::createScheduled(Agreement $agreement, BillingPeriod $period, array $lines, User $actor): Invoice` (both public; `handle()` uses them)
  - `InvoicePeriod::of(Agreement $agreement, Invoice $invoice): BillingPeriod`
  - `BuildCreditNote::handle(Invoice $target, array $entries, string $reason, User $actor, ?Invoice $draft = null): Invoice` — internal; `$entries` = `list<array{0: InvoiceLine, 1: int}>` (line, gross fils); returns a draft credit note
  - `RebillAgreement::handle(Agreement $agreement, CarbonImmutable $effectiveDate, User $actor, string $reason): RebillResult` — internal; the caller holds the customer and agreement locks and has already changed the unit dates (or inserted the new unit)
  - `App\Billing\RebillResult` (readonly: `int $cancelled`, `int $replaced`, `list<int> $creditNoteIds`, `?int $manualInvoiceId`, `list<int> $chequesToReturn`)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Billing/RebillAgreementTest.php`:
```php
<?php

use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RebillAgreement;
use App\Actions\Cheques\RecordCheques;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Billing\CustomerCredit;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
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
    CompanySetting::factory()->create(['invoice_lead_days' => 0]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30'], [Unit::factory()->create()]);
    $this->au = $this->agreement->agreementUnits()->sole();
    DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($this->agreement, $this->finance)); // 12 × 400.000
    $this->byMonth = fn (string $start) => Invoice::where('agreement_id', $this->agreement->id)->where('period_start', $start)->whereNull('replaced_by_invoice_id')->where('status', '!=', 'cancelled')->sole();
    foreach (['2026-10-01', '2026-11-01'] as $m) { // October and November issued ("Issue now")
        app(IssueInvoice::class)->handle(($this->byMonth)($m), $this->finance);
    }
    $this->rebill = fn (string $effective) => DB::transaction(function () use ($effective) {
        Customer::query()->lockForUpdate()->findOrFail($this->customer->id);
        return app(RebillAgreement::class)->handle($this->agreement->fresh(), CarbonImmutable::parse($effective), $this->finance, 'Unit released');
    });
});

test('the schedule stamps each line with its charge, frozen after issue', function () {
    $line = ($this->byMonth)('2026-10-01')->lines->sole();
    expect($line->agreement_unit_charge_id)->toBe($this->au->charges()->sole()->id);
    expect(fn () => DB::table('invoice_lines')->where('id', $line->id)->update(['agreement_unit_charge_id' => null]))->toThrow(QueryException::class, 'invoice_lines: the charge is frozen');
});

test('releasing a unit mid-November: later scheduled invoices go, November is credited for the unkept days', function () {
    $december = ($this->byMonth)('2026-12-01');
    $cheque = app(RecordCheques::class)->handle($this->finance, $this->customer, $this->agreement, [['cheque_no' => '1', 'bank_name' => 'NBB', 'cheque_date' => '2026-12-01', 'amount' => '400', 'invoice_id' => $december->id]])->sole();
    $this->au->forceFill(['end_date' => '2026-11-15', 'planned_exit_date' => '2026-11-15'])->save();

    $result = ($this->rebill)('2026-11-15');

    expect($result->cancelled)->toBe(10)              // December … September
        ->and($result->replaced)->toBe(0)             // no days left in them
        ->and($december->fresh()->status->value)->toBe('cancelled')
        ->and($cheque->fresh()->invoice_id)->toBeNull()
        ->and($result->chequesToReturn)->toBe([$cheque->id]);

    $cn = Invoice::findOrFail($result->creditNoteIds[0]);
    expect($result->creditNoteIds)->toHaveCount(1)
        ->and($cn->status->value)->toBe('issued')
        ->and($cn->related_invoice_id)->toBe(($this->byMonth)('2026-11-01')->id)
        ->and($cn->total)->toBe('202.740')            // 400 − 15 days (1–15 Nov) at 400 × 12 / 365
        ->and(($this->byMonth)('2026-11-01')->balance)->toBe('197.260')
        ->and(($this->byMonth)('2026-10-01')->credited)->toBe('0.000')
        ->and(app(IntegrityCheck::class)->run())->toBe([]);
});

test('a scheduled period cut short is replaced by a prorated invoice and its cheque follows', function () {
    $this->au->forceFill(['end_date' => '2026-12-10'])->save();
    $december = ($this->byMonth)('2026-12-01');
    $cheque = app(RecordCheques::class)->handle($this->finance, $this->customer, $this->agreement, [['cheque_no' => '2', 'bank_name' => 'NBB', 'cheque_date' => '2026-12-01', 'amount' => '400', 'invoice_id' => $december->id]])->sole();

    $result = ($this->rebill)('2026-12-10');

    $replacement = Invoice::findOrFail($december->fresh()->replaced_by_invoice_id);
    expect($result->replaced)->toBe(1)
        ->and($replacement->status->value)->toBe('scheduled')
        ->and($replacement->total)->toBe('131.507')   // 10 days at 400 × 12 / 365
        ->and($replacement->lines->sole()->period_end->toDateString())->toBe('2026-12-10')
        ->and($cheque->fresh()->invoice_id)->toBe($replacement->id)
        ->and($result->creditNoteIds)->toBe([]);      // nothing issued is affected
});

test('a credit on a paid period de-allocates and returns the money as credit', function () {
    app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '800.000']);
    $this->au->forceFill(['end_date' => '2026-11-15'])->save();

    ($this->rebill)('2026-11-15');

    expect(CustomerCredit::fils($this->customer->id))->toBe(202_740)
        ->and(($this->byMonth)('2026-11-01')->balance)->toBe('0.000');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Billing/RebillAgreementTest.php`
Expected: FAIL — `Class "App\Actions\Billing\RebillAgreement" not found`.

- [ ] **Step 3: Migration**

Create `database/migrations/2026_10_20_000400_add_charge_to_invoice_lines.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Plan ruling 5: rent-schedule lines remember their charge, so re-billing matches them exactly. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('agreement_unit_charge_id')->nullable()->after('agreement_unit_id')->constrained()->restrictOnDelete();
        });

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER invoice_lines_charge_guard BEFORE UPDATE ON invoice_lines FOR EACH ROW FOLLOWS invoice_lines_paid_only_issued
            BEGIN
                IF NOT (NEW.agreement_unit_charge_id <=> OLD.agreement_unit_charge_id)
                    AND (SELECT status FROM invoices WHERE id = OLD.invoice_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_lines: the charge is frozen once the invoice is not draft';
                END IF;
            END
            DDL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS invoice_lines_charge_guard');
        Schema::table('invoice_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('agreement_unit_charge_id'));
    }
};
```
`EXPECTED_TRIGGERS` 48 → **49** (`+ 1 (invoice line charge)`). In `app/Models/InvoiceLine.php` add `@property int|null $agreement_unit_charge_id` and:
```php
    /** @return BelongsTo<AgreementUnitCharge, $this> */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(AgreementUnitCharge::class, 'agreement_unit_charge_id');
    }
```

- [ ] **Step 4: Split the schedule generator**

Replace `app/Actions/Billing/GenerateRentSchedule.php` with:
```php
<?php

namespace App\Actions\Billing;

use App\Billing\BillingPeriod;
use App\Billing\BillingPeriods;
use App\Billing\Proration;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Agreement;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Spec §6.2: one scheduled rent invoice per billing period for the whole term. Runs inside the activation transaction.
 * linesFor() is the one definition of "what a period bills", shared with re-billing (spec §5.7, §6.3).
 */
final class GenerateRentSchedule
{
    /** @return Collection<int, Invoice> */
    public function handle(Agreement $agreement, User $actor): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('GenerateRentSchedule must run inside the caller\'s transaction.');
        }

        $invoices = collect();
        foreach (BillingPeriods::for($agreement->start_date, $agreement->end_date, $agreement->frequency, $agreement->billing_day) as $period) {
            $lines = $this->linesFor($agreement, $period);
            if ($lines !== []) {
                $invoices->push($this->createScheduled($agreement, $period, $lines, $actor));
            }
        }

        return $invoices;
    }

    /**
     * Each agreement unit's charges over its own dates within the period, prorated (spec §6.2).
     *
     * @return list<array<string, mixed>>
     */
    public function linesFor(Agreement $agreement, BillingPeriod $period, ?int $onlyAgreementUnitId = null): array
    {
        $agreement->loadMissing('agreementUnits.charges', 'agreementUnits.unit.building');
        $settings = CompanySetting::current();
        $months = $agreement->frequency->months();
        $lines = [];

        foreach ($agreement->agreementUnits as $au) {
            if ($onlyAgreementUnitId !== null && $au->id !== $onlyAgreementUnitId) {
                continue;
            }
            $from = $au->start_date->max($period->start);
            $to = $au->end_date->min($period->end);

            foreach ($au->charges as $charge) {
                $net = Proration::line($period, $au->start_date, $au->end_date, Fils::fromDecimal($charge->monthly_amount), $months, $settings->proration_basis);
                if ($net === 0) {
                    continue;
                }

                $lines[] = [
                    'agreement_unit_id' => $au->id,
                    'agreement_unit_charge_id' => $charge->id,
                    'unit_id' => $au->unit_id,
                    'charge_type' => $charge->type->value,
                    'description' => sprintf('%s — %s / %s, %s–%s', $charge->description ?: $charge->type->label(),
                        $au->unit->building->code, $au->unit->code, $from->format('d/m/Y'), $to->format('d/m/Y')),
                    'period_start' => $from->toDateString(),
                    'period_end' => $to->toDateString(),
                    'net' => Fils::toDecimal($net),
                    'tax_category' => $charge->tax_category->value,
                    'tax_rate' => '0.00',
                    'tax_amount' => '0.000',
                    'total' => Fils::toDecimal($net), // tax is written at issue (spec §6.3)
                ];
            }
        }

        return $lines;
    }

    /** @param  list<array<string, mixed>>  $lines */
    public function createScheduled(Agreement $agreement, BillingPeriod $period, array $lines, User $actor): Invoice
    {
        $settings = CompanySetting::current();
        $sum = array_sum(array_map(fn (array $l) => Fils::fromDecimal($l['net']), $lines));
        $due = $period->start; // rent is billed in advance
        $issue = $due->subDays($settings->invoice_lead_days)->max(CarbonImmutable::now('Asia/Bahrain')->startOfDay());

        // Created as draft, lines inserted, then scheduled: lines may only be inserted into a draft (spec §8.5).
        $invoice = (new Invoice)->forceFill([
            'type' => InvoiceType::Rent,
            'customer_id' => $agreement->customer_id,
            'agreement_id' => $agreement->id,
            'period_start' => $period->start->toDateString(),
            'period_end' => $period->end->toDateString(),
            'issue_date' => $issue->toDateString(),
            'due_date' => $due->toDateString(),
            'status' => InvoiceStatus::Draft,
            'subtotal' => Fils::toDecimal($sum),
            'tax_total' => '0.000',
            'total' => Fils::toDecimal($sum),
            'created_by' => $actor->id,
        ]);
        $invoice->save();
        $invoice->lines()->createMany($lines);
        $invoice->forceFill(['status' => InvoiceStatus::Scheduled])->save();

        return $invoice;
    }
}
```
`loadMissing` replaces M2's `load`: callers that change unit dates must pass a fresh agreement (`RebillAgreement` does). Run `tests/Feature/Billing` to confirm the schedule is unchanged.

Create `app/Billing/InvoicePeriod.php`:
```php
<?php

namespace App\Billing;

use App\Models\Agreement;
use App\Models\Invoice;
use LogicException;

/**
 * The billing period an invoice was raised for, with its regular flag (spec §6.2). Recomputed from the agreement's start
 * up to the invoice's own period end, so a later change of the agreement's end_date never alters a past period.
 */
final class InvoicePeriod
{
    public static function of(Agreement $agreement, Invoice $invoice): BillingPeriod
    {
        if ($invoice->period_start === null || $invoice->period_end === null) {
            throw new LogicException("Invoice {$invoice->id} has no billing period.");
        }

        foreach (BillingPeriods::for($agreement->start_date, $invoice->period_end, $agreement->frequency, $agreement->billing_day) as $period) {
            if ($period->start->equalTo($invoice->period_start->startOfDay())) {
                return $period;
            }
        }

        throw new LogicException("Invoice {$invoice->id} does not match a billing period of agreement {$agreement->id}.");
    }
}
```

- [ ] **Step 5: Building credit notes without a user request**

Create `app/Actions/Billing/BuildCreditNote.php` — the body of `SaveCreditNote`'s transaction after validation, so system credit notes (amendments, renewals) and Finance's share one writer:
```php
<?php

namespace App\Actions\Billing;

use App\Billing\CreditNoteSplit;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Spec §6.5: writes a draft credit note against one issued invoice. Internal: callers validated the entries. */
final class BuildCreditNote
{
    /** @param  list<array{0: InvoiceLine, 1: int}>  $entries  credited line and gross fils */
    public function handle(Invoice $target, array $entries, string $reason, User $actor, ?Invoice $draft = null): Invoice
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('BuildCreditNote must run inside the caller\'s transaction.');
        }

        $cn = $draft ?? new Invoice;
        $cn->forceFill([
            'type' => InvoiceType::CreditNote,
            'customer_id' => $target->customer_id,
            'agreement_id' => $target->agreement_id,
            'related_invoice_id' => $target->id,
            'credit_reason' => $reason,
            'issue_date' => now('Asia/Bahrain')->toDateString(),
            'due_date' => now('Asia/Bahrain')->toDateString(),
            'status' => InvoiceStatus::Draft,
            'created_by' => $cn->created_by ?? $actor->id,
        ]);

        $subtotal = 0;
        $tax = 0;
        $rows = [];
        foreach ($entries as [$line, $amount]) {
            $split = CreditNoteSplit::of($amount, $line);
            $rows[] = [
                'credited_line_id' => $line->id,
                'agreement_unit_id' => $line->agreement_unit_id,
                'unit_id' => $line->unit_id,
                'charge_type' => $line->charge_type,
                'description' => __('Credit: :d', ['d' => $line->description]),
                'period_start' => $line->period_start,
                'period_end' => $line->period_end,
                'net' => Fils::toDecimal($split['net']),
                'tax_category' => $line->tax_category,
                'tax_rate' => $line->tax_rate,
                'tax_amount' => Fils::toDecimal($split['tax']),
                'total' => Fils::toDecimal($amount),
                'owner_contract_id' => $line->owner_contract_id,
            ];
            $subtotal += $split['net'];
            $tax += $split['tax'];
        }

        $cn->forceFill(['subtotal' => Fils::toDecimal($subtotal), 'tax_total' => Fils::toDecimal($tax), 'total' => Fils::toDecimal($subtotal + $tax)])->save();
        $cn->lines()->delete();
        foreach ($rows as $row) {
            $cn->lines()->create($row);
        }

        return $cn->refresh()->load('lines');
    }
}
```
In `app/Actions/Billing/SaveCreditNote.php`, inject `BuildCreditNote $build` in a constructor and replace everything from `$cn->forceFill([` to `return $cn->refresh()->load('lines');` with:
```php
            return $this->build->handle($target, $entries, trim((string) ($data['reason'] ?? '')), $actor, $draft ? $cn : null);
```
`tests/Feature/Invoices/CreditNoteTest.php` must pass unchanged.

- [ ] **Step 6: The engine**

Create `app/Billing/RebillResult.php`:
```php
<?php

namespace App\Billing;

final readonly class RebillResult
{
    /**
     * @param  list<int>  $creditNoteIds
     * @param  list<int>  $chequesToReturn  cheques whose invoice was cancelled with no replacement (spec §6.3)
     */
    public function __construct(
        public int $cancelled,
        public int $replaced,
        public array $creditNoteIds,
        public ?int $manualInvoiceId,
        public array $chequesToReturn,
    ) {}
}
```
Create `app/Actions/Billing/RebillAgreement.php`:
```php
<?php

namespace App\Actions\Billing;

use App\Billing\InvoicePeriod;
use App\Billing\RebillResult;
use App\Billing\Tax;
use App\Enums\ChequeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Agreement;
use App\Models\Cheque;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Spec §5.7 billing effect and §6.3 cancel-and-replace. Internal: the caller holds the customer and agreement locks and
 * has already applied the new unit dates. Locks, in the global order: cheques → issued lines → invoices.
 */
final class RebillAgreement
{
    public function __construct(
        private GenerateRentSchedule $schedule,
        private BuildCreditNote $buildCreditNote,
        private IssueCreditNote $issueCreditNote,
        private IssueInvoice $issue,
    ) {}

    public function handle(Agreement $agreement, CarbonImmutable $effectiveDate, User $actor, string $reason): RebillResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('RebillAgreement must run inside the caller\'s transaction.');
        }

        $agreement = Agreement::query()->with('agreementUnits.charges', 'agreementUnits.unit.building')->findOrFail($agreement->id);
        $from = $effectiveDate->toDateString();

        // 1. Scheduled rent invoices ending on or after the effective date: cancel, replace if anything is left, move cheques.
        $scheduled = Invoice::query()->where('agreement_id', $agreement->id)->where('type', InvoiceType::Rent)
            ->where('status', InvoiceStatus::Scheduled)->where('period_end', '>=', $from)->orderBy('id')->pluck('id');
        $cheques = Cheque::query()->whereIn('invoice_id', $scheduled)->whereIn('status', [ChequeStatus::Held, ChequeStatus::Deposited])
            ->orderBy('id')->lockForUpdate()->get();

        $cancelled = 0;
        $replaced = 0;
        $toReturn = [];
        foreach (Invoice::query()->whereKey($scheduled)->orderBy('id')->lockForUpdate()->get() as $old) {
            $period = InvoicePeriod::of($agreement, $old);
            $lines = $this->schedule->linesFor($agreement, $period);
            $old->forceFill(['status' => InvoiceStatus::Cancelled])->save();
            $cancelled++;

            $new = $lines === [] ? null : $this->schedule->createScheduled($agreement, $period, $lines, $actor);
            if ($new) {
                $old->forceFill(['replaced_by_invoice_id' => $new->id])->save();
                $replaced++;
            }
            foreach ($cheques->where('invoice_id', $old->id) as $cheque) {
                $cheque->forceFill(['invoice_id' => $new?->id])->save();
                if ($new === null) {
                    $toReturn[] = $cheque->id;
                }
            }
        }

        // 2. Issued rent invoices ending on or after the effective date: credit what is no longer billed, bill what is new.
        $creditNotes = [];
        $extra = [];
        $issued = Invoice::query()->where('agreement_id', $agreement->id)->where('type', InvoiceType::Rent)
            ->where('status', InvoiceStatus::Issued)->where('period_end', '>=', $from)->orderBy('id')->get();

        foreach ($issued as $invoice) {
            $period = InvoicePeriod::of($agreement, $invoice);
            $desired = collect($this->schedule->linesFor($agreement, $period))->keyBy('agreement_unit_charge_id');
            $billed = InvoiceLine::query()->where('invoice_id', $invoice->id)->whereNotNull('agreement_unit_charge_id')->orderBy('id')->get();

            $entries = [];
            foreach ($billed as $line) {
                $correct = Fils::fromDecimal((string) ($desired->get($line->agreement_unit_charge_id)['net'] ?? '0'));
                $creditNet = Fils::fromDecimal($line->net) - $correct;
                $left = Fils::fromDecimal($line->total) - Fils::fromDecimal((string) $line->credited);
                if ($creditNet <= 0 || $left <= 0) {
                    continue;
                }
                // Plan ruling 4: the whole line takes exactly what is left; a partial credit adds its tax at the line's rate.
                $gross = $correct === 0
                    ? $left
                    : min($left, $creditNet + Tax::amount($creditNet, $line->tax_category, (float) $line->tax_rate > 0, (string) $line->tax_rate));
                $entries[] = [$line, $gross];
            }
            if ($entries !== []) {
                $cn = $this->buildCreditNote->handle($invoice, $entries, $reason, $actor);
                $cn->forceFill(['status' => InvoiceStatus::PendingApproval])->save();
                $this->issueCreditNote->handle($cn, $actor);
                $creditNotes[] = $cn->id;
            }

            $billedCharges = $billed->pluck('agreement_unit_charge_id')->all();
            foreach ($desired as $chargeId => $row) {
                if (! in_array($chargeId, $billedCharges, true)) {
                    $extra[] = $row; // a unit added inside an already-issued period (add_unit)
                }
            }
        }

        // One manual invoice for everything newly billed in issued periods (spec §5.7).
        $manualId = null;
        if ($extra !== []) {
            $sum = array_sum(array_map(fn (array $l) => Fils::fromDecimal($l['net']), $extra));
            $manual = (new Invoice)->forceFill([
                'type' => InvoiceType::Manual,
                'customer_id' => $agreement->customer_id,
                'agreement_id' => $agreement->id,
                'issue_date' => now('Asia/Bahrain')->toDateString(),
                'due_date' => now('Asia/Bahrain')->toDateString(),
                'status' => InvoiceStatus::Draft,
                'subtotal' => Fils::toDecimal($sum),
                'tax_total' => '0.000',
                'total' => Fils::toDecimal($sum),
                'created_by' => $actor->id,
            ]);
            $manual->save();
            $manual->lines()->createMany($extra);
            $this->issue->handle($manual, $actor); // a hold-back (§4.6) leaves it draft for Finance to issue later
            $manualId = $manual->id;
        }

        return new RebillResult($cancelled, $replaced, $creditNotes, $manualId, $toReturn);
    }
}
```
The manual invoice's lines keep `agreement_unit_charge_id` (it is not a rent invoice, so later re-billing never reads it; the column is informational there).

- [ ] **Step 7: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Billing tests/Feature/Invoices tests/Feature/Cheques tests/Feature/Integrity tests/Unit
"$PHP" artisan test
```
Expected: every test passes (M2's schedule tests unchanged; 197.260 = `divRound(400 000 × 15 × 12, 365)`, 131.507 = `divRound(400 000 × 10 × 12, 365)`).

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the re-billing engine

When a unit's dates change, scheduled rent invoices from that date are
cancelled and replaced with what the new dates bill, their cheques
follow (or are flagged to return), issued periods are credited for the
days no longer let, and newly let days in issued periods are invoiced.
EOF
```

---

### Task 8: Agreement amendments (approval items 2 and 3)

**Spec:** §5.7 (`agreement_amendments`: type add_unit | release_unit | terminate, effective_date, data, reason, status draft | pending_approval | approved; every unit an amendment touches in the requester's scope; on approval: add_unit — overlap check, new agreement unit from effective_date to the agreement's end_date, deposit invoice for the new unit; release_unit — that unit's end_date and planned_exit_date = effective_date; terminate — the same for every unit and the agreement's end_date = effective_date; then the billing effect; rent changes only through renewal), §8.3 items 2–3 (rejecting returns the draft), §5.4 (a move-out before end_date neither ends billing nor frees the unit — only an amendment does), plan ruling 6

**Files:**
- Create: `app/Enums/{AmendmentType,AmendmentStatus}.php`, `database/migrations/2026_10_20_000500_create_agreement_amendments_table.php`, `app/Models/AgreementAmendment.php`, `app/Actions/Agreements/{SaveAmendment,SubmitAmendment,ApplyAmendment}.php`, `app/Approvals/AgreementAmendmentApproval.php`, `tests/Feature/Agreements/AmendmentTest.php`, `tests/Feature/Agreements/AmendmentTriggersTest.php`
- Modify: `app/Models/Agreement.php`, `app/Models/AgreementUnit.php`, `app/Enums/ApprovalAction.php`, `app/Actions/Billing/CreateDepositInvoice.php`, `app/Integrity/IntegrityCheck.php`

**Interfaces:**
- Consumes: `RebillAgreement`, `EnsureNoAgreementOverlap`, `CreateDepositInvoice`, `RequestApproval`
- Produces:
  - `AmendmentType` (`AddUnit`, `ReleaseUnit`, `Terminate`; `label()`), `AmendmentStatus` (`Draft`, `PendingApproval`, `Approved`)
  - `AgreementAmendment` (`agreement()`, `creator()`, `agreementUnit(): ?AgreementUnit` for release_unit), `Agreement::amendments()`
  - `SaveAmendment::handle(User $actor, Agreement $agreement, ?AgreementAmendment $draft, array $data): AgreementAmendment` — `$data`: `type`, `effective_date`, `reason`; release_unit: `agreement_unit_id`; add_unit: `unit_id`, `deposit_amount`, `charges` (same shape as `SaveAgreement`'s unit charges)
  - `SubmitAmendment::handle(User $actor, AgreementAmendment $draft): Approval`
  - `ApplyAmendment::handle(AgreementAmendment $pending, User $approver): RebillResult` — internal
  - `ApprovalAction::AgreementAmendment` (`'agreement.amend'`, item 2) and `ApprovalAction::AgreementTermination` (`'agreement.terminate'`, item 3), both → `App\Approvals\AgreementAmendmentApproval`
  - `CreateDepositInvoice::handle(Agreement $agreement, User $actor, ?array $amounts = null): ?Invoice` — `$amounts` = `array<int $agreementUnitId, int $fils>`; null = every unit's full deposit (M2 behaviour)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Agreements/AmendmentTest.php`:
```php
<?php

use App\Actions\Agreements\SaveAmendment;
use App\Actions\Agreements\SubmitAmendment;
use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\Billing\IssueInvoice;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['invoice_lead_days' => 0, 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create();
    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->leasing->buildings()->attach($this->building->id);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->customer = Customer::factory()->create();
    [$this->u1, $this->u2, $this->spare] = Unit::factory()->for($this->building)->count(3)->create()->all();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30', 'created_by' => $this->leasing->id], [$this->u1, $this->u2]);
    DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($this->agreement, $this->leasing)); // 12 × 800
    app(IssueInvoice::class)->handle(Invoice::where('agreement_id', $this->agreement->id)->where('period_start', '2026-10-01')->sole());
    app(IssueInvoice::class)->handle(Invoice::where('agreement_id', $this->agreement->id)->where('period_start', '2026-11-01')->sole());
    $this->approveAmendment = function (array $data) {
        $draft = app(SaveAmendment::class)->handle($this->leasing, $this->agreement, null, $data);
        app(DecideApproval::class)->handle($this->management, app(SubmitAmendment::class)->handle($this->leasing, $draft), true);

        return $draft->fresh();
    };
});

test('§14 flow 8: releasing a unit mid-period replaces the schedule and credits the issued period', function () {
    $au2 = $this->agreement->agreementUnits()->where('unit_id', $this->u2->id)->sole();
    $amendment = ($this->approveAmendment)(['type' => 'release_unit', 'agreement_unit_id' => $au2->id, 'effective_date' => '2026-11-15', 'reason' => 'Downsizing']);

    expect($amendment->status->value)->toBe('approved')
        ->and($amendment->applied_at)->not->toBeNull()
        ->and($au2->fresh()->end_date->toDateString())->toBe('2026-11-15')
        ->and($au2->fresh()->planned_exit_date->toDateString())->toBe('2026-11-15');

    $december = Invoice::where('agreement_id', $this->agreement->id)->where('period_start', '2026-12-01')->where('status', 'scheduled')->sole();
    $november = Invoice::where('agreement_id', $this->agreement->id)->where('period_start', '2026-11-01')->where('status', 'issued')->where('type', 'rent')->sole();
    expect($december->total)->toBe('400.000')                     // only unit 1 from December
        ->and(Invoice::where('type', 'credit_note')->sole()->related_invoice_id)->toBe($november->id)
        ->and($november->fresh()->credited)->toBe('202.740')       // unit 2 kept 1–15 Nov
        ->and(app(IntegrityCheck::class)->run())->toBe([]);
});

test('adding a unit bills the issued period it joins on a manual invoice and joins every later invoice', function () {
    $amendment = ($this->approveAmendment)(['type' => 'add_unit', 'unit_id' => $this->spare->id, 'effective_date' => '2026-11-15', 'reason' => 'Expansion',
        'deposit_amount' => '300.000', 'charges' => [['type' => 'rent', 'monthly_amount' => '300.000', 'tax_category' => 'exempt']]]);

    $new = $this->agreement->agreementUnits()->where('unit_id', $this->spare->id)->sole();
    expect($new->start_date->toDateString())->toBe('2026-11-15')
        ->and($new->end_date->toDateString())->toBe('2027-09-30')
        ->and($new->amendment_id)->toBe($amendment->id)
        ->and(Invoice::where('type', 'manual')->sole()->total)->toBe('157.808')   // 16 days at 300 × 12 / 365
        ->and(Invoice::where('type', 'deposit')->sole()->total)->toBe('300.000')
        ->and(Invoice::where('agreement_id', $this->agreement->id)->where('period_start', '2026-12-01')->where('status', 'scheduled')->sole()->total)->toBe('1100.000');
});

test('the added unit must be free: an overlapping agreement blocks it', function () {
    activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->spare]);
    $draft = app(SaveAmendment::class)->handle($this->leasing, $this->agreement, null, ['type' => 'add_unit', 'unit_id' => $this->spare->id, 'effective_date' => '2026-11-15', 'reason' => 'x',
        'charges' => [['type' => 'rent', 'monthly_amount' => '1', 'tax_category' => 'exempt']]]);

    expect(fn () => app(DecideApproval::class)->handle($this->management, app(SubmitAmendment::class)->handle($this->leasing, $draft), true))->toThrow(ValidationException::class);
    expect($this->agreement->agreementUnits()->count())->toBe(2);
});

test('early termination ends every unit and the agreement, credits issued periods, and cancels the rest', function () {
    ($this->approveAmendment)(['type' => 'terminate', 'effective_date' => '2026-11-30', 'reason' => 'Company relocating']);

    $agreement = $this->agreement->fresh('agreementUnits');
    expect($agreement->end_date->toDateString())->toBe('2026-11-30')
        ->and($agreement->agreementUnits->every(fn ($au) => $au->end_date->toDateString() === '2026-11-30'))->toBeTrue()
        ->and(Invoice::where('agreement_id', $agreement->id)->where('status', 'scheduled')->count())->toBe(0)
        ->and(Invoice::where('type', 'credit_note')->count())->toBe(0); // October and November are kept in full
});

test('amendments are validated, scoped and need approval; rejecting returns the draft', function () {
    $au1 = $this->agreement->agreementUnits()->where('unit_id', $this->u1->id)->sole();
    foreach ([
        ['type' => 'release_unit', 'agreement_unit_id' => $au1->id, 'effective_date' => '2027-10-01', 'reason' => 'x'],   // after end
        ['type' => 'release_unit', 'agreement_unit_id' => $au1->id, 'effective_date' => '2026-11-15', 'reason' => ''],    // no reason
        ['type' => 'add_unit', 'unit_id' => $this->u1->id, 'effective_date' => '2026-11-15', 'reason' => 'x', 'charges' => [['type' => 'rent', 'monthly_amount' => '1', 'tax_category' => 'exempt']]], // already on it
        ['type' => 'add_unit', 'unit_id' => $this->spare->id, 'effective_date' => '2026-11-15', 'reason' => 'x', 'charges' => []],
    ] as $bad) {
        expect(fn () => app(SaveAmendment::class)->handle($this->leasing, $this->agreement, null, $bad))->toThrow(ValidationException::class);
    }

    $outsider = User::factory()->create()->assignRole(RoleName::Leasing);
    expect(fn () => app(SaveAmendment::class)->handle($outsider, $this->agreement, null, ['type' => 'terminate', 'effective_date' => '2026-11-30', 'reason' => 'x']))->toThrow(AuthorizationException::class);

    $draft = app(SaveAmendment::class)->handle($this->leasing, $this->agreement, null, ['type' => 'terminate', 'effective_date' => '2026-11-30', 'reason' => 'x']);
    $approval = app(SubmitAmendment::class)->handle($this->leasing, $draft);
    expect($approval->action->value)->toBe('agreement.terminate');
    app(DecideApproval::class)->handle($this->management, $approval, false, 'Talk to them first');
    expect($draft->fresh()->status->value)->toBe('draft')->and($this->agreement->fresh()->end_date->toDateString())->toBe('2027-09-30');
});
```
Create `tests/Feature/Agreements/AmendmentTriggersTest.php`:
```php
<?php

use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->agreement = activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->create()]);
    $this->unit = Unit::factory()->create();
    $this->amendment = DB::table('agreement_amendments')->insertGetId(['agreement_id' => $this->agreement->id, 'type' => 'add_unit', 'effective_date' => '2026-06-01',
        'data' => json_encode(['unit_id' => $this->unit->id, 'deposit_amount' => '0.000', 'charges' => []]), 'reason' => 'x', 'status' => 'draft', 'created_by' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->row = fn (array $over = []) => ['agreement_id' => $this->agreement->id, 'unit_id' => $this->unit->id, 'list_rent' => '1.000', 'deposit_amount' => '0.000',
        'start_date' => '2026-06-01', 'end_date' => '2026-12-31', 'created_at' => now(), 'updated_at' => now(), ...$over];
});

test('a unit enters a submitted agreement only through an approved, unapplied add_unit amendment', function () {
    expect(fn () => DB::table('agreement_units')->insert(($this->row)()))->toThrow(QueryException::class, 'agreement_units are frozen once the agreement is submitted');
    expect(fn () => DB::table('agreement_units')->insert(($this->row)(['amendment_id' => $this->amendment])))->toThrow(QueryException::class); // still draft

    DB::table('agreement_amendments')->where('id', $this->amendment)->update(['status' => 'pending_approval']);
    DB::table('agreement_amendments')->where('id', $this->amendment)->update(['status' => 'approved']);
    DB::table('agreement_units')->insert(($this->row)(['amendment_id' => $this->amendment]));

    DB::table('agreement_amendments')->where('id', $this->amendment)->update(['applied_at' => now()]);
    expect(fn () => DB::table('agreement_units')->insert(($this->row)(['unit_id' => Unit::factory()->create()->id, 'amendment_id' => $this->amendment])))->toThrow(QueryException::class);
});

test('amendments are deleted only as drafts and freeze once submitted', function () {
    DB::table('agreement_amendments')->where('id', $this->amendment)->update(['status' => 'pending_approval']);
    expect(fn () => DB::table('agreement_amendments')->where('id', $this->amendment)->delete())->toThrow(QueryException::class, 'agreement_amendments: only drafts can be deleted');
    expect(fn () => DB::table('agreement_amendments')->where('id', $this->amendment)->update(['effective_date' => '2026-07-01']))->toThrow(QueryException::class, 'agreement_amendments: frozen once submitted');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Agreements/AmendmentTest.php tests/Feature/Agreements/AmendmentTriggersTest.php`
Expected: FAIL — `Base table or view not found: … agreement_amendments`.

- [ ] **Step 3: Enums and migration**

Create `app/Enums/AmendmentType.php`:
```php
<?php

namespace App\Enums;

enum AmendmentType: string
{
    case AddUnit = 'add_unit';
    case ReleaseUnit = 'release_unit';
    case Terminate = 'terminate';

    public function label(): string
    {
        return match ($this) {
            self::AddUnit => __('Add a unit'),
            self::ReleaseUnit => __('Release a unit'),
            self::Terminate => __('Early termination'),
        };
    }

    /** Spec §8.3: items 2 (add or release a unit) and 3 (early termination). */
    public function approvalAction(): ApprovalAction
    {
        return $this === self::Terminate ? ApprovalAction::AgreementTermination : ApprovalAction::AgreementAmendment;
    }
}
```
Create `app/Enums/AmendmentStatus.php`:
```php
<?php

namespace App\Enums;

enum AmendmentStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
```
Create `database/migrations/2026_10_20_000500_create_agreement_amendments_table.php`:
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
        Schema::create('agreement_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->date('effective_date');
            $table->foreignId('agreement_unit_id')->nullable()->constrained()->restrictOnDelete(); // release_unit
            $table->json('data')->nullable(); // add_unit: unit_id, deposit_amount, charges
            $table->text('reason');
            $table->string('status', 20)->default('draft');
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['agreement_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE agreement_amendments
                ADD CONSTRAINT agreement_amendments_type_chk CHECK (type IN ('add_unit', 'release_unit', 'terminate')),
                ADD CONSTRAINT agreement_amendments_status_chk CHECK (status IN ('draft', 'pending_approval', 'approved')),
                ADD CONSTRAINT agreement_amendments_target_chk CHECK ((type = 'release_unit') = (agreement_unit_id IS NOT NULL)
                    AND (type = 'add_unit') = (data IS NOT NULL)),
                ADD CONSTRAINT agreement_amendments_applied_chk CHECK (applied_at IS NULL OR status = 'approved')
            SQL);

        Schema::table('agreement_units', function (Blueprint $table) {
            $table->foreignId('amendment_id')->nullable()->after('agreement_id')->constrained('agreement_amendments')->restrictOnDelete();
        });

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER agreement_amendments_no_delete BEFORE DELETE ON agreement_amendments FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_amendments: only drafts can be deleted';
                END IF;
            END
            DDL);

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER agreement_amendments_guard BEFORE UPDATE ON agreement_amendments FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'draft' AND NEW.status = 'pending_approval')
                    OR (OLD.status = 'pending_approval' AND NEW.status IN ('draft', 'approved'))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_amendments: status change not allowed';
                END IF;
                IF OLD.status <> 'draft' AND NOT (NEW.agreement_id <=> OLD.agreement_id AND NEW.type <=> OLD.type
                    AND NEW.effective_date <=> OLD.effective_date AND NEW.agreement_unit_id <=> OLD.agreement_unit_id
                    AND NEW.data <=> OLD.data AND NEW.reason <=> OLD.reason AND NEW.created_by <=> OLD.created_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_amendments: frozen once submitted';
                END IF;
                IF OLD.applied_at IS NOT NULL AND NOT (NEW.applied_at <=> OLD.applied_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_amendments: applied once';
                END IF;
            END
            DDL);

        // Plan ruling 6: rows enter a non-draft agreement only through an approved, not-yet-applied add_unit amendment.
        DB::unprepared('DROP TRIGGER IF EXISTS agreement_units_insert');
        DB::unprepared(<<<'DDL'
            CREATE TRIGGER agreement_units_insert BEFORE INSERT ON agreement_units FOR EACH ROW
            BEGIN
                IF (SELECT status FROM agreements WHERE id = NEW.agreement_id) <> 'draft' AND NOT EXISTS (
                    SELECT 1 FROM agreement_amendments m WHERE m.id = NEW.amendment_id AND m.agreement_id = NEW.agreement_id
                      AND m.type = 'add_unit' AND m.status = 'approved' AND m.applied_at IS NULL) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_units are frozen once the agreement is submitted';
                END IF;
            END
            DDL);

        DB::unprepared('DROP TRIGGER IF EXISTS agreement_unit_charges_insert');
        DB::unprepared(<<<'DDL'
            CREATE TRIGGER agreement_unit_charges_insert BEFORE INSERT ON agreement_unit_charges FOR EACH ROW
            BEGIN
                IF (SELECT a.status FROM agreement_units au JOIN agreements a ON a.id = au.agreement_id WHERE au.id = NEW.agreement_unit_id) <> 'draft'
                    AND NOT EXISTS (SELECT 1 FROM agreement_units au JOIN agreement_amendments m ON m.id = au.amendment_id
                        WHERE au.id = NEW.agreement_unit_id AND m.status = 'approved' AND m.applied_at IS NULL) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_unit_charges are frozen once the agreement is submitted';
                END IF;
            END
            DDL);

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER agreement_units_amendment_guard BEFORE UPDATE ON agreement_units FOR EACH ROW FOLLOWS agreement_units_guard
            BEGIN
                IF NOT (NEW.amendment_id <=> OLD.amendment_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_units: the amendment that added a unit never changes';
                END IF;
            END
            DDL);
    }

    public function down(): void
    {
        // Restoring M2's insert triggers is not supported; roll back with migrate:fresh.
        foreach (['agreement_units_amendment_guard', 'agreement_amendments_guard', 'agreement_amendments_no_delete'] as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
        }
        Schema::table('agreement_units', fn (Blueprint $table) => $table->dropConstrainedForeignId('amendment_id'));
        Schema::dropIfExists('agreement_amendments');
    }
};
```
`EXPECTED_TRIGGERS` 49 → **52** (`+ 3 (amendments; the two insert triggers are replaced, not added)`).

- [ ] **Step 4: Models**

Create `app/Models/AgreementAmendment.php`:
```php
<?php

namespace App\Models;

use App\Enums\AmendmentStatus;
use App\Enums\AmendmentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec §5.7.
 *
 * @property int $id
 * @property int $agreement_id
 * @property AmendmentType $type
 * @property CarbonImmutable $effective_date
 * @property int|null $agreement_unit_id
 * @property array<string, mixed>|null $data
 * @property string $reason
 * @property AmendmentStatus $status
 * @property CarbonImmutable|null $applied_at
 * @property int $created_by
 */
class AgreementAmendment extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => AmendmentType::class,
            'status' => AmendmentStatus::class,
            'effective_date' => 'immutable_date',
            'applied_at' => 'immutable_datetime',
            'data' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['type', 'effective_date', 'agreement_unit_id', 'data', 'reason', 'status', 'applied_at'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Agreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /** @return BelongsTo<AgreementUnit, $this> */
    public function agreementUnit(): BelongsTo
    {
        return $this->belongsTo(AgreementUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```
In `app/Models/Agreement.php` add:
```php
    /** @return HasMany<AgreementAmendment, $this> */
    public function amendments(): HasMany
    {
        return $this->hasMany(AgreementAmendment::class);
    }
```
In `app/Models/AgreementUnit.php` add `@property int|null $amendment_id`. Check `AgreementUnit::$fillable`/`$guarded`: `ApplyAmendment` writes the row with `forceFill`, so no change is needed there.

- [ ] **Step 5: Deposit invoice for chosen units**

In `app/Actions/Billing/CreateDepositInvoice.php` change the signature to `public function handle(Agreement $agreement, User $actor, ?array $amounts = null): ?Invoice` (docblock `@param array<int, int>|null $amounts agreement unit id => fils; null = each unit's full deposit`), and build the lines from:
```php
        $lines = $agreement->agreementUnits
            ->map(fn ($au) => [$au, $amounts === null ? Fils::fromDecimal($au->deposit_amount) : ($amounts[$au->id] ?? 0)])
            ->filter(fn (array $pair) => $pair[1] > 0)
            ->map(fn (array $pair) => [
                'agreement_unit_id' => $pair[0]->id,
                'unit_id' => $pair[0]->unit_id,
                'charge_type' => InvoiceChargeType::Deposit->value,
                'description' => __('Security deposit — :b / :u', ['b' => $pair[0]->unit->building->code, 'u' => $pair[0]->unit->code]),
                'net' => Fils::toDecimal($pair[1]),
                'tax_category' => TaxCategory::OutOfScope->value,
                'tax_rate' => '0.00',
                'tax_amount' => '0.000',
                'total' => Fils::toDecimal($pair[1]),
            ])->values()->all();
```
and set `'due_date'` to the earliest start date among the billed units: `$agreement->agreementUnits->whereIn('id', array_column($lines, 'agreement_unit_id'))->min('start_date')->toDateString()` (for M2's call this is the agreement's start date, as before). Replace the `ponytail: M3 renewals…` sentence of the class comment with `Amendments bill one new unit; renewals bill only what the transfer did not cover (spec §5.8).`

- [ ] **Step 6: The Actions and the handler**

Create `app/Actions/Agreements/SaveAmendment.php`:
```php
<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Enums\AmendmentStatus;
use App\Enums\AmendmentType;
use App\Enums\ChargeType;
use App\Enums\PermissionName;
use App\Enums\TaxCategory;
use App\Models\Agreement;
use App\Models\AgreementAmendment;
use App\Models\Unit;
use App\Models\User;
use App\Policies\AgreementPolicy;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec §5.7: a draft amendment of an active agreement. Every unit it touches must be in the actor's scope (§8.2). */
final class SaveAmendment
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Agreement $agreement, ?AgreementAmendment $draft, array $data): AgreementAmendment
    {
        if (! $actor->can(PermissionName::AgreementsManage) || ! AgreementPolicy::allUnitsInScope($actor, $agreement)) {
            throw new AuthorizationException;
        }

        $v = Validator::make($data, [
            'type' => ['required', Rule::enum(AmendmentType::class)],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:2000'],
            'agreement_unit_id' => ['required_if:type,release_unit', 'nullable', 'integer'],
            'unit_id' => ['required_if:type,add_unit', 'nullable', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'deposit_amount' => ['nullable', Fils::rule()],
            'charges' => ['required_if:type,add_unit', 'array'],
            'charges.*.type' => ['required', Rule::enum(ChargeType::class)],
            'charges.*.description' => ['nullable', 'string', 'max:150'],
            'charges.*.monthly_amount' => ['required', Fils::rule()],
            'charges.*.tax_category' => ['required', Rule::enum(TaxCategory::class)],
        ])->validate();

        $type = AmendmentType::from($v['type']);
        $effective = $v['effective_date'];

        if ($agreement->status !== AgreementStatus::Active) {
            throw ValidationException::withMessages(['type' => __('Only an active agreement can be amended.')]);
        }
        if ($effective < $agreement->start_date->toDateString() || $effective >= $agreement->end_date->toDateString()) {
            throw ValidationException::withMessages(['effective_date' => __('Choose a date after the start and before the end of the agreement.')]);
        }

        $agreementUnitId = null;
        $payload = null;
        if ($type === AmendmentType::ReleaseUnit) {
            $au = $agreement->agreementUnits()->find((int) $v['agreement_unit_id']);
            if ($au === null || $effective < $au->start_date->toDateString() || $effective >= $au->end_date->toDateString()) {
                throw ValidationException::withMessages(['agreement_unit_id' => __('Choose a unit of this agreement that is let past that date.')]);
            }
            if ($agreement->agreementUnits()->where('end_date', '>', $effective)->count() < 2) {
                throw ValidationException::withMessages(['agreement_unit_id' => __('This is the last unit: terminate the agreement instead.')]);
            }
            $agreementUnitId = $au->id;
        }
        if ($type === AmendmentType::AddUnit) {
            $unitId = (int) $v['unit_id'];
            if (! Unit::query()->visibleTo($actor)->whereKey($unitId)->exists()) {
                throw new AuthorizationException;
            }
            if ($agreement->agreementUnits()->where('unit_id', $unitId)->exists()) {
                throw ValidationException::withMessages(['unit_id' => __('This unit is already on the agreement.')]);
            }
            $rents = array_filter($v['charges'] ?? [], fn (array $c) => $c['type'] === ChargeType::Rent->value);
            if (count($rents) !== 1 || Fils::fromDecimal((string) array_values($rents)[0]['monthly_amount']) === 0) {
                throw ValidationException::withMessages(['charges' => __('The unit needs exactly one rent charge above zero.')]);
            }
            $payload = [
                'unit_id' => $unitId,
                'deposit_amount' => Fils::toDecimal(Fils::fromDecimal((string) ($v['deposit_amount'] ?? '0'))),
                'charges' => array_map(fn (array $c) => [
                    'type' => $c['type'], 'description' => $c['description'] ?? null,
                    'monthly_amount' => Fils::toDecimal(Fils::fromDecimal((string) $c['monthly_amount'])), 'tax_category' => $c['tax_category'],
                ], array_values($v['charges'])),
            ];
        }

        return DB::transaction(function () use ($actor, $agreement, $draft, $type, $effective, $agreementUnitId, $payload, $v) {
            $amendment = $draft ? AgreementAmendment::query()->lockForUpdate()->findOrFail($draft->id) : new AgreementAmendment;
            if ($draft && ($amendment->status !== AmendmentStatus::Draft || $amendment->agreement_id !== $agreement->id)) {
                throw ValidationException::withMessages(['type' => __('Only a draft amendment can be edited.')]);
            }

            $amendment->forceFill([
                'agreement_id' => $agreement->id,
                'type' => $type,
                'effective_date' => $effective,
                'agreement_unit_id' => $agreementUnitId,
                'data' => $payload,
                'reason' => trim((string) $v['reason']),
                'status' => AmendmentStatus::Draft,
                'created_by' => $amendment->created_by ?? $actor->id,
            ])->save();

            return $amendment;
        }, attempts: 3);
    }
}
```
Create `app/Actions/Agreements/SubmitAmendment.php`:
```php
<?php

namespace App\Actions\Agreements;

use App\Actions\Approvals\RequestApproval;
use App\Enums\AmendmentStatus;
use App\Enums\PermissionName;
use App\Models\AgreementAmendment;
use App\Models\Approval;
use App\Models\User;
use App\Policies\AgreementPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §5.7, §8.3 items 2–3. */
final class SubmitAmendment
{
    public function __construct(private RequestApproval $request) {}

    public function handle(User $actor, AgreementAmendment $draft): Approval
    {
        $agreement = $draft->agreement()->firstOrFail();
        if (! $actor->can(PermissionName::AgreementsManage) || ! AgreementPolicy::allUnitsInScope($actor, $agreement)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $draft) {
            $amendment = AgreementAmendment::query()->lockForUpdate()->findOrFail($draft->id);
            if ($amendment->status !== AmendmentStatus::Draft) {
                throw ValidationException::withMessages(['type' => __('Only a draft amendment can be submitted.')]);
            }
            $amendment->forceFill(['status' => AmendmentStatus::PendingApproval])->save();

            return $this->request->handle($actor, $amendment, $amendment->type->approvalAction(), $amendment->reason);
        }, attempts: 3);
    }
}
```
Create `app/Actions/Agreements/ApplyAmendment.php`:
```php
<?php

namespace App\Actions\Agreements;

use App\Actions\Billing\CreateDepositInvoice;
use App\Actions\Billing\RebillAgreement;
use App\Billing\RebillResult;
use App\Enums\AgreementStatus;
use App\Enums\AmendmentStatus;
use App\Enums\AmendmentType;
use App\Models\Agreement;
use App\Models\AgreementAmendment;
use App\Models\AgreementUnit;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Spec §5.7 on approval. Internal: inside DecideApproval's transaction. Locks: customer → units (overlap, add_unit) →
 * agreement → agreement units → (RebillAgreement: cheques → lines → invoices).
 */
final class ApplyAmendment
{
    public function __construct(
        private EnsureNoAgreementOverlap $overlap,
        private RebillAgreement $rebill,
        private CreateDepositInvoice $deposit,
    ) {}

    public function handle(AgreementAmendment $pending, User $approver): RebillResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ApplyAmendment must run inside the caller\'s transaction.');
        }

        $agreement = Agreement::query()->findOrFail($pending->agreement_id);
        Customer::query()->lockForUpdate()->findOrFail($agreement->customer_id); // first lock (spec §7.2)
        $amendment = AgreementAmendment::query()->lockForUpdate()->findOrFail($pending->id);
        if ($amendment->status !== AmendmentStatus::PendingApproval) {
            throw ValidationException::withMessages(['approval' => __('This amendment is no longer waiting for approval.')]);
        }
        $amendment->forceFill(['status' => AmendmentStatus::Approved])->save();
        $effective = $amendment->effective_date;
        $newUnitId = null;

        if ($amendment->type === AmendmentType::AddUnit) {
            $data = (array) $amendment->data;
            $au = (new AgreementUnit)->forceFill([
                'agreement_id' => $agreement->id,
                'amendment_id' => $amendment->id,
                'unit_id' => (int) $data['unit_id'],
                'list_rent' => Unit::query()->findOrFail((int) $data['unit_id'])->list_rent,
                'deposit_amount' => $data['deposit_amount'],
                'start_date' => $effective->toDateString(),
                'end_date' => $agreement->end_date->toDateString(),
            ]);
            $au->save();
            foreach ((array) $data['charges'] as $charge) {
                $au->charges()->create($charge);
            }
            $this->overlap->handle($agreement); // spec §5.5: locks the unit rows, then the overlap query as a locking read
            $newUnitId = $au->id;
        }

        $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);
        if ($agreement->status !== AgreementStatus::Active) {
            throw ValidationException::withMessages(['approval' => __('The agreement is no longer active.')]);
        }

        $units = AgreementUnit::query()->where('agreement_id', $agreement->id)->orderBy('id')->lockForUpdate()->get();
        if ($amendment->type === AmendmentType::ReleaseUnit) {
            $units->firstWhere('id', $amendment->agreement_unit_id)?->forceFill(['end_date' => $effective->toDateString(), 'planned_exit_date' => $effective->toDateString()])->save();
        }
        if ($amendment->type === AmendmentType::Terminate) {
            foreach ($units->filter(fn (AgreementUnit $au) => $au->end_date->greaterThan($effective)) as $au) {
                $au->forceFill(['end_date' => $effective->toDateString(), 'planned_exit_date' => $effective->toDateString()])->save();
            }
            $agreement->forceFill(['end_date' => $effective->toDateString(), 'planned_exit_date' => $effective->toDateString()])->save();
        }

        $result = $this->rebill->handle($agreement, $effective, $approver, __(':type (:date): :reason', [
            'type' => $amendment->type->label(), 'date' => $effective->format('d/m/Y'), 'reason' => $amendment->reason,
        ]));

        if ($newUnitId !== null) {
            $deposit = Fils::fromDecimal((string) ((array) $amendment->data)['deposit_amount']);
            $this->deposit->handle($agreement->fresh(), $approver, [$newUnitId => $deposit]);
        }

        $amendment->forceFill(['applied_at' => now()])->save();

        return $result;
    }
}
```
The overlap check runs after the insert so the new row is part of the agreement's lines it checks; the unit rows it locks are new to this transaction, so a concurrent `add_unit` or submission for the same unit queues behind them and then sees this row (both its reads are locking reads).

Create `app/Approvals/AgreementAmendmentApproval.php`:
```php
<?php

namespace App\Approvals;

use App\Actions\Agreements\ApplyAmendment;
use App\Enums\AmendmentStatus;
use App\Models\AgreementAmendment;
use App\Models\Approval;
use App\Models\User;

/** Spec §8.3 items 2 (add or release a unit) and 3 (early termination). */
final class AgreementAmendmentApproval implements ApprovalHandler
{
    public function __construct(private ApplyAmendment $apply) {}

    public function creatorId(Approval $approval): int
    {
        return AgreementAmendment::query()->findOrFail($approval->approvable_id)->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $this->apply->handle(AgreementAmendment::query()->findOrFail($approval->approvable_id), $approver);
    }

    public function reject(Approval $approval, User $approver): void
    {
        AgreementAmendment::query()->lockForUpdate()->findOrFail($approval->approvable_id)
            ->forceFill(['status' => AmendmentStatus::Draft])->save();
    }

    public function summary(Approval $approval): string
    {
        $m = AgreementAmendment::query()->with(['agreement.customer', 'agreementUnit.unit'])->findOrFail($approval->approvable_id);
        $what = match ($m->type->value) {
            'release_unit' => __('release unit :u', ['u' => $m->agreementUnit?->unit->code]),
            'add_unit' => __('add a unit at :rent BHD/month', ['rent' => collect((array) ($m->data['charges'] ?? []))->firstWhere('type', 'rent')['monthly_amount'] ?? '?']),
            default => __('terminate the agreement'),
        };

        return __(':agreement (:customer): :what from :date. Reason: :reason', [
            'agreement' => $m->agreement->label(), 'customer' => $m->agreement->customer->name_en,
            'what' => $what, 'date' => $m->effective_date->format('d/m/Y'), 'reason' => $m->reason,
        ]);
    }

    public function url(Approval $approval): string
    {
        return route('agreements.show', AgreementAmendment::query()->findOrFail($approval->approvable_id)->agreement_id);
    }
}
```
In `app/Enums/ApprovalAction.php` add `case AgreementAmendment = 'agreement.amend';` (label `__('Agreement amendment')`) and `case AgreementTermination = 'agreement.terminate';` (label `__('Early termination')`), both handled by `\App\Approvals\AgreementAmendmentApproval::class`.

- [ ] **Step 7: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Agreements tests/Feature/Billing tests/Feature/Integrity
"$PHP" artisan test
```
Expected: every test passes (1100.000 = 800 + 300; 157.808 = `divRound(300 000 × 16 × 12, 365)`; 202.740 as in Task 7).

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add agreement amendments

Leasing drafts adding a unit, releasing one or terminating early; on
Management approval the dates change, the schedule is re-billed with
credit notes or a manual invoice for issued periods, and an added unit
gets its own deposit invoice. Units enter a live agreement only through
an approved amendment.
EOF
```

---

### Task 9: Amendment screens

**Spec:** §5.7, §8.2 (scope), §2 (375 px)

**Files:**
- Create: `app/Livewire/Agreements/AmendmentForm.php`, `resources/views/livewire/agreements/amendment-form.blade.php`, `tests/Feature/Agreements/AmendmentScreensTest.php`
- Modify: `app/Livewire/Agreements/Show.php`, `resources/views/livewire/agreements/show.blade.php`, `routes/property.php`

**Interfaces:**
- Consumes: `SaveAmendment`, `SubmitAmendment`, `AgreementAmendment`
- Produces: routes `agreements.amend` (`agreements/{agreement}/amend?type=`), `agreements.amend.edit` (`amendments/{amendment}/edit`); an **Amendments** section on the agreement page; a **Delete draft** action for rejected drafts

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Agreements/AmendmentScreensTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Livewire\Agreements\AmendmentForm;
use App\Livewire\Agreements\Show;
use App\Models\AgreementAmendment;
use App\Models\Approval;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $building = Building::factory()->create();
    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->leasing->buildings()->attach($building->id);
    [$u1, $u2] = Unit::factory()->for($building)->count(2)->create()->all();
    $this->spare = Unit::factory()->for($building)->create();
    $this->agreement = activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30'], [$u1, $u2]);
});

test('Leasing sends a release for approval from the form and sees it on the agreement', function () {
    $au = $this->agreement->agreementUnits()->first();

    Livewire::withQueryParams(['type' => 'release_unit'])->actingAs($this->leasing)->test(AmendmentForm::class, ['agreement' => $this->agreement])
        ->set('form.agreement_unit_id', $au->id)
        ->set('form.effective_date', '2026-12-31')
        ->set('form.reason', 'Customer downsizing')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('agreements.show', $this->agreement));

    expect(AgreementAmendment::sole()->status->value)->toBe('pending_approval')->and(Approval::sole()->action->value)->toBe('agreement.amend');

    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $this->agreement])
        ->assertSee('Release a unit')->assertSee('Pending Approval');
});

test('adding a unit takes its charges on the form', function () {
    Livewire::withQueryParams(['type' => 'add_unit'])->actingAs($this->leasing)->test(AmendmentForm::class, ['agreement' => $this->agreement])
        ->set('form.unit_id', $this->spare->id)
        ->set('form.effective_date', '2026-11-01')
        ->set('form.deposit_amount', '250')
        ->set('form.charges.0.monthly_amount', '250')
        ->set('form.reason', 'Extra store')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(AgreementAmendment::sole()->data['charges'][0]['monthly_amount'])->toBe('250.000');
});

test('errors show on the form; users outside the buildings are refused', function () {
    Livewire::withQueryParams(['type' => 'terminate'])->actingAs($this->leasing)->test(AmendmentForm::class, ['agreement' => $this->agreement])
        ->set('form.effective_date', '2028-01-01')->set('form.reason', 'x')
        ->call('submit')->assertHasErrors('form.effective_date');

    $outsider = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->actingAs($outsider)->get(route('agreements.amend', ['agreement' => $this->agreement, 'type' => 'terminate']))->assertForbidden();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Agreements/AmendmentScreensTest.php`
Expected: FAIL — `Class "App\Livewire\Agreements\AmendmentForm" not found`.

- [ ] **Step 3: The form**

Create `app/Livewire/Agreements/AmendmentForm.php`:
```php
<?php

namespace App\Livewire\Agreements;

use App\Actions\Agreements\SaveAmendment;
use App\Actions\Agreements\SubmitAmendment;
use App\Enums\AmendmentStatus;
use App\Enums\AmendmentType;
use App\Enums\ChargeType;
use App\Enums\PermissionName;
use App\Enums\TaxCategory;
use App\Livewire\Concerns\WithActor;
use App\Models\Agreement;
use App\Models\AgreementAmendment;
use App\Models\Unit;
use App\Policies\AgreementPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AmendmentForm extends Component
{
    use WithActor;

    #[Locked]
    public int $agreementId;

    #[Locked]
    public ?int $amendmentId = null;

    #[Locked]
    public string $type = 'release_unit';

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(?Agreement $agreement = null, ?AgreementAmendment $amendment = null): void
    {
        if ($amendment?->exists) {
            abort_unless($amendment->status === AmendmentStatus::Draft, 404);
            $agreement = $amendment->agreement;
            $this->amendmentId = $amendment->id;
            $this->type = $amendment->type->value;
            $this->form = [
                'effective_date' => $amendment->effective_date->toDateString(), 'reason' => $amendment->reason,
                'agreement_unit_id' => $amendment->agreement_unit_id, ...((array) $amendment->data),
            ];
        } else {
            $this->type = AmendmentType::tryFrom((string) request()->query('type'))?->value ?? abort(404);
            $this->form = ['effective_date' => '', 'reason' => '', 'deposit_amount' => '0',
                'charges' => [['type' => 'rent', 'description' => '', 'monthly_amount' => '', 'tax_category' => 'exempt']]];
        }

        abort_unless($agreement !== null && $this->actor()->can(PermissionName::AgreementsManage) && AgreementPolicy::allUnitsInScope($this->actor(), $agreement), 403);
        $this->agreementId = $agreement->id;
    }

    public function addCharge(): void
    {
        $this->form['charges'][] = ['type' => 'service_charge', 'description' => '', 'monthly_amount' => '', 'tax_category' => 'exempt'];
    }

    private function save(SaveAmendment $save): AgreementAmendment
    {
        try {
            $amendment = $save->handle($this->actor(), Agreement::findOrFail($this->agreementId),
                $this->amendmentId ? AgreementAmendment::findOrFail($this->amendmentId) : null, [...$this->form, 'type' => $this->type]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
        }
        $this->amendmentId = $amendment->id;

        return $amendment;
    }

    public function saveDraft(SaveAmendment $save): void
    {
        $this->save($save);
        $this->redirectRoute('agreements.show', $this->agreementId, navigate: true);
    }

    public function submit(SaveAmendment $save, SubmitAmendment $submit): void
    {
        $submit->handle($this->actor(), $this->save($save));
        $this->redirectRoute('agreements.show', $this->agreementId, navigate: true);
    }

    public function render(): View
    {
        $agreement = Agreement::with('agreementUnits.unit.building')->findOrFail($this->agreementId);

        return view('livewire.agreements.amendment-form', [
            'agreement' => $agreement,
            'typeLabel' => AmendmentType::from($this->type)->label(),
            'units' => $this->type === 'add_unit'
                ? Unit::query()->visibleTo($this->actor())->whereNotIn('id', $agreement->agreementUnits->pluck('unit_id'))->with('building:id,code')->orderBy('code')->get()
                : collect(),
            'chargeTypes' => ChargeType::cases(),
            'taxCategories' => TaxCategory::cases(),
        ])->title($agreement->label());
    }
}
```
Create `resources/views/livewire/agreements/amendment-form.blade.php`:
```blade
<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $typeLabel }} — {{ $agreement->label() }}</flux:heading>
    <flux:text>{{ __('Management approves. On approval the schedule is re-billed from the effective date; issued periods are credited or invoiced.') }}</flux:text>

    <form wire:submit="submit" class="space-y-4">
        @if ($type === 'release_unit')
            <flux:select wire:model="form.agreement_unit_id" :label="__('Unit to release')">
                <option value="">{{ __('Choose…') }}</option>
                @foreach ($agreement->agreementUnits as $au)<option value="{{ $au->id }}">{{ $au->unit->building->code }} / {{ $au->unit->code }}</option>@endforeach
            </flux:select>
        @endif
        @if ($type === 'add_unit')
            <flux:select wire:model="form.unit_id" :label="__('Unit to add')">
                <option value="">{{ __('Choose…') }}</option>
                @foreach ($units as $u)<option value="{{ $u->id }}">{{ $u->building->code }} / {{ $u->code }}</option>@endforeach
            </flux:select>
            <flux:input wire:model="form.deposit_amount" inputmode="decimal" :label="__('Deposit (BHD)')" />
            @foreach ($form['charges'] ?? [] as $i => $charge)
                <div class="grid gap-3 sm:grid-cols-3" wire:key="ch-{{ $i }}">
                    <flux:select wire:model="form.charges.{{ $i }}.type" :label="__('Charge')">
                        @foreach ($chargeTypes as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
                    </flux:select>
                    <flux:input wire:model="form.charges.{{ $i }}.monthly_amount" inputmode="decimal" :label="__('Monthly (BHD)')" />
                    <flux:select wire:model="form.charges.{{ $i }}.tax_category" :label="__('Tax')">
                        @foreach ($taxCategories as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach
                    </flux:select>
                </div>
            @endforeach
            <flux:button size="sm" wire:click="addCharge">{{ __('Add charge') }}</flux:button>
            <flux:error name="form.charges" />
        @endif
        <flux:input wire:model="form.effective_date" type="date" :label="$type === 'add_unit' ? __('Let from') : __('Last day of occupancy')" />
        <flux:textarea wire:model="form.reason" :label="__('Reason')" rows="3" />
        @foreach (['type', 'agreement_unit_id', 'unit_id', 'deposit_amount', 'effective_date', 'reason'] as $f)<flux:error name="form.{{ $f }}" />@endforeach
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="saveDraft">{{ __('Save draft') }}</flux:button>
            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="submit">{{ __('Send for approval') }}</flux:button>
        </div>
    </form>
</section>
```
In `routes/property.php`, before `agreements/{agreement}`:
```php
    Route::livewire('agreements/{agreement}/amend', Agreements\AmendmentForm::class)->middleware('can:agreements.manage')->name('agreements.amend');
    Route::livewire('amendments/{amendment}/edit', Agreements\AmendmentForm::class)->middleware('can:agreements.manage')->name('agreements.amend.edit');
```

- [ ] **Step 4: The agreement page**

In `app/Livewire/Agreements/Show.php` add (import `App\Models\AgreementAmendment`, `App\Enums\AmendmentStatus`):
```php
    public function deleteAmendmentDraft(int $amendmentId): void
    {
        $amendment = AgreementAmendment::query()->where('agreement_id', $this->agreementId)->findOrFail($amendmentId);
        abort_unless($this->actor()->can('update', $this->agreement()) && $amendment->status === AmendmentStatus::Draft, 403);
        $amendment->delete(); // drafts only: the trigger refuses anything else

        Flux::toast(text: __('Draft amendment deleted.'));
    }
```
and in `render()` add `'amendments' => $agreement->amendments()->with('creator:id,name')->latest('id')->get(),` and `'canAmend' => $this->actor()->can('update', $agreement) && $agreement->status->value === 'active',`.

In `resources/views/livewire/agreements/show.blade.php`, in the header button group add:
```blade
            @if ($canAmend)
                <flux:dropdown>
                    <flux:button icon:trailing="chevron-down">{{ __('Amend') }}</flux:button>
                    <flux:menu>
                        <flux:menu.item :href="route('agreements.amend', ['agreement' => $agreement, 'type' => 'add_unit'])" wire:navigate>{{ __('Add a unit') }}</flux:menu.item>
                        <flux:menu.item :href="route('agreements.amend', ['agreement' => $agreement, 'type' => 'release_unit'])" wire:navigate>{{ __('Release a unit') }}</flux:menu.item>
                        <flux:menu.item :href="route('agreements.amend', ['agreement' => $agreement, 'type' => 'terminate'])" wire:navigate>{{ __('Early termination') }}</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            @endif
```
and after the units table:
```blade
    @if ($amendments->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg">{{ __('Amendments') }}</flux:heading>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($amendments as $m)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm" wire:key="am-{{ $m->id }}">
                        <span>{{ $m->type->label() }} · {{ __('from :d', ['d' => $m->effective_date->format('d/m/Y')]) }} · {{ $m->status->label() }} · {{ $m->reason }}</span>
                        @if ($canManage && $m->status->value === 'draft')
                            <span class="flex gap-2">
                                <flux:button size="sm" :href="route('agreements.amend.edit', $m)" wire:navigate>{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="deleteAmendmentDraft({{ $m->id }})" wire:confirm="{{ __('Delete this draft amendment?') }}">{{ __('Delete') }}</flux:button>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
```
Flux's free `flux:dropdown` and `flux:menu` are part of the free set; if your installed version lacks them, use three plain buttons instead.

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Agreements
"$PHP" artisan test
grep -rn "panel:documentable" resources/views   # must print nothing
```
Expected: every test passes.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the amendment screens

From an active agreement, Leasing opens Add a unit, Release a unit or
Early termination, saves a draft or sends it for approval, and sees each
amendment's status on the agreement page.
EOF
```

---
### Task 10: Move-outs, the closing check and the 02:00 job

**Spec:** §5.9 (move-out per agreement unit or all at once with `agreements.manage`, units in scope: `move_out_date` ≤ today, readings, notes, photos on the agreement unit; the draft deposit settlement is created when occupancy has ended: at the move-out if `end_date ≤ move_out_date`, otherwise by the 02:00 job on the day after `end_date`), §5.4 (closing: active | expired with end_date passed and every unit moved out → `closed`, or `terminated` if a terminate amendment was approved; every unit carried or moved out with the renewal active → `renewed`; otherwise active → `expired`; the check runs on each move-out and at 02:00; a move-out before end_date neither ends billing nor frees the unit before end_date; an expired agreement without a move-out is an overstay), §12 (02:00 job), §9.1 (`move_out_photo` documents on agreement units)

**Files:**
- Create: `app/Actions/Agreements/RecordMoveOut.php`, `app/Policies/AgreementUnitPolicy.php`, `tests/Feature/Agreements/MoveOutTest.php`, `tests/Feature/Agreements/CloseAgreementsTest.php`
- Modify: `app/Actions/Agreements/ExpireAgreements.php`, `app/Console/Commands/ExpireAgreementsCommand.php`, `app/Models/AgreementUnit.php`, `app/Livewire/Documents/Panel.php`, `app/Livewire/Agreements/Show.php`, `resources/views/livewire/agreements/show.blade.php`

**Interfaces:**
- Consumes: `CreateDepositSettlement`, `AgreementAmendment` (approved terminate), `Agreement::previous_agreement_id`
- Produces:
  - `RecordMoveOut::handle(User $actor, Agreement $agreement, ?AgreementUnit $unit, string $moveOutDate, ?string $readings, ?string $notes): void`
  - `ExpireAgreements::__invoke(): int` (agreements whose status changed) and `ExpireAgreements::checkOne(int $agreementId): ?AgreementStatus` (inside the caller's transaction; the new status or null)
  - `AgreementUnit::documents()`; `AgreementUnitPolicy::{view, update}` (delegating to the agreement)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Agreements/MoveOutTest.php`:
```php
<?php

use App\Actions\Agreements\RecordMoveOut;
use App\Enums\RoleName;
use App\Livewire\Agreements\Show;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositSettlement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $building = Building::factory()->create();
    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->leasing->buildings()->attach($building->id);
    [$this->u1, $this->u2] = Unit::factory()->for($building)->count(2)->create()->all();
    $this->agreement = activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30', 'created_by' => $this->leasing->id], [$this->u1, $this->u2]);
});

test('moving out after the end date records it, drafts the settlement, and closes the agreement once every unit is out', function () {
    $au1 = $this->agreement->agreementUnits()->where('unit_id', $this->u1->id)->sole();
    app(RecordMoveOut::class)->handle($this->leasing, $this->agreement, $au1, '2026-10-02', 'Electric 10432', 'Keys returned');

    expect($au1->fresh()->move_out_date->toDateString())->toBe('2026-10-02')
        ->and($au1->fresh()->move_out_readings)->toBe('Electric 10432')
        ->and($au1->fresh()->move_out_recorded_by)->toBe($this->leasing->id)
        ->and(DepositSettlement::sole()->units->sole()->agreement_unit_id)->toBe($au1->id)
        ->and($this->agreement->fresh()->status->value)->toBe('expired'); // past its end, unit 2 still in: an overstay

    app(RecordMoveOut::class)->handle($this->leasing, $this->agreement, null, '2026-10-04', null, null); // everyone else
    expect($this->agreement->fresh()->status->value)->toBe('closed')
        ->and(DepositSettlement::count())->toBe(2);
});

test('move-outs are validated and scoped', function () {
    expect(fn () => app(RecordMoveOut::class)->handle($this->leasing, $this->agreement, null, '2026-10-06', null, null))->toThrow(ValidationException::class);
    expect(fn () => app(RecordMoveOut::class)->handle($this->leasing, $this->agreement, null, '2025-09-30', null, null))->toThrow(ValidationException::class);

    $au1 = $this->agreement->agreementUnits()->where('unit_id', $this->u1->id)->sole();
    app(RecordMoveOut::class)->handle($this->leasing, $this->agreement, $au1, '2026-10-02', null, null);
    expect(fn () => app(RecordMoveOut::class)->handle($this->leasing, $this->agreement, $au1, '2026-10-03', null, null))->toThrow(ValidationException::class);

    $outsider = User::factory()->create()->assignRole(RoleName::Leasing);
    expect(fn () => app(RecordMoveOut::class)->handle($outsider, $this->agreement, null, '2026-10-02', null, null))->toThrow(AuthorizationException::class);
});

test('the agreement page records a move-out and shows the photo panel for the unit', function () {
    $au1 = $this->agreement->agreementUnits()->where('unit_id', $this->u1->id)->sole();

    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $this->agreement])
        ->set('moveOutTarget', (string) $au1->id)->set('moveOutDate', '2026-10-03')->set('moveOutNotes', 'Clean')
        ->call('recordMoveOut')->assertHasNoErrors()
        ->assertSee('Move-out photos — '.$this->u1->code); // the unit's own photo panel (the agreement already has one)

    expect($au1->fresh()->move_out_notes)->toBe('Clean');
});
```
Create `tests/Feature/Agreements/CloseAgreementsTest.php`:
```php
<?php

use App\Actions\Agreements\ExpireAgreements;
use App\Models\AgreementAmendment;
use App\Models\Customer;
use App\Models\DepositSettlement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 02:00', 'Asia/Bahrain'));
    $this->make = fn (array $over = []) => activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30', ...$over], [Unit::factory()->create()]);
});

test('an overstay expires and stays expired; a moved-out one closes; a terminated one terminates', function () {
    $overstay = ($this->make)();
    $out = ($this->make)();
    $out->agreementUnits()->sole()->forceFill(['move_out_date' => '2026-09-30'])->save();
    $terminated = ($this->make)();
    $terminated->agreementUnits()->sole()->forceFill(['move_out_date' => '2026-09-30'])->save();
    DB::table('agreement_amendments')->insert(['agreement_id' => $terminated->id, 'type' => 'terminate', 'effective_date' => '2026-09-30', 'reason' => 'x',
        'status' => 'approved', 'applied_at' => now(), 'created_by' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]);

    expect(app(ExpireAgreements::class)())->toBe(3)
        ->and($overstay->fresh()->status->value)->toBe('expired')
        ->and($out->fresh()->status->value)->toBe('closed')
        ->and($terminated->fresh()->status->value)->toBe('terminated')
        ->and(app(ExpireAgreements::class)())->toBe(0);
});

test('a unit that moved out before its end date gets its settlement the day after the end date', function () {
    $agreement = ($this->make)(['end_date' => '2026-10-05']);
    $agreement->agreementUnits()->sole()->forceFill(['move_out_date' => '2026-10-01'])->save();

    app(ExpireAgreements::class)();
    expect(DepositSettlement::count())->toBe(0); // still let until the end of today

    $this->travelTo(CarbonImmutable::parse('2026-10-06 02:00', 'Asia/Bahrain'));
    app(ExpireAgreements::class)();
    expect(DepositSettlement::sole()->agreement_id)->toBe($agreement->id)
        ->and($agreement->fresh()->status->value)->toBe('closed');

    $this->artisan('rms:agreements:expire')->assertSuccessful();
    expect(DepositSettlement::count())->toBe(1);
});
```
In `tests/Feature/Agreements/ExpireAgreementsTest.php` nothing changes: an active agreement past its end with no move-out still expires.

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Agreements/MoveOutTest.php tests/Feature/Agreements/CloseAgreementsTest.php`
Expected: FAIL — `Class "App\Actions\Agreements\RecordMoveOut" not found`.

- [ ] **Step 3: The closing check and the job**

Replace `app/Actions/Agreements/ExpireAgreements.php` with:
```php
<?php

namespace App\Actions\Agreements;

use App\Actions\Deposits\CreateDepositSettlement;
use App\Enums\AgreementStatus;
use App\Enums\AmendmentStatus;
use App\Enums\AmendmentType;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\DepositSettlementUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The 02:00 job (spec §12) and the closing check (spec §5.4), which RecordMoveOut also runs. Idempotent.
 * Occupancy ends at the later of move_out_date and end_date; the job drafts the settlement the day after (spec §5.9).
 */
final class ExpireAgreements
{
    public function __construct(private CreateDepositSettlement $settlement) {}

    public function __invoke(): int
    {
        $today = now('Asia/Bahrain')->toDateString();

        $changed = 0;
        $due = Agreement::query()->whereIn('status', [AgreementStatus::Active, AgreementStatus::Expired])->where('end_date', '<', $today)->orderBy('id')->pluck('id');
        foreach ($due as $id) {
            $changed += DB::transaction(fn () => $this->checkOne($id) !== null ? 1 : 0, attempts: 3);
        }

        // Units whose occupancy ended before today and have no settlement yet (spec §5.9).
        $ended = AgreementUnit::query()
            ->whereNotNull('move_out_date')
            ->whereRaw('GREATEST(move_out_date, end_date) < ?', [$today])
            ->whereNotIn('id', DepositSettlementUnit::query()->select('agreement_unit_id'))
            ->whereHas('agreement', fn ($q) => $q->whereNotIn('status', [AgreementStatus::Draft, AgreementStatus::PendingApproval]))
            ->get()->groupBy('agreement_id');
        foreach ($ended as $agreementId => $units) {
            DB::transaction(function () use ($agreementId, $units) {
                $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreementId);
                // ponytail: the agreement's creator stands in as the settlement's creator for the nightly job; a system user arrives with M5's jobs.
                $this->settlement->handle($agreement, $units->pluck('id')->all(), User::query()->findOrFail($agreement->created_by));
            }, attempts: 3);
        }

        return $changed;
    }

    /** Spec §5.4. Inside the caller's transaction. Returns the new status, or null when nothing changes. */
    public function checkOne(int $agreementId): ?AgreementStatus
    {
        $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreementId);
        if (! in_array($agreement->status, [AgreementStatus::Active, AgreementStatus::Expired], true)
            || $agreement->end_date->toDateString() >= now('Asia/Bahrain')->toDateString()) {
            return null;
        }

        $units = $agreement->agreementUnits()->get(['id', 'unit_id', 'move_out_date']);
        $renewal = Agreement::query()->where('previous_agreement_id', $agreement->id)->where('status', AgreementStatus::Active)->first();
        $carried = $renewal ? $renewal->agreementUnits()->pluck('unit_id')->all() : [];

        $done = $units->every(fn ($au) => $au->move_out_date !== null || in_array($au->unit_id, $carried, true));

        $status = match (true) {
            $done && $renewal !== null && $units->contains(fn ($au) => in_array($au->unit_id, $carried, true)) => AgreementStatus::Renewed,
            $done && $agreement->amendments()->where('type', AmendmentType::Terminate)->where('status', AmendmentStatus::Approved)->exists() => AgreementStatus::Terminated,
            $done => AgreementStatus::Closed,
            $agreement->status === AgreementStatus::Active => AgreementStatus::Expired,
            default => null,
        };

        if ($status !== null) {
            $agreement->forceFill(['status' => $status])->save();
        }

        return $status;
    }
}
```
In `app/Console/Commands/ExpireAgreementsCommand.php` change the output line to say "agreements expired, closed, terminated or renewed" (keep the signature).

- [ ] **Step 4: Move-out**

Create `app/Actions/Agreements/RecordMoveOut.php`:
```php
<?php

namespace App\Actions\Agreements;

use App\Actions\Deposits\CreateDepositSettlement;
use App\Enums\AgreementStatus;
use App\Enums\PermissionName;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\Unit;
use App\Models\User;
use App\Policies\AgreementPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Spec §5.9. Without a unit, every unit not yet moved out is recorded. Ends neither billing nor occupancy before end_date. */
final class RecordMoveOut
{
    public function __construct(private CreateDepositSettlement $settlement, private ExpireAgreements $close) {}

    public function handle(User $actor, Agreement $agreement, ?AgreementUnit $unit, string $moveOutDate, ?string $readings, ?string $notes): void
    {
        $inScope = $unit
            ? $unit->agreement_id === $agreement->id && Unit::query()->visibleTo($actor)->whereKey($unit->unit_id)->exists()
            : AgreementPolicy::allUnitsInScope($actor, $agreement);
        if (! $actor->can(PermissionName::AgreementsManage) || ! $inScope) {
            throw new AuthorizationException;
        }

        Validator::make(['move_out_date' => $moveOutDate, 'readings' => $readings, 'notes' => $notes], [
            'move_out_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'readings' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        DB::transaction(function () use ($actor, $agreement, $unit, $moveOutDate, $readings, $notes) {
            $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);
            if (! in_array($agreement->status, [AgreementStatus::Active, AgreementStatus::Expired], true)) {
                throw ValidationException::withMessages(['move_out_date' => __('Move-outs are recorded on active or expired agreements.')]);
            }

            $targets = AgreementUnit::query()->where('agreement_id', $agreement->id)
                ->when($unit, fn ($q) => $q->whereKey($unit->id), fn ($q) => $q->whereNull('move_out_date'))
                ->orderBy('id')->lockForUpdate()->get();
            if ($targets->isEmpty() || $targets->contains(fn (AgreementUnit $au) => $au->move_out_date !== null)) {
                throw ValidationException::withMessages(['move_out_date' => __('That move-out is already recorded.')]);
            }
            if ($targets->contains(fn (AgreementUnit $au) => $moveOutDate < $au->start_date->toDateString())) {
                throw ValidationException::withMessages(['move_out_date' => __('The move-out cannot be before the unit\'s start date.')]);
            }

            foreach ($targets as $au) {
                $au->forceFill(['move_out_date' => $moveOutDate, 'move_out_readings' => $readings, 'move_out_notes' => $notes, 'move_out_recorded_by' => $actor->id])->save();
            }

            // Occupancy has ended for units already past their end date: their settlement is drafted now (spec §5.9).
            $ended = $targets->filter(fn (AgreementUnit $au) => $au->end_date->toDateString() <= $moveOutDate)->pluck('id')->all();
            if ($ended !== []) {
                $this->settlement->handle($agreement, $ended, $actor);
            }

            $this->close->checkOne($agreement->id); // spec §5.4: the closing check runs on each move-out
        }, attempts: 3);
    }
}
```
In `app/Models/AgreementUnit.php` add:
```php
    /** @return MorphMany<Document, $this> move-out photos (spec §5.9) */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
```
Create `app/Policies/AgreementUnitPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Models\AgreementUnit;
use App\Models\User;

/** Documents on an agreement unit follow its agreement (spec §9.1). */
class AgreementUnitPolicy
{
    public function view(User $user, AgreementUnit $unit): bool
    {
        return $user->can('view', $unit->agreement);
    }

    public function update(User $user, AgreementUnit $unit): bool
    {
        return $user->can('update', $unit->agreement);
    }
}
```
Add `AgreementUnit::class` to `Documents\Panel::ALLOWED`. The panel's category choices already include `move_out_photo`.

- [ ] **Step 5: The agreement page**

In `app/Livewire/Agreements/Show.php` add (import `App\Actions\Agreements\RecordMoveOut`):
```php
    public string $moveOutTarget = '';

    public string $moveOutDate = '';

    public string $moveOutReadings = '';

    public string $moveOutNotes = '';

    public function recordMoveOut(RecordMoveOut $moveOut): void
    {
        $agreement = $this->agreement();
        $unit = $this->moveOutTarget !== '' ? AgreementUnit::query()->where('agreement_id', $agreement->id)->findOrFail((int) $this->moveOutTarget) : null;

        try {
            $moveOut->handle($this->actor(), $agreement, $unit, $this->moveOutDate, $this->moveOutReadings ?: null, $this->moveOutNotes ?: null);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['moveOutDate' => \Illuminate\Support\Arr::flatten($e->errors())]);
        }

        $this->reset('moveOutTarget', 'moveOutDate', 'moveOutReadings', 'moveOutNotes');
        Flux::toast(variant: 'success', text: __('Move-out recorded.'));
    }
```
(import `Illuminate\Support\Arr`.) In the view, after the Record notice fieldset (same `@if` guard):
```blade
        <flux:fieldset>
            <flux:legend>{{ __('Record move-out') }}</flux:legend>
            <form wire:submit="recordMoveOut" class="mt-2 grid gap-3 sm:grid-cols-2 sm:items-end">
                <flux:select wire:model="moveOutTarget" :label="__('For')">
                    <option value="">{{ __('Every unit still in') }}</option>
                    @foreach ($agreement->agreementUnits->whereNull('move_out_date') as $au)<option value="{{ $au->id }}">{{ $au->unit->building->code }} / {{ $au->unit->code }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="moveOutDate" type="date" :label="__('Moved out on')" />
                <flux:textarea wire:model="moveOutReadings" :label="__('Meter readings')" rows="2" />
                <flux:textarea wire:model="moveOutNotes" :label="__('Notes')" rows="2" />
                <flux:error name="moveOutDate" />
                <flux:button type="submit" class="sm:col-span-2 sm:justify-self-start">{{ __('Record move-out') }}</flux:button>
            </form>
        </flux:fieldset>
```
and in the units table add a **Move-out** column showing `{{ $au->move_out_date?->format('d/m/Y') ?? '—' }}`. Below the table, for every unit with a move-out:
```blade
    @foreach ($agreement->agreementUnits->whereNotNull('move_out_date') as $au)
        <div class="space-y-1" wire:key="mo-{{ $au->id }}">
            <flux:heading size="sm">{{ __('Move-out photos — :u', ['u' => $au->unit->code]) }}</flux:heading>
            @if ($au->move_out_notes || $au->move_out_readings)<flux:text size="sm">{{ $au->move_out_readings }} {{ $au->move_out_notes }}</flux:text>@endif
            <livewire:documents.panel :documentable="$au" :key="'docs-au-'.$au->id" />
        </div>
    @endforeach
```
Check that the tag keeps the space before `:documentable` (the recurring defect).

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Agreements tests/Feature/Deposits tests/Feature/Documents
"$PHP" artisan test
grep -rn "panel:documentable" resources/views   # must print nothing
```
Expected: every test passes, including M2's `ExpireAgreementsTest`.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Record move-outs and close agreements at 02:00

A move-out is recorded per unit or for every unit still in, with
readings, notes and photos. When a unit's occupancy has ended its
deposit settlement is drafted, and an agreement past its end closes,
terminates or renews once every unit is out or carried.
EOF
```

---

### Task 11: Renewals with the deposit carried over

**Spec:** §5.8 (Renew creates a draft from an active or expired agreement: same customer, a subset of its units, charges copied, `start_date` = old end + 1, `previous_agreement_id`; editable; normal submit → approval; carried units keep `effective_end = end_date` on the old agreement so the renewal passes the overlap check even after expiry; an uncarried unit needs its own move-out; on activation each carried unit gets `transfer_out` of min(held, new deposit) and an equal `transfer_in`; any unpaid balance on the old deposit line is credited by a credit note in the same approval; any amount still held after the transfer is refunded through a draft deposit settlement on the old agreement; the renewal's deposit invoice bills only what is still missing; old → renewed), §6.2 (renewal deposit invoice), §7.6 (`transfer_out` copies from the unit's movements, `transfer_in` from its `transfer_out`), §14 flow 10, plan ruling 9

**Files:**
- Create: `app/Actions/Agreements/{RenewAgreement,TransferDeposits}.php`, `tests/Feature/Agreements/RenewalTest.php`
- Modify: `app/Actions/Agreements/ActivateAgreement.php`, `app/Actions/Agreements/EnsureNoAgreementOverlap.php`, `app/Livewire/Agreements/Show.php`, `resources/views/livewire/agreements/show.blade.php`

**Interfaces:**
- Consumes: `SaveAgreement`, `CreateDepositInvoice(…, $amounts)`, `CreateDepositSettlement`, `BuildCreditNote`, `IssueCreditNote`, `DepositMovement::{heldFils, ownerContractFor}`, `ExpireAgreements::checkOne`
- Produces:
  - `RenewAgreement::handle(User $actor, Agreement $old, array $unitIds, string $endDate): Agreement` (a draft)
  - `TransferDeposits::handle(Agreement $renewal, User $approver): void` — internal (activation)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Agreements/RenewalTest.php`:
```php
<?php

use App\Actions\Agreements\ExpireAgreements;
use App\Actions\Agreements\RecordMoveOut;
use App\Actions\Agreements\RenewAgreement;
use App\Actions\Agreements\SaveAgreement;
use App\Actions\Agreements\SubmitAgreement;
use App\Actions\Approvals\DecideApproval;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\DepositSettlement;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    app(EnsureDefaultContractTemplate::class)();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $building = Building::factory()->create();
    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->leasing->buildings()->attach($building->id);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->customer = Customer::factory()->create();
    [$this->u1, $this->u2] = Unit::factory()->for($building)->count(2)->create()->all();
    $this->old = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30', 'created_by' => $this->leasing->id], [$this->u1, $this->u2]); // deposits 400 each
    $this->oldAu = fn (Unit $u) => $this->old->agreementUnits()->where('unit_id', $u->id)->sole();
    $this->depositPaid = function (string $paid) {
        issuedInvoice($this->customer, [
            ['net' => '400.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => ($this->oldAu)($this->u1)],
            ['net' => '400.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => ($this->oldAu)($this->u2)],
        ], '2025-10-01', $this->old);
        app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => $paid]);
    };
    app(ExpireAgreements::class)(); // the old agreement expired on 30 September
    $this->renew = function (string $deposit) {
        $draft = app(RenewAgreement::class)->handle($this->leasing, $this->old->fresh(), [$this->u1->id], '2027-09-30');
        $data = ['customer_id' => $this->customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30', 'frequency' => 'monthly',
            'units' => [['unit_id' => $this->u1->id, 'deposit_amount' => $deposit, 'charges' => [['type' => 'rent', 'monthly_amount' => '420.000', 'tax_category' => 'exempt']]]]];
        app(SaveAgreement::class)->handle($this->leasing, $draft, $data); // rent and deposit edited on the draft
        app(DecideApproval::class)->handle($this->management, app(SubmitAgreement::class)->handle($this->leasing, $draft->fresh()), true);

        return $draft->fresh();
    };
});

test('§14 flow 10: a renewal after expiry passes the overlap check and carries the deposit', function () {
    ($this->depositPaid)('800.000');
    expect($this->old->fresh()->status->value)->toBe('expired');

    $renewal = ($this->renew)('500.000');
    $newAu = $renewal->agreementUnits()->sole();

    expect($renewal->status->value)->toBe('active')
        ->and($renewal->previous_agreement_id)->toBe($this->old->id)
        ->and($renewal->start_date->toDateString())->toBe('2026-10-01')
        ->and(DepositMovement::heldFils(($this->oldAu)($this->u1)->id))->toBe(0)
        ->and(DepositMovement::heldFils($newAu->id))->toBe(400_000)
        ->and(DepositMovement::where('type', 'transfer_out')->sole()->amount)->toBe('-400.000')
        ->and(Invoice::where('agreement_id', $renewal->id)->where('type', 'deposit')->sole()->total)->toBe('100.000'); // 500 − 400 carried

    // Unit 2 was not carried: it needs its own move-out, which drafts its settlement and lets the old agreement renew.
    expect($this->old->fresh()->status->value)->toBe('expired');
    app(RecordMoveOut::class)->handle($this->leasing, $this->old->fresh(), ($this->oldAu)($this->u2), '2026-10-03', null, null);
    expect($this->old->fresh()->status->value)->toBe('renewed')
        ->and(DepositSettlement::sole()->units->sole()->agreement_unit_id)->toBe(($this->oldAu)($this->u2)->id)
        ->and(app(IntegrityCheck::class)->run())->toBe([]);
});

test('a smaller new deposit leaves the rest on the old unit for a settlement', function () {
    ($this->depositPaid)('800.000');

    ($this->renew)('300.000');

    expect(DepositMovement::heldFils(($this->oldAu)($this->u1)->id))->toBe(100_000)
        ->and(DepositSettlement::sole()->units->sole()->agreement_unit_id)->toBe(($this->oldAu)($this->u1)->id)
        ->and(Invoice::where('type', 'deposit')->where('agreement_id', '!=', $this->old->id)->count())->toBe(0); // fully covered
});

test('an unpaid old deposit balance is credited and only what was paid carries over', function () {
    ($this->depositPaid)('650.000'); // 400 on unit 1 is split by balance: 325 each

    $renewal = ($this->renew)('400.000');

    $cn = Invoice::where('type', 'credit_note')->sole();
    expect($cn->total)->toBe('75.000')                 // unit 1's unpaid 75 on the old deposit line
        ->and(DepositMovement::heldFils($renewal->agreementUnits()->sole()->id))->toBe(325_000)
        ->and(Invoice::where('agreement_id', $renewal->id)->where('type', 'deposit')->sole()->total)->toBe('75.000');
});

test('renewing needs an active or expired agreement, carried units of it, and no other renewal', function () {
    expect(fn () => app(RenewAgreement::class)->handle($this->leasing, $this->old, [Unit::factory()->create()->id], '2027-09-30'))->toThrow(ValidationException::class);
    app(RenewAgreement::class)->handle($this->leasing, $this->old, [$this->u1->id], '2027-09-30');
    expect(fn () => app(RenewAgreement::class)->handle($this->leasing, $this->old, [$this->u2->id], '2027-09-30'))->toThrow(ValidationException::class);
});
```
The 650.000 case: one deposit invoice of 800 split by balance pays 325 on each line, so unit 1's line has 75 unpaid; that 75 is credited, 325 carries, and the new 400 deposit bills the missing 75.

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Agreements/RenewalTest.php`
Expected: FAIL — `Class "App\Actions\Agreements\RenewAgreement" not found`.

- [ ] **Step 3: The overlap check knows its own renewal**

At submit the renewal is still draft, so §5.5's "carried into a pending or active renewal" does not yet hold for the agreement being checked. In `app/Actions/Agreements/EnsureNoAgreementOverlap.php`, replace the clash query's `->whereRaw('('.AgreementUnit::effectiveEndSql('au').') >= ?', [$today, $line->start_date])` with:
```php
                // Spec §5.8: the agreement being renewed holds a carried unit only to its end_date (or a later move-out).
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('a.id', '<>', $agreement->previous_agreement_id ?? 0)
                        ->whereRaw('('.AgreementUnit::effectiveEndSql('au').') >= ?', [$today, $line->start_date]))
                    ->orWhere(fn ($q) => $q->where('a.id', $agreement->previous_agreement_id ?? 0)
                        ->whereRaw('GREATEST(au.end_date, COALESCE(au.move_out_date, au.end_date)) >= ?', [$line->start_date])))
```
The query stays a single locking read; M2's overlap tests must pass unchanged.

- [ ] **Step 4: Renew, transfer, activate**

Create `app/Actions/Agreements/RenewAgreement.php`:
```php
<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\User;
use App\Policies\AgreementPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §5.8: a draft renewal of a subset of the units, charges and deposits copied, starting the day after the old end. */
final class RenewAgreement
{
    public function __construct(private SaveAgreement $save) {}

    /** @param  list<int>  $unitIds */
    public function handle(User $actor, Agreement $old, array $unitIds, string $endDate): Agreement
    {
        if (! $actor->can('create', Agreement::class) || ! AgreementPolicy::allUnitsInScope($actor, $old)) {
            throw new AuthorizationException;
        }
        if (! in_array($old->status, [AgreementStatus::Active, AgreementStatus::Expired], true)) {
            throw ValidationException::withMessages(['units' => __('Only an active or expired agreement can be renewed.')]);
        }

        $old->load('agreementUnits.charges');
        $carried = $old->agreementUnits->whereIn('unit_id', $unitIds);
        if ($unitIds === [] || $carried->count() !== count(array_unique($unitIds))) {
            throw ValidationException::withMessages(['units' => __('Choose units of this agreement to carry into the renewal.')]);
        }

        return DB::transaction(function () use ($actor, $old, $carried, $endDate) {
            Agreement::query()->lockForUpdate()->findOrFail($old->id); // serialises two renewals of the same agreement
            if (Agreement::query()->where('previous_agreement_id', $old->id)->whereIn('status', [AgreementStatus::Draft, AgreementStatus::PendingApproval, AgreementStatus::Active])->exists()) {
                throw ValidationException::withMessages(['units' => __('This agreement already has a renewal.')]);
            }

            $draft = $this->save->handle($actor, null, [
                'customer_id' => $old->customer_id,
                'start_date' => $old->end_date->addDay()->toDateString(),
                'end_date' => $endDate,
                'frequency' => $old->frequency->value,
                'billing_day' => $old->billing_day,
                'grace_days' => $old->grace_days,
                'notice_period_days' => $old->notice_period_days,
                'units' => $carried->map(fn ($au) => [
                    'unit_id' => $au->unit_id,
                    'deposit_amount' => $au->deposit_amount,
                    'charges' => $au->charges->map(fn ($c) => [
                        'type' => $c->type->value, 'description' => $c->description, 'monthly_amount' => $c->monthly_amount, 'tax_category' => $c->tax_category->value,
                    ])->values()->all(),
                ])->values()->all(),
            ]);
            $draft->forceFill(['previous_agreement_id' => $old->id])->save(); // allowed while draft (spec §8.5)

            return $draft;
        }, attempts: 3);
    }
}
```
`SaveAgreement` keeps `previous_agreement_id` when the draft is edited later (it never writes that column).

Create `app/Actions/Agreements/TransferDeposits.php`:
```php
<?php

namespace App\Actions\Agreements;

use App\Actions\Billing\BuildCreditNote;
use App\Actions\Billing\CreateDepositInvoice;
use App\Actions\Billing\IssueCreditNote;
use App\Actions\Deposits\CreateDepositSettlement;
use App\Enums\DepositMovementType;
use App\Enums\InvoiceChargeType;
use App\Enums\InvoiceStatus;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\DepositMovement;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Spec §5.8 on a renewal's activation (plan ruling 9): credit any unpaid old deposit, carry min(held, new deposit), leave
 * the rest for a settlement on the old agreement, and invoice only what is still missing. Internal; the customer is locked.
 */
final class TransferDeposits
{
    public function __construct(
        private BuildCreditNote $buildCreditNote,
        private IssueCreditNote $issueCreditNote,
        private CreateDepositSettlement $settlement,
        private CreateDepositInvoice $depositInvoice,
    ) {}

    public function handle(Agreement $renewal, User $approver): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TransferDeposits must run inside the caller\'s transaction.');
        }

        $old = Agreement::query()->findOrFail($renewal->previous_agreement_id);
        $oldUnits = AgreementUnit::query()->where('agreement_id', $old->id)->get()->keyBy('unit_id');
        $amounts = [];
        $leftover = [];
        $now = now();

        foreach (AgreementUnit::query()->where('agreement_id', $renewal->id)->orderBy('id')->get() as $newAu) {
            $oldAu = $oldUnits->get($newAu->unit_id);
            $wanted = Fils::fromDecimal($newAu->deposit_amount);
            if ($oldAu === null) {
                $amounts[$newAu->id] = $wanted;
                continue;
            }

            // 1. The old deposit line's unpaid balance is credited (spec §5.8), so nothing stays owed on the old agreement.
            $unpaid = InvoiceLine::query()->where('agreement_unit_id', $oldAu->id)->where('charge_type', InvoiceChargeType::Deposit)
                ->whereHas('invoice', fn ($q) => $q->where('status', InvoiceStatus::Issued))->orderBy('id')->get()
                ->filter(fn (InvoiceLine $l) => $l->balanceFils() > 0);
            foreach ($unpaid->groupBy('invoice_id') as $lines) {
                $target = $lines->first()->invoice;
                $cn = $this->buildCreditNote->handle($target, $lines->map(fn (InvoiceLine $l) => [$l, $l->balanceFils()])->values()->all(),
                    __('Unpaid deposit closed on renewal :n', ['n' => $renewal->label()]), $approver);
                $cn->forceFill(['status' => InvoiceStatus::PendingApproval])->save();
                $this->issueCreditNote->handle($cn, $approver);
            }

            // 2. Carry min(held, new deposit).
            $held = DepositMovement::heldFils($oldAu->id);
            $carry = min($held, $wanted);
            if ($carry > 0) {
                $owner = DepositMovement::ownerContractFor($oldAu->id);
                $out = DepositMovement::create(['agreement_unit_id' => $oldAu->id, 'owner_contract_id' => $owner, 'type' => DepositMovementType::TransferOut,
                    'amount' => Fils::toDecimal(-$carry), 'source_type' => 'agreement', 'source_id' => $renewal->id, 'posted_at' => $now]);
                DepositMovement::create(['agreement_unit_id' => $newAu->id, 'owner_contract_id' => $out->owner_contract_id, 'type' => DepositMovementType::TransferIn,
                    'amount' => Fils::toDecimal($carry), 'source_type' => 'agreement', 'source_id' => $old->id, 'posted_at' => $now]);
            }

            // 3. What stays on the old unit is refunded through a settlement; 4. the renewal bills only what is missing.
            if ($held - $carry > 0) {
                $leftover[] = $oldAu->id;
            }
            $amounts[$newAu->id] = $wanted - $carry;
        }

        if ($leftover !== []) {
            $this->settlement->handle($old, $leftover, $approver);
        }
        $this->depositInvoice->handle($renewal, $approver, $amounts);
    }
}
```
In `app/Actions/Agreements/ActivateAgreement.php`:
- inject `TransferDeposits $transfer` and import `App\Models\Customer`;
- make the customer lock the transaction's first statement (the credit note in `TransferDeposits`, and any future money step, must not lock a customer after unit or agreement rows):
```php
        Customer::query()->lockForUpdate()->findOrFail($agreement->customer_id); // spec §7.2: customer first, then the units (§5.5)
        $this->overlap->handle($agreement); // checked again on approval
```
- replace `$this->deposit->handle($agreement, $approver);` with:
```php
        $agreement->previous_agreement_id !== null
            ? $this->transfer->handle($agreement, $approver)   // spec §5.8
            : $this->deposit->handle($agreement, $approver);
```
`EnsureNoAgreementOverlap`'s docblock says it runs "before anything else": change it to "before anything else except the customer lock".

The test's last step relies on `RecordMoveOut` running the closing check (Task 10), which now finds the renewal active and every old unit carried or moved out.

- [ ] **Step 5: The Renew button**

In `app/Livewire/Agreements/Show.php` add (import `App\Actions\Agreements\RenewAgreement`):
```php
    /** @var list<int|string> */
    public array $renewUnits = [];

    public string $renewEnd = '';

    public function renew(RenewAgreement $renew): void
    {
        try {
            $draft = $renew->handle($this->actor(), $this->agreement(), array_map('intval', $this->renewUnits), $this->renewEnd);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['renewUnits' => Arr::flatten($e->errors())]);
        }

        $this->redirectRoute('agreements.edit', $draft, navigate: true);
    }
```
and `'canRenew' => $this->actor()->can('create', Agreement::class) && $this->actor()->can('update', $agreement) && in_array($agreement->status->value, ['active', 'expired'], true),` in `render()`. In the view's header buttons:
```blade
            @if ($canRenew)
                <flux:modal.trigger name="renew"><flux:button>{{ __('Renew') }}</flux:button></flux:modal.trigger>
            @endif
```
and before `</section>`:
```blade
    <flux:modal name="renew" class="md:w-96">
        <form wire:submit="renew" class="space-y-4">
            <flux:heading size="lg">{{ __('Renew from :d', ['d' => $agreement->end_date->addDay()->format('d/m/Y')]) }}</flux:heading>
            <flux:checkbox.group wire:model="renewUnits" :label="__('Units to carry')">
                @foreach ($agreement->agreementUnits as $au)<flux:checkbox value="{{ $au->unit_id }}" :label="$au->unit->building->code.' / '.$au->unit->code" />@endforeach
            </flux:checkbox.group>
            <flux:input wire:model="renewEnd" type="date" :label="__('New end date')" />
            <flux:error name="renewUnits" />
            <flux:text size="sm">{{ __('A draft opens with the current rents and deposits; change them before sending it for approval.') }}</flux:text>
            <flux:button variant="primary" type="submit">{{ __('Create renewal draft') }}</flux:button>
        </form>
    </flux:modal>
```
Show a link to the renewal when one exists: `@if ($renewalOf = \App\Models\Agreement::query()->where('previous_agreement_id', $agreement->id)->latest('id')->first()) <flux:text>{{ __('Renewed by') }} <flux:link :href="route('agreements.show', $renewalOf)" wire:navigate>{{ $renewalOf->label() }}</flux:link></flux:text> @endif` — compute it in `render()` as `'renewal' => …` instead of in the view.

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Agreements tests/Feature/Billing tests/Feature/Deposits tests/Feature/Integrity
"$PHP" artisan test
```
Expected: every test passes, including M2's overlap and approval tests.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Renew agreements and carry the deposit over

Renew drafts a new agreement for the chosen units from the day after the
old end. On approval the held deposit moves across up to the new
deposit, any unpaid old deposit is credited, any surplus waits in a
settlement, and only the shortfall is invoiced. The old agreement renews
once every unit is carried or moved out.
EOF
```

---

### Task 12: Permission matrix, §14 flows and the lifecycle that reconciles

**Spec:** §14 flows 8 (release mid-period incl. "Issue now" periods → scheduled replaced, one credit note per issued invoice, kept days billed; deposit settlement → deductions invoice, deposit applied capped at held, refund), 10 (Task 11), 11 (permission matrix), 12 (immutability: every new table's rules), 14 (payment-out limits: the M3b part — refunds above their source are refused; remittance and head-lease limits are M4), §7.11 (the integrity check stays clean through the whole lifecycle)

**Files:**
- Create: `tests/Feature/Permissions/LifecyclePermissionMatrixTest.php`, `tests/Feature/Billing/LifecycleReconcilesTest.php`

**Interfaces:**
- Consumes: every Action and route of Tasks 1–11; `matrixUser()`

- [ ] **Step 1: The permission matrix**

Create `tests/Feature/Permissions/LifecyclePermissionMatrixTest.php`:
```php
<?php

use App\Actions\Agreements\RecordMoveOut;
use App\Actions\Agreements\RenewAgreement;
use App\Actions\Agreements\SaveAmendment;
use App\Actions\Approvals\DecideApproval;
use App\Actions\Agreements\SubmitAmendment;
use App\Actions\Deposits\CreateDepositSettlement;
use App\Actions\Deposits\SaveSettlementDeductions;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName as R;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Spec §14 flow 11 for M3b. Every user is assigned the fixture building.

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => false]);
    app(EnsureNumberSequences::class)(now('Asia/Bahrain')->year);

    $this->building = Building::factory()->create();
    [$u1, $u2, $this->spare] = Unit::factory()->for($this->building)->count(3)->create()->all();
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => now()->subYear()->toDateString(), 'end_date' => now()->addYear()->toDateString()], [$u1, $u2]);
    $finance = matrixUser(R::Finance, $this->building);
    $this->payment = app(RecordPayment::class)->handle($finance, $this->customer, ['received_on' => now('Asia/Bahrain')->toDateString(), 'method' => 'cash', 'amount' => '100']);
    $this->refund = app(RecordDisbursement::class)->handle($finance, ['purpose' => 'credit_refund', 'payment_id' => $this->payment->id, 'amount' => '10', 'method' => 'cash', 'paid_on' => now('Asia/Bahrain')->toDateString()]);
    $this->settlement = DB::transaction(fn () => app(CreateDepositSettlement::class)->handle($this->agreement, [$this->agreement->agreementUnits()->value('id')], $finance));
    $leasing = matrixUser(R::Leasing, $this->building);
    $this->amendment = app(SubmitAmendment::class)->handle($leasing, app(SaveAmendment::class)->handle($leasing, $this->agreement, null,
        ['type' => 'terminate', 'effective_date' => now()->addMonth()->toDateString(), 'reason' => 'matrix']));
});

$view = [R::Admin, R::Management, R::Finance, R::VendorSupport];
$lease = [R::Admin, R::PropertyManager, R::Leasing, R::VendorSupport];

test('pages open for exactly the roles the spec allows', function (string $route, Closure $params, array $allowed) {
    foreach (R::cases() as $role) {
        $status = $this->actingAs(matrixUser($role, $this->building))->get(route($route, $params->call($this)))->status();

        expect($status === 200)->toBe(in_array($role, $allowed, true), "{$route} as {$role->value} gave {$status}");
    }
})->with([
    ['disbursements.index', fn () => [], $view],
    ['disbursements.create', fn () => [], [R::Finance]],
    ['disbursements.show', fn () => [$this->refund], $view],
    ['deposit-settlements.index', fn () => [], $view],
    ['deposit-settlements.show', fn () => [$this->settlement], $view],
    ['agreements.amend', fn () => ['agreement' => $this->agreement, 'type' => 'release_unit'], $lease],
]);

test('each protected Action allows exactly the spec roles', function (string $name, Closure $run, array $allowed) {
    foreach (R::cases() as $role) {
        $user = matrixUser($role, $this->building);
        $denied = false;
        try {
            DB::transaction(fn () => $run->call($this, $user)); // rolled back below so each role starts from the same state
        } catch (AuthorizationException) {
            $denied = true;
        } catch (ValidationException) {
            // through authorisation; the data was refused
        }

        expect($denied)->toBe(! in_array($role, $allowed, true), "{$name} as {$role->value}");
    }
})->with([
    ['credit refund', fn (User $u) => app(RecordDisbursement::class)->handle($u, ['purpose' => 'credit_refund', 'payment_id' => $this->payment->id, 'amount' => '1', 'method' => 'cash', 'paid_on' => now('Asia/Bahrain')->toDateString()]), [R::Finance]],
    ['payment out', fn (User $u) => app(RecordDisbursement::class)->handle($u, ['purpose' => 'other', 'payee_type' => 'customer', 'payee_id' => $this->customer->id, 'amount' => '1', 'method' => 'cash', 'reason' => 'x']), [R::Finance]],
    ['payment-out reversal', fn (User $u) => app(RequestDisbursementReversal::class)->handle($u, $this->refund, 'x'), [R::Finance]],
    ['settlement deductions', fn (User $u) => app(SaveSettlementDeductions::class)->handle($u, $this->settlement, []), [R::Finance]],
    ['amendment', fn (User $u) => app(SaveAmendment::class)->handle($u, $this->agreement, null, ['type' => 'add_unit', 'unit_id' => $this->spare->id, 'effective_date' => now()->addMonth()->toDateString(), 'reason' => 'x', 'charges' => [['type' => 'rent', 'monthly_amount' => '1', 'tax_category' => 'exempt']]]), $lease],
    ['move-out', fn (User $u) => app(RecordMoveOut::class)->handle($u, $this->agreement, null, now('Asia/Bahrain')->toDateString(), null, null), $lease],
    ['renewal', fn (User $u) => app(RenewAgreement::class)->handle($u, $this->agreement, [$this->agreement->agreementUnits()->value('unit_id')], now()->addYears(2)->toDateString()), $lease],
    ['decide amendment', fn (User $u) => app(DecideApproval::class)->handle($u, $this->amendment, false, 'matrix'), [R::Management]],
]);
```
Each Action run is wrapped in `DB::transaction(...)`; because the test itself runs inside `RefreshDatabase`'s transaction, an allowed role's changes persist into the next role's run. That is acceptable: every closure is safe to repeat (later runs are refused with a `ValidationException`, which still counts as "allowed through"). If a closure is not repeatable, rethrow a sentinel exception inside the inner transaction to roll it back, and catch it.

- [ ] **Step 2: The lifecycle that reconciles (§14 flow 8 end to end)**

Create `tests/Feature/Billing/LifecycleReconcilesTest.php`:
```php
<?php

use App\Actions\Agreements\ExpireAgreements;
use App\Actions\Agreements\RecordMoveOut;
use App\Actions\Agreements\SaveAgreement;
use App\Actions\Agreements\SaveAmendment;
use App\Actions\Agreements\SubmitAgreement;
use App\Actions\Agreements\SubmitAmendment;
use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\IssueInvoice;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Actions\Deposits\SaveSettlementDeductions;
use App\Actions\Deposits\SubmitDepositSettlement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Billing\CustomerCredit;
use App\Billing\CustomerStatement;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\DepositSettlement;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

// Spec §14 flow 8 with real Actions only: a two-unit agreement, one unit released mid-period after "Issue now",
// its move-out, settlement with deductions, the refund, and the books still reconcile to the fil.

test('a release, a move-out, a settlement and a refund keep the books exact', function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true, 'invoice_lead_days' => 0, 'vat_registered' => false]);
    app(EnsureDefaultContractTemplate::class)();
    app(EnsureNumberSequences::class)(2026);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00', 'Asia/Bahrain'));

    $building = Building::factory()->create();
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($building->id);
    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    [$a, $b] = Unit::factory()->for($building)->count(2)->create()->all();
    $customer = Customer::factory()->create();

    $draft = app(SaveAgreement::class)->handle($leasing, null, ['customer_id' => $customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30', 'frequency' => 'monthly',
        'units' => [
            ['unit_id' => $a->id, 'deposit_amount' => '400.000', 'charges' => [['type' => 'rent', 'monthly_amount' => '400.000', 'tax_category' => 'exempt']]],
            ['unit_id' => $b->id, 'deposit_amount' => '300.000', 'charges' => [['type' => 'rent', 'monthly_amount' => '300.000', 'tax_category' => 'exempt']]],
        ]]);
    app(DecideApproval::class)->handle($management, app(SubmitAgreement::class)->handle($leasing, $draft), true);
    $agreement = $draft->fresh();

    // October issued at 01:00; November issued early ("Issue now"); the customer pays deposits and both months.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 01:00', 'Asia/Bahrain'));
    $this->artisan('rms:invoices:issue')->assertExitCode(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Bahrain'));
    app(IssueInvoice::class)->handle(Invoice::where('agreement_id', $agreement->id)->where('period_start', '2026-11-01')->sole(), $finance);
    app(RecordPayment::class)->handle($finance, $customer, ['received_on' => '2026-10-02', 'method' => 'bank_transfer', 'amount' => '2100.000']); // 700 deposits + 2 × 700 rent

    // Unit B released from 15 November.
    $auB = $agreement->agreementUnits()->where('unit_id', $b->id)->sole();
    $amend = app(SaveAmendment::class)->handle($leasing, $agreement, null, ['type' => 'release_unit', 'agreement_unit_id' => $auB->id, 'effective_date' => '2026-11-15', 'reason' => 'Downsizing']);
    app(DecideApproval::class)->handle($management, app(SubmitAmendment::class)->handle($leasing, $amend), true);

    $november = Invoice::where('agreement_id', $agreement->id)->where('type', 'rent')->where('period_start', '2026-11-01')->where('status', 'issued')->sole();
    expect(Invoice::where('type', 'credit_note')->count())->toBe(1)
        ->and($november->fresh()->credited)->toBe('152.055')           // B: 300 − 15 days (147.945)
        ->and(CustomerCredit::fils($customer->id))->toBe(152_055)       // paid, so it came back as credit
        ->and(Invoice::where('agreement_id', $agreement->id)->where('period_start', '2026-12-01')->where('status', 'scheduled')->sole()->total)->toBe('400.000');

    // B moves out on its last day; its settlement takes 50 for cleaning; the rest is refunded.
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Bahrain'));
    app(RecordMoveOut::class)->handle($leasing, $agreement->fresh(), $auB->fresh(), '2026-11-15', null, 'Keys back');
    $settlement = DepositSettlement::sole();
    app(SaveSettlementDeductions::class)->handle($finance, $settlement, [['agreement_unit_id' => $auB->id, 'type' => 'cleaning', 'description' => 'End clean', 'amount' => '50.000']]);
    app(DecideApproval::class)->handle($management, app(SubmitDepositSettlement::class)->handle($finance, $settlement->fresh()), true);
    app(RecordDisbursement::class)->handle($finance, ['purpose' => 'deposit_refund', 'deposit_settlement_id' => $settlement->id, 'amount' => '250.000', 'method' => 'bank_transfer', 'paid_on' => '2026-11-16']);

    // The 02:00 job leaves the agreement active (unit A is still let).
    app(ExpireAgreements::class)();

    expect($settlement->fresh()->status->value)->toBe('completed')
        ->and(DepositMovement::heldFils($auB->id))->toBe(0)
        ->and(DepositMovement::heldFils($agreement->agreementUnits()->where('unit_id', $a->id)->value('id')))->toBe(400_000)
        ->and($agreement->fresh()->status->value)->toBe('active')
        ->and(app(IntegrityCheck::class)->run())->toBe([]);

    // The statement equals the cached figures (spec §7.10).
    $issued = Invoice::where('customer_id', $customer->id)->where('status', 'issued')->where('type', '!=', 'credit_note');
    $outstanding = Fils::fromDecimal((string) $issued->sum('balance'));
    expect(CustomerStatement::receivables($customer, '2026-09-01', '2026-11-30')['closing'])->toBe($outstanding - CustomerCredit::fils($customer->id));
});
```
Before running, check the two derived figures against the code once, as M3a's month test did: B's kept 1–15 November is `divRound(300 000 × 15 × 12, 365)` = 147 945 fils, so the credit is 152 055; the 2 100.000 transfer covers the deposit invoice and October and November rent exactly, so every line is paid in full whatever the order. If an identity fails, the bug is in Tasks 1–11 — fix it there, not in the test.

- [ ] **Step 3: Run everything, then static checks**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test
"$PHP" vendor/bin/pint --test
"$PHP" vendor/bin/phpstan analyse --memory-limit=1G
grep -rn "panel:documentable" resources/views   # must print nothing
"$PHP" artisan tinker --execute="echo DB::selectOne('SELECT rms_trigger_count() AS n')->n;"   # must print 52
```
Expected: every test passes; Pint and Larastan clean; 52 triggers.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the lifecycle permission matrix and an end-to-end release

Every M3b page and Action is checked against every role. A two-unit
agreement with one unit released after Issue now, its move-out,
settlement and refund reconciles to the fil and passes the integrity
check.
EOF
```

---

## Not in M3b (M4 and later)

| Item | Where |
|---|---|
| Owner remittances and head-lease payments out, with their limits and approval when exceeded (§7.5, §14 flow 14's remittance and head-lease cases) | M4 |
| Allocation tax on partly credited lines can be ~1 fil short of the line tax (M3a final review) — fix before the owner ledger reads `payment_allocations.tax_amount` | M4, first task |
| Owner ledger, statements, payables, fees | M4 |
| "Held cheques to return" and "expired, not closed" reports; daily 07:00 emails | M5 |
| A system user for nightly jobs (settlements created at 02:00 are attributed to the agreement's creator meanwhile) | M5 |
| Imported agreements' opening deposits and balances | M5 |
| Client confirmations: same-day deposit vs rent order (lowest id wins); a pending owner-contract termination does not hold back invoices | ask the client |
