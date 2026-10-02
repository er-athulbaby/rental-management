<?php

use App\Enums\RoleName;
use App\Livewire\Reports\CollectionsReport;
use App\Livewire\Reports\DepositsHeldReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-06-10 09:00', 'Asia/Bahrain'));
    $this->building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->for($this->building)->create(['code' => 'A-1'])]);
    $pay = fn (string $on, string $method, string $amount, string $status = 'confirmed') => tap((new Payment)->forceFill([
        'number' => 'RCP-T-'.uniqid(), 'customer_id' => $this->customer->id, 'received_on' => $on, 'method' => $method, 'amount' => $amount,
        'status' => $status, 'recorded_by' => User::factory()->create()->id, 'posted_at' => now(),
        'reversed_at' => $status === 'reversed' ? now() : null, // payments_reversed_chk ties status to reversed_at
    ]))->save();
    $pay('2026-06-01', 'cash', '100.000');
    $pay('2026-06-01', 'bank_transfer', '50.000');
    $pay('2026-06-03', 'cash', '20.000');
    $pay('2026-06-04', 'deposit_applied', '30.000');
    $pay('2026-06-05', 'cash', '999.000', 'reversed');
});

test('collections by date and method, deposit applied shown separately and not counted', function () {
    $html = Livewire::actingAs($this->finance)->test(CollectionsReport::class)->set('from', '2026-06-01')->set('to', '2026-06-30')->html();
    expect(reportRowText($html, 'Bank Transfer'))->toBe('01/06/2026 Bank Transfer 1 50.000')
        ->and(reportRowText($html, 'Cash'))->toBe('01/06/2026 Cash 1 100.000')
        ->and(reportRowText($html, '03/06/2026'))->toBe('03/06/2026 Cash 1 20.000')
        ->and(reportRowText($html, 'Total collected'))->toBe('Total collected 3 170.000')
        ->and(reportRowText($html, 'Deposit applied'))->toBe('Deposit applied (not a collection) 1 30.000')
        ->and($html)->not->toContain('999.000');
});

test('deposits held per agreement unit as at a date', function () {
    $au = $this->agreement->agreementUnits()->sole();
    DepositMovement::create(['agreement_unit_id' => $au->id, 'type' => 'opening', 'amount' => '400.000', 'source_type' => 'import', 'source_id' => $this->agreement->id, 'posted_at' => '2026-05-01 10:00:00']);
    DepositMovement::create(['agreement_unit_id' => $au->id, 'type' => 'refunded', 'amount' => '-100.000', 'source_type' => 'disbursement', 'source_id' => 1, 'posted_at' => '2026-06-08 10:00:00']);

    $early = Livewire::actingAs($this->finance)->test(DepositsHeldReport::class)->set('to', '2026-06-01')->html();
    expect(reportRowText($early, 'A-1'))->toContain('A-1')->toEndWith('400.000')
        ->and(reportRowText($early, 'Total'))->toEndWith('400.000');
    $later = Livewire::actingAs($this->finance)->test(DepositsHeldReport::class)->set('to', '2026-06-10')->html();
    expect(reportRowText($later, 'A-1'))->toEndWith('300.000');
});

test('the collections and deposits reports need reports.financial', function () {
    $leasing = matrixUser(RoleName::Leasing, $this->building);
    $this->actingAs($leasing)->get(route('reports.collections'))->assertForbidden();
    $this->actingAs($leasing)->get(route('reports.deposits'))->assertForbidden();
});
