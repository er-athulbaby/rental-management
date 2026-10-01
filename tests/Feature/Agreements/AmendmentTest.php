<?php

use App\Actions\Agreements\SaveAmendment;
use App\Actions\Agreements\SubmitAmendment;
use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\Billing\IssueInvoice;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
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
