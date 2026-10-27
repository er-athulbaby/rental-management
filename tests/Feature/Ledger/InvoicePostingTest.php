<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Ledger\Poster;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Models\Owner;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    ledgerStart('2026-10-01');
    $this->building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $this->owner = Owner::factory()->create();
    $this->tenant = Customer::factory()->create();
    // A new unit let to the tenant for 2026, under a contract of the given type for 2026 (none when null).
    $this->unitUnder = function (?string $type, string $heldBy = 'company'): array {
        $unit = Unit::factory()->for($this->building)->create();
        if ($type !== null) {
            activeOwnerContract(['owner_id' => $this->owner->id, 'building_id' => $this->building->id, 'type' => $type, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
                ...($type === 'leased'
                    ? ['rent_amount' => '300.000', 'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null]
                    : ['deposits_held_by' => $heldBy])], [$unit]);
        }
        $agreement = activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$unit]);

        return [$agreement, $agreement->agreementUnits->sole()];
    };
});

afterEach(function () {
    LedgerSetting::current()->forceFill(['start_date' => null, 'locked_until' => null])->save();
});

test('a managed building\'s rent posts the net to Owner payable and the VAT to VAT payable, with its dimensions', function () {
    [$agreement, $au] = ($this->unitUnder)('managed');
    $invoice = issuedInvoice($this->tenant, [['net' => '400.000', 'tax' => 'standard', 'au' => $au]], '2026-10-01', $agreement);
    $journal = journalFor('invoice', $invoice->id);

    expect(postedLines('invoice', $invoice->id))->toBe(['1100 440.000 0.000', '2100 0.000 400.000', '2400 0.000 40.000'])
        ->and($journal->date->toDateString())->toBe('2026-10-01')
        ->and($journal->kind)->toBe('auto')
        ->and($journal->late_posting)->toBeFalse()
        ->and($journal->description)->toBe($invoice->number)
        ->and($journal->lines->first()->only(['customer_id', 'owner_id', 'building_id', 'unit_id']))
        ->toBe(['customer_id' => $this->tenant->id, 'owner_id' => $this->owner->id, 'building_id' => $this->building->id, 'unit_id' => $au->unit_id]);
});

test('a leased building\'s rent is company income, other charges go to Other charges income, and posting twice changes nothing', function () {
    [$agreement, $au] = ($this->unitUnder)('leased');
    $invoice = issuedInvoice($this->tenant, [['net' => '300.000', 'au' => $au], ['net' => '25.000', 'tax' => 'standard', 'type' => 'service_charge', 'au' => $au]], '2026-10-01', $agreement);

    expect(postedLines('invoice', $invoice->id))->toBe([
        '1100 300.000 0.000', '4000 0.000 300.000',
        '1100 27.500 0.000', '4100 0.000 25.000', '2400 0.000 2.500',
    ])->and(app(Poster::class)->post('invoice', $invoice->id))->toBeNull()
        ->and(Journal::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->count())->toBe(1);
});

test('a deposit is held for the tenant, or owed to the owner when the owner holds deposits', function () {
    [$companyAgreement, $companyAu] = ($this->unitUnder)('managed', 'company');
    [$ownerAgreement, $ownerAu] = ($this->unitUnder)('managed', 'owner');
    $company = issuedInvoice($this->tenant, [['net' => '400.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => $companyAu]], '2026-10-01', $companyAgreement, 'deposit');
    $owner = issuedInvoice($this->tenant, [['net' => '500.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => $ownerAu]], '2026-10-01', $ownerAgreement, 'deposit');

    expect(postedLines('invoice', $company->id))->toBe(['1100 400.000 0.000', '2000 0.000 400.000'])
        ->and(postedLines('invoice', $owner->id))->toBe(['1100 500.000 0.000', '2100 0.000 500.000']);
});

test('a credit note posts the exact opposite of the lines it credits, on its own issue date', function () {
    [$agreement, $au] = ($this->unitUnder)('managed');
    $invoice = issuedInvoice($this->tenant, [['net' => '400.000', 'tax' => 'standard', 'au' => $au]], '2026-10-01', $agreement);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00', 'Asia/Bahrain'));
    $cn = app(SaveCreditNote::class)->handle($this->finance, $invoice, null, ['reason' => 'Goodwill', 'lines' => [['credited_line_id' => $invoice->lines->sole()->id, 'amount' => '110.000']]]);
    app(DecideApproval::class)->handle(matrixUser(RoleName::Management, $this->building), app(SubmitCreditNote::class)->handle($this->finance, $cn), true);

    expect(postedLines('invoice', $cn->id))->toBe(['1100 0.000 110.000', '2100 100.000 0.000', '2400 10.000 0.000'])
        ->and(journalFor('invoice', $cn->id)->date->toDateString())->toBe('2026-10-12');
});

test('a unit with no owner contract on the date falls back to company income', function () {
    [$agreement, $au] = ($this->unitUnder)(null);
    $invoice = issuedInvoice($this->tenant, [['net' => '200.000', 'au' => $au]], '2026-10-01', $agreement);

    expect(postedLines('invoice', $invoice->id))->toBe(['1100 200.000 0.000', '4000 0.000 200.000']);
});

test('nothing posts before the start date, before the ledger is set up, or before an invoice is issued', function () {
    [$agreement, $au] = ($this->unitUnder)('leased');
    $this->travelTo(CarbonImmutable::parse('2026-09-30 09:00', 'Asia/Bahrain'));
    $september = issuedInvoice($this->tenant, [['net' => '300.000', 'au' => $au]], '2026-09-01', $agreement);
    LedgerSetting::current()->forceFill(['start_date' => null])->save();
    $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00', 'Asia/Bahrain'));
    $unset = issuedInvoice($this->tenant, [['net' => '300.000', 'au' => $au]], '2026-10-01', $agreement);
    $draft = (new Invoice)->forceFill(['type' => 'manual', 'customer_id' => $this->tenant->id, 'issue_date' => '2026-10-02', 'due_date' => '2026-10-02', 'status' => 'draft']);
    $draft->save();

    expect(journalFor('invoice', $september->id))->toBeNull()
        ->and(journalFor('invoice', $unset->id))->toBeNull()
        ->and(journalFor('invoice', $draft->id))->toBeNull();

    ledgerStart('2026-10-01');
    app(Poster::class)->catchUp();

    expect(journalFor('invoice', $unset->id))->not->toBeNull()
        ->and(journalFor('invoice', $september->id))->toBeNull()
        ->and(journalFor('invoice', $draft->id))->toBeNull();
});

test('a posting failure leaves the invoice issued; catch-up posts it once the account is active again', function () {
    [$agreement, $au] = ($this->unitUnder)('leased');
    $receivables = LedgerAccount::query()->where('code', '1100')->firstOrFail();
    $receivables->forceFill(['active' => false])->save();
    $invoice = issuedInvoice($this->tenant, [['net' => '300.000', 'au' => $au]], '2026-10-01', $agreement);

    expect($invoice->status->value)->toBe('issued')
        ->and(journalFor('invoice', $invoice->id))->toBeNull()
        ->and(Poster::pendingCount())->toBe(1);

    $receivables->forceFill(['active' => true])->save();
    app(Poster::class)->catchUp();

    expect(postedLines('invoice', $invoice->id))->toBe(['1100 300.000 0.000', '4000 0.000 300.000'])
        ->and(Poster::pendingCount())->toBe(0);
});

test('every posted journal balances to the fils', function () {
    [$agreement, $au] = ($this->unitUnder)('managed');
    issuedInvoice($this->tenant, [['net' => '400.000', 'tax' => 'standard', 'au' => $au], ['net' => '33.333', 'tax' => 'standard', 'type' => 'service_charge', 'au' => $au]], '2026-10-01', $agreement);

    expect(DB::table('journal_lines')->sum('debit'))->toEqual(DB::table('journal_lines')->sum('credit'))
        ->and(Journal::query()->count())->toBeGreaterThan(0);
});
