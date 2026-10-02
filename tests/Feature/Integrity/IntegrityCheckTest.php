<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerCharge;
use App\Models\OwnerPayable;
use App\Models\OwnerStatement;
use App\Models\User;
use App\Notifications\IntegrityCheckFailed;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->vendor = User::factory()->create()->assignRole(RoleName::VendorSupport);
    $customer = Customer::factory()->create();
    $this->invoice = issuedInvoice($customer, [['net' => '100.000', 'tax' => 'standard'], ['net' => '50.000']]);
    app(RecordPayment::class)->handle(User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance), $customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '90.000']);
});

test('the trigger count the app user sees matches the expected count', function () {
    expect((int) DB::selectOne('SELECT rms_trigger_count() AS n')->n)->toBe(IntegrityCheck::EXPECTED_TRIGGERS);
});

test('healthy books pass', function () {
    expect(app(IntegrityCheck::class)->run())->toBe([]);
    $this->artisan('rms:integrity-check')->assertExitCode(0);
});

test('a cached figure that drifts from its source rows is reported and emailed to Vendor Support', function () {
    Notification::fake();
    // Drift below the Actions: the triggers let an issued line's allocated change (only the allocation Actions do it),
    // so one fil written directly is a realistic corruption.
    $line = $this->invoice->lines->first();
    DB::table('invoice_lines')->where('id', $line->id)->update(['allocated' => DB::raw('allocated + 0.001')]);

    $failures = app(IntegrityCheck::class)->run();

    expect($failures)->not->toBe([])
        ->and(implode("\n", $failures))->toContain("invoice line {$line->id}");
    $this->artisan('rms:integrity-check')->assertExitCode(1);
    Notification::assertSentTo($this->vendor, IntegrityCheckFailed::class);
});

test('the check runs at 02:30 without overlap', function () {
    expect(scheduledEvent('rms:integrity-check')->expression)->toBe('30 2 * * *');
});

test('a paid payable must be paid by its own head-lease payment out', function () {
    $contract = activeOwnerContract(['type' => 'leased', 'rent_amount' => '50.000', 'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null], []);
    $payable = (new OwnerPayable)->forceFill(['owner_contract_id' => $contract->id, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'due_date' => '2026-01-01', 'amount' => '50.000', 'status' => 'scheduled']);
    $payable->save();
    // any payment out that is not this payable's
    $other = DB::table('disbursements')->insertGetId([
        'payee_type' => 'customer', 'payee_id' => Customer::factory()->create()->id, 'purpose' => 'other', 'amount' => '10.000',
        'method' => 'cash', 'status' => 'pending_approval', 'created_by' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('owner_payables')->where('id', $payable->id)->update(['status' => 'paid', 'disbursement_id' => $other]);

    expect(app(IntegrityCheck::class)->run())->toContain("owner payable {$payable->id}: not paid by its own head-lease payment out");
});

test('a finalised statement must still add up from the ledger', function () {
    $contract = activeOwnerContract(['type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'fee_type' => 'fixed', 'fee_value' => '0.000'], []);
    $s = (new OwnerStatement)->forceFill(['owner_contract_id' => $contract->id, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'cutoff_at' => '2026-01-31 23:59:59', 'status' => 'draft', 'created_by' => $contract->created_by]);
    $s->save();
    DB::table('owner_statements')->where('id', $s->id)->update(['status' => 'pending_approval']);
    DB::table('owner_statements')->where('id', $s->id)->update(['status' => 'finalised', 'number' => 'OS-T-1', 'finalised_at' => now(), 'finalised_by' => $contract->created_by]);
    expect(app(IntegrityCheck::class)->run())->toBe([]);

    // Something posted back into a finalised window.
    OwnerCharge::create(['owner_contract_id' => $contract->id, 'type' => 'opening_balance', 'net' => '5.000', 'tax_amount' => '0.000', 'amount' => '5.000', 'posted_at' => '2026-01-20 10:00:00', 'created_by' => $contract->created_by]);
    expect(app(IntegrityCheck::class)->run())->toContain('owner statement OS-T-1: closing 0.000 but its entries now give 5.000');
});
