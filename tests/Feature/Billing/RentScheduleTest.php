<?php

use App\Actions\Billing\CreateDepositInvoice;
use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\EnsureNumberSequences;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    CompanySetting::factory()->create(['invoice_lead_days' => 7, 'default_grace_days' => 5, 'proration_basis' => 'actual_365']);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->actor = User::factory()->create();
    $building = Building::factory()->create(['code' => 'MT']);
    $this->flat = Unit::factory()->for($building)->create(['code' => '101']);
    $this->shop = Unit::factory()->for($building)->create(['code' => 'S1']);
});

/** A draft agreement with a flat (rent + service charge) and a shop joining on 15 Nov, walked to active. */
function scheduledAgreement(object $test, array $attributes = []): Agreement
{
    $agreement = Agreement::factory()->create(['start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'frequency' => 'monthly', ...$attributes]);
    $flat = $agreement->agreementUnits()->create(['unit_id' => $test->flat->id, 'list_rent' => '450.000', 'deposit_amount' => '450.000', 'start_date' => $agreement->start_date, 'end_date' => $agreement->end_date]);
    $flat->charges()->create(['type' => 'rent', 'monthly_amount' => '450.000', 'tax_category' => 'exempt']);
    $flat->charges()->create(['type' => 'service_charge', 'monthly_amount' => '20.000', 'tax_category' => 'exempt']);
    $shop = $agreement->agreementUnits()->create(['unit_id' => $test->shop->id, 'list_rent' => '850.000', 'deposit_amount' => '0.000', 'start_date' => max('2026-11-15', $agreement->start_date->toDateString()), 'end_date' => $agreement->end_date]);
    $shop->charges()->create(['type' => 'rent', 'monthly_amount' => '850.000', 'tax_category' => 'standard']);
    $agreement->forceFill(['status' => 'pending_approval'])->save();
    $agreement->forceFill(['status' => 'active', 'number' => 'AGR-T-1', 'verify_token' => str_repeat('x', 32)])->save();

    return $agreement->fresh();
}

test('one scheduled rent invoice per period, with a line per unit per charge, prorated', function () {
    $agreement = scheduledAgreement($this);

    $invoices = DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($agreement, $this->actor));

    expect($invoices)->toHaveCount(12)
        ->and($invoices->every(fn (Invoice $i) => $i->status === InvoiceStatus::Scheduled && $i->number === null && $i->type === InvoiceType::Rent))->toBeTrue();

    $nov = $invoices->first()->load('lines');
    expect($nov->period_start->toDateString())->toBe('2026-11-01')
        ->and($nov->due_date->toDateString())->toBe('2026-11-01')
        ->and($nov->issue_date->toDateString())->toBe('2026-10-25')             // due − 7 lead days
        ->and($nov->lines->pluck('net')->sort()->values()->all())->toBe(['20.000', '447.123', '450.000']) // shop: 16 days of 850
        ->and($nov->lines->firstWhere('net', '447.123')->period_start->toDateString())->toBe('2026-11-15')
        ->and($nov->total)->toBe('917.123');

    expect($invoices[1]->load('lines')->lines->sum(fn ($l) => (float) $l->net))->toBe(1320.0);
});

test('the issue date never falls before activation', function () {
    $agreement = scheduledAgreement($this, ['start_date' => '2026-10-01', 'end_date' => '2027-09-30']);

    $first = DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($agreement, $this->actor))->first();

    expect($first->issue_date->toDateString())->toBe('2026-10-05');
});

test('a quarterly agreement with a billing day starts with a stub', function () {
    $agreement = scheduledAgreement($this, ['start_date' => '2026-11-20', 'end_date' => '2027-11-19', 'frequency' => 'quarterly', 'billing_day' => 1]);

    $invoices = DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($agreement, $this->actor));

    expect($invoices->first()->period_end->toDateString())->toBe('2026-11-30')
        ->and($invoices[1]->period_start->toDateString())->toBe('2026-12-01')
        ->and($invoices[1]->period_end->toDateString())->toBe('2027-02-28');
});

test('the deposit invoice is issued at activation, out of scope, due on the start date', function () {
    $agreement = scheduledAgreement($this);

    $deposit = DB::transaction(fn () => app(CreateDepositInvoice::class)->handle($agreement, $this->actor))->load('lines');

    expect($deposit->type)->toBe(InvoiceType::Deposit)
        ->and($deposit->status)->toBe(InvoiceStatus::Issued)
        ->and($deposit->number)->toBe('INV-2026-000001')
        ->and($deposit->lines)->toHaveCount(1)                      // the shop has no deposit
        ->and($deposit->lines[0]->tax_category->value)->toBe('out_of_scope')
        ->and($deposit->total)->toBe('450.000')
        ->and($deposit->due_date->toDateString())->toBe('2026-11-01')
        ->and($deposit->grace_until->toDateString())->toBe('2026-11-06');
});
