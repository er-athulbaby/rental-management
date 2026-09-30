<?php

use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\Billing\IssueDueInvoices;
use App\Actions\Billing\IssueInvoice;
use App\Actions\EnsureNumberSequences;
use App\Enums\InvoiceStatus;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'invoice_lead_days' => 7, 'default_grace_days' => 5]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->create();
    $this->building = Building::factory()->create();
    $this->flat = Unit::factory()->for($this->building)->create();
    $this->shop = Unit::factory()->for($this->building)->create();

    $agreement = Agreement::factory()->create(['start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'grace_days' => 7]);
    foreach ([[$this->flat, '450.000', 'exempt'], [$this->shop, '850.000', 'standard']] as [$unit, $rent, $tax]) {
        $au = $agreement->agreementUnits()->create(['unit_id' => $unit->id, 'list_rent' => $rent, 'deposit_amount' => 0, 'start_date' => $agreement->start_date, 'end_date' => $agreement->end_date]);
        $au->charges()->create(['type' => 'rent', 'monthly_amount' => $rent, 'tax_category' => $tax]);
    }
    $agreement->forceFill(['status' => 'pending_approval'])->save();
    $agreement->forceFill(['status' => 'active', 'number' => 'AGR-T-2', 'verify_token' => str_repeat('y', 32)])->save();
    $this->agreement = $agreement;
    $this->invoices = DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($agreement, $this->finance));
});

test('issuing writes tax per line, the number, grace date and issuer', function () {
    $invoice = $this->invoices->first();

    expect(app(IssueInvoice::class)->handle($invoice, $this->finance))->toBeTrue();

    $invoice->refresh()->load('lines');
    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->number)->toBe('INV-2026-000001')
        ->and($invoice->lines->firstWhere('unit_id', $this->shop->id)->tax_amount)->toBe('85.000')
        ->and($invoice->lines->firstWhere('unit_id', $this->shop->id)->tax_rate)->toBe('10.00')
        ->and($invoice->lines->firstWhere('unit_id', $this->flat->id)->tax_amount)->toBe('0.000')
        ->and($invoice->subtotal)->toBe('1300.000')
        ->and($invoice->tax_total)->toBe('85.000')
        ->and($invoice->total)->toBe('1385.000')
        ->and($invoice->balance)->toBe('1385.000')
        ->and($invoice->grace_until->toDateString())->toBe('2026-11-08')   // agreement grace 7 days
        ->and($invoice->issued_by)->toBe($this->finance->id);

    expect(fn () => app(IssueInvoice::class)->handle($invoice->fresh(), $this->finance))->toThrow(ValidationException::class);
});

test('each line is stamped with the owner contract covering its unit on its period start', function () {
    $managed = activeOwnerContract(['building_id' => $this->building->id, 'start_date' => '2026-01-01', 'end_date' => '2026-11-30'], [$this->flat]);

    app(IssueInvoice::class)->handle($this->invoices[0]);  // November: covered
    app(IssueInvoice::class)->handle($this->invoices[1]);  // December: contract has ended

    expect($this->invoices[0]->lines()->where('unit_id', $this->flat->id)->value('owner_contract_id'))->toBe($managed->id)
        ->and($this->invoices[0]->lines()->where('unit_id', $this->shop->id)->value('owner_contract_id'))->toBeNull()
        ->and($this->invoices[1]->lines()->where('unit_id', $this->flat->id)->value('owner_contract_id'))->toBeNull();
});

test('an invoice is held back while an owner contract covering one of its lines is pending', function () {
    $pending = OwnerContract::factory()->create(['building_id' => $this->building->id, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31']);
    $pending->units()->attach($this->shop->id);
    $pending->forceFill(['status' => 'pending_approval'])->save();

    expect(app(IssueInvoice::class)->handle($this->invoices->first()))->toBeFalse()
        ->and($this->invoices->first()->fresh()->status)->toBe(InvoiceStatus::Scheduled);

    $pending->forceFill(['status' => 'active', 'number' => 'OC-TEST-9'])->save();
    expect(app(IssueInvoice::class)->handle($this->invoices->first()))->toBeTrue()
        ->and($this->invoices->first()->lines()->where('unit_id', $this->shop->id)->value('owner_contract_id'))->toBe($pending->id);
});

test('the due-invoice run issues only what is due, once', function () {
    expect(app(IssueDueInvoices::class)())->toBe(0);          // November is issued from 25 Oct

    $this->travelTo(CarbonImmutable::parse('2026-10-25 01:00', 'Asia/Bahrain'));
    expect(app(IssueDueInvoices::class)())->toBe(1)
        ->and(app(IssueDueInvoices::class)())->toBe(0)
        ->and(Invoice::where('status', 'issued')->count())->toBe(1);
});

test('a company that is not VAT registered issues plain invoices with no tax', function () {
    CompanySetting::current()->forceFill(['vat_registered' => false])->save();

    app(IssueInvoice::class)->handle($this->invoices->first());

    expect($this->invoices->first()->fresh()->tax_total)->toBe('0.000');
});

test('the due-invoice run skips an invoice that was issued meanwhile and keeps going', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 01:00', 'Asia/Bahrain'));   // November and December are both due
    $second = $this->invoices[1];

    // The run reads its id list, then issues November; that read is when we issue December behind its back.
    Invoice::retrieved(function (Invoice $i) use ($second) {
        static $done = false;
        if (! $done && $i->id !== $second->id) {
            $done = true;
            app(IssueInvoice::class)->handle($second);
        }
    });

    expect(app(IssueDueInvoices::class)())->toBe(1)
        ->and(Invoice::where('status', 'issued')->count())->toBe(2);
});
