<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RebillAgreement;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\Cheques\RecordCheques;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Billing\CustomerCredit;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\Agreement;
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
use Illuminate\Support\Str;

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

test('a second, earlier cut credits only the gap: November keeps exactly 1–10 Nov', function () {
    $this->au->forceFill(['end_date' => '2026-11-15'])->save();
    ($this->rebill)('2026-11-15');
    $this->au->forceFill(['end_date' => '2026-11-10'])->save();

    $result = ($this->rebill)('2026-11-10');

    $november = ($this->byMonth)('2026-11-01');
    expect(Invoice::findOrFail($result->creditNoteIds[0])->total)->toBe('65.753') // 197.260 − 131.507
        ->and($november->credited)->toBe('268.493')                               // 400 − 131.507
        ->and($november->balance)->toBe('131.507')
        ->and(app(IntegrityCheck::class)->run())->toBe([]);
});

test('VAT-registered partial credits keep the gross of the kept days, and split net and tax on the line', function () {
    CompanySetting::current()->forceFill(['vat_registered' => true, 'vat_rate' => '10.00'])->save();
    $agreement = Agreement::factory()->create(['customer_id' => $this->customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30']);
    $au = $agreement->agreementUnits()->create(['unit_id' => Unit::factory()->create()->id, 'list_rent' => '400.000', 'deposit_amount' => '400.000',
        'start_date' => '2026-10-01', 'end_date' => '2027-09-30']);
    $au->charges()->create(['type' => 'rent', 'monthly_amount' => '400.000', 'tax_category' => 'standard']);
    $agreement->forceFill(['status' => 'pending_approval'])->save();
    $agreement->forceFill(['status' => 'active', 'number' => 'AGR-T-'.$agreement->id, 'verify_token' => Str::random(32)])->save();
    DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($agreement->fresh(), $this->finance));
    $october = Invoice::where('agreement_id', $agreement->id)->where('period_start', '2026-10-01')->sole();
    app(IssueInvoice::class)->handle($october, $this->finance);
    expect($october->fresh()->total)->toBe('440.000'); // 400 + 10 %
    $rebill = fn (string $end) => DB::transaction(function () use ($agreement, $au, $end) {
        Customer::query()->lockForUpdate()->findOrFail($this->customer->id);
        $au->forceFill(['end_date' => $end])->save();

        return app(RebillAgreement::class)->handle($agreement->fresh(), CarbonImmutable::parse($end), $this->finance, 'Unit released');
    });

    $first = Invoice::findOrFail($rebill('2026-10-15')->creditNoteIds[0]);
    expect($first->subtotal)->toBe('202.740')->and($first->tax_total)->toBe('20.274')->and($first->total)->toBe('223.014'); // keeps 197.260 + 19.726

    $second = Invoice::findOrFail($rebill('2026-10-10')->creditNoteIds[0]);
    expect($second->total)->toBe('72.328')                                    // 216.986 − (131.507 + 13.151)
        ->and($october->fresh()->balance)->toBe('144.658')
        ->and(app(IntegrityCheck::class)->run())->toBe([]);
});

test('an issued rent invoice with lines without a charge cannot be re-billed', function () {
    $december = ($this->byMonth)('2026-12-01');
    $december->forceFill(['status' => 'cancelled'])->save();
    $invoice = (new Invoice)->forceFill(['type' => 'rent', 'customer_id' => $this->customer->id, 'agreement_id' => $this->agreement->id,
        'period_start' => '2026-12-01', 'period_end' => '2026-12-31', 'issue_date' => '2026-10-05', 'due_date' => '2026-12-01',
        'status' => 'draft', 'subtotal' => '400.000', 'tax_total' => '0.000', 'total' => '400.000']);
    $invoice->save();
    $invoice->lines()->create(['agreement_unit_id' => $this->au->id, 'unit_id' => $this->au->unit_id, 'charge_type' => 'rent', 'description' => 'Rent',
        'net' => '400.000', 'tax_category' => 'exempt', 'tax_rate' => '0.00', 'tax_amount' => '0.000', 'total' => '400.000']);
    app(IssueInvoice::class)->handle($invoice, $this->finance);
    $this->au->forceFill(['end_date' => '2026-11-15'])->save();

    expect(fn () => ($this->rebill)('2026-11-15'))->toThrow(LogicException::class, 'Issued rent invoice '.$invoice->fresh()->number.' has lines without a charge; cannot re-bill');
});

test('a 1-fil VAT drift on a partly credited line does not bill a 0.001 manual invoice on the next rebill', function () {
    CompanySetting::current()->forceFill(['vat_registered' => true, 'vat_rate' => '10.00'])->save();
    $agreement = Agreement::factory()->create(['customer_id' => $this->customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30']);
    foreach (Unit::factory()->count(2)->create() as $unit) {
        $agreement->agreementUnits()->create(['unit_id' => $unit->id, 'list_rent' => '400.000', 'deposit_amount' => '400.000',
            'start_date' => '2026-10-01', 'end_date' => '2027-09-30'])
            ->charges()->create(['type' => 'rent', 'monthly_amount' => '400.000', 'tax_category' => 'standard']);
    }
    $agreement->forceFill(['status' => 'pending_approval'])->save();
    $agreement->forceFill(['status' => 'active', 'number' => 'AGR-T-'.$agreement->id, 'verify_token' => Str::random(32)])->save();
    DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($agreement->fresh(), $this->finance));
    $october = Invoice::where('agreement_id', $agreement->id)->where('period_start', '2026-10-01')->sole();
    app(IssueInvoice::class)->handle($october, $this->finance);
    [$au1, $au2] = $agreement->agreementUnits()->orderBy('id')->get()->all();
    $release = fn ($au, string $end) => DB::transaction(function () use ($agreement, $au, $end) {
        Customer::query()->lockForUpdate()->findOrFail($this->customer->id);
        $au->forceFill(['end_date' => $end])->save();

        return app(RebillAgreement::class)->handle($agreement->fresh(), CarbonImmutable::parse($end), $this->finance, 'Unit released');
    });

    $release($au1, '2026-10-16');                                             // October keeps 210.411 net for u1
    $u1Line = $october->lines()->where('agreement_unit_id', $au1->id)->sole();
    $result = $release($au2, '2026-10-20');

    expect($result->manualInvoiceId)->toBeNull()
        ->and(Invoice::where('type', 'manual')->exists())->toBeFalse()
        ->and($u1Line->fresh()->credited)->toBe($u1Line->credited)
        ->and(app(IntegrityCheck::class)->run())->toBe([]);
});

test('a discretionary credit note stays a concession: re-billing neither re-bills it nor counts it as kept', function () {
    $november = ($this->byMonth)('2026-11-01');
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $concession = app(SaveCreditNote::class)->handle($this->finance, $november, null, ['reason' => 'Goodwill', 'lines' => [['credited_line_id' => $november->lines->sole()->id, 'amount' => '50']]]);
    app(DecideApproval::class)->handle($management, app(SubmitCreditNote::class)->handle($this->finance, $concession), true);
    expect($concession->fresh()->status->value)->toBe('issued')->and($concession->fresh()->credit_source)->toBeNull();

    $this->au->forceFill(['end_date' => '2026-11-15'])->save();
    $result = ($this->rebill)('2026-11-15');

    $cn = Invoice::findOrFail($result->creditNoteIds[0]);
    expect($result->manualInvoiceId)->toBeNull()
        ->and(Invoice::where('type', 'manual')->exists())->toBeFalse()
        ->and($cn->total)->toBe('202.740')                // 400 − 197.260 kept; the concession is not re-billed
        ->and($cn->credit_source)->toBe('rebill')
        ->and(fn () => DB::table('invoices')->where('id', $concession->id)->update(['credit_source' => 'rebill']))->toThrow(QueryException::class, 'the credit source is frozen')
        ->and(($this->byMonth)('2026-11-01')->credited)->toBe('252.740')
        ->and(app(IntegrityCheck::class)->run())->toBe([]);

    $this->au->forceFill(['end_date' => '2026-11-10'])->save();     // a second cut credits only 11–15 Nov, the concession untouched
    $later = ($this->rebill)('2026-11-10');
    expect(Invoice::findOrFail($later->creditNoteIds[0])->total)->toBe('65.753')
        ->and($later->manualInvoiceId)->toBeNull()
        ->and(($this->byMonth)('2026-11-01')->balance)->toBe('81.507');      // 131.507 kept − 50 concession
});
