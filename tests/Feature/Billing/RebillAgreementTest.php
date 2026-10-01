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
