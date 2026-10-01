<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\CompanySetting;
use App\Models\Customer;
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
