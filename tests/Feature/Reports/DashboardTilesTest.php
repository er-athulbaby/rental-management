<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Dashboard;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['default_grace_days' => 5]);
    $this->travelTo(CarbonImmutable::parse('2026-06-10 09:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create();
    [$u1, $u2] = Unit::factory()->for($this->building)->count(2)->create()->all();
    $this->customer = Customer::factory()->create();
    $agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-07-15'], [$u1]); // expires in 35 days
    issuedInvoice($this->customer, [['net' => '400.000']], '2026-06-01', $agreement);  // a rent invoice due this month, overdue (grace 06-06)
    (new Cheque)->forceFill(['direction' => 'received', 'customer_id' => $this->customer->id, 'cheque_no' => 'C0', 'bank_name' => 'NBB', 'cheque_date' => '2026-06-05',
        'amount' => '400.000', 'status' => 'held', 'created_by' => User::factory()->create()->id])->save(); // held, dated last week: overdue, not "this week"
    (new Cheque)->forceFill(['direction' => 'received', 'customer_id' => $this->customer->id, 'cheque_no' => 'C1', 'bank_name' => 'NBB', 'cheque_date' => '2026-06-12',
        'amount' => '400.000', 'status' => 'held', 'created_by' => User::factory()->create()->id])->save();
});

test('Management sees all 8 tiles with this company\'s figures', function () {
    $tiles = collect(Dashboard::tiles(matrixUser(RoleName::Management, $this->building)))->pluck('value', 'label');

    expect($tiles->keys()->all())->toBe(['Occupancy', 'Rent due this month', 'Collected this month', 'Overdue total', 'Cheques to deposit this week', 'Open bounced cheques', 'Expiring in 60 days', 'Pending approvals'])
        ->and($tiles['Occupancy'])->toBe('50.0%')
        ->and($tiles['Rent due this month'])->toBe('400.000')
        ->and($tiles['Overdue total'])->toBe('400.000')
        ->and($tiles['Cheques to deposit this week'])->toBe('1')
        ->and($tiles['Expiring in 60 days'])->toBe('1');
});

test('each tile needs its report\'s permission', function () {
    $leasing = collect(Dashboard::tiles(matrixUser(RoleName::Leasing, $this->building)))->pluck('label')->all();
    expect($leasing)->toBe(['Occupancy', 'Expiring in 60 days']); // operational only: no financial, cheque or approval tiles

    Livewire::actingAs(matrixUser(RoleName::Management, $this->building))->test(Dashboard::class)->assertSee('Pending approvals')->assertSee('50.0%');
});

test('rent due sums net amounts: issued VAT-inclusive totals are not mixed with scheduled ones, which carry no VAT yet', function () {
    CompanySetting::current()->forceFill(['vat_registered' => true, 'vat_rate' => '10.00'])->save();
    $agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->for($this->building)->create()]);
    $vat = issuedInvoice($this->customer, [['net' => '100.000', 'tax' => 'standard']], '2026-06-15', $agreement);
    expect($vat->total)->not->toBe($vat->subtotal); // VAT was charged on issue

    $tiles = collect(Dashboard::tiles(matrixUser(RoleName::Management, $this->building)))->pluck('value', 'label');
    expect($tiles['Rent due this month'])->toBe('500.000');
});
