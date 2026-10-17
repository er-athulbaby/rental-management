<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Expenses\ReverseExpense;
use App\Actions\Maintenance\CompleteWorkOrder;
use App\Actions\Maintenance\ResolveMaintenanceRequest;
use App\Enums\RoleName as R;
use App\Livewire\Reports\Index;
use App\Livewire\Reports\MaintenanceCostReport;
use App\Livewire\Reports\OpenMaintenanceReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Unit;
use App\Models\WorkOrder;
use App\Reports\BuildingProfitability;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->a = Building::factory()->create(['code' => 'AAA']);
    $this->b = Building::factory()->create(['code' => 'BBB']);
    $this->unitA = Unit::factory()->for($this->a)->create(['code' => '101']);
    $this->unitB = Unit::factory()->for($this->b)->create(['code' => '201']);
    activeOwnerContract(['building_id' => $this->a->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unitA]);
    $this->pm = matrixUser(R::PropertyManager, $this->a);
    $this->plumber = maintenanceVendor(['name' => 'Ali Plumbing', 'trades' => ['plumbing']]);
    $this->cool = maintenanceVendor(['name' => 'Gulf Cool', 'trades' => ['ac']]);
    $this->old = maintenanceRequest($this->pm, $this->unitA, ['priority' => 'urgent']); // logged 10/10
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    $this->complete = fn (WorkOrder $wo, string $net, string $tax, string $chargeTo = 'company') => app(CompleteWorkOrder::class)->handle($this->pm, $wo, [
        'completion_note' => 'Done', 'completed_on' => '2026-10-17', 'actual_net' => $net, 'actual_tax' => $tax, 'charge_to' => $chargeTo,
        'category' => 'maintenance', 'description' => 'Repair']);
});

test('Open maintenance: open and in-progress requests, urgent first, with their age in days and open vendors', function () {
    $acB = maintenanceRequest($this->pm, $this->unitB, ['category' => 'ac']);
    workOrderFor($this->pm, $acB, $this->cool);
    $resolved = app(ResolveMaintenanceRequest::class)->handle($this->pm, maintenanceRequest($this->pm, $this->unitA));

    $html = Livewire::actingAs($this->pm)->test(OpenMaintenanceReport::class)->html();
    expect(reportRowText($html, $this->old->number))->toBe("{$this->old->number} AAA 101 Plumbing Urgent 7")
        ->and(reportRowText($html, $acB->number))->toBe("{$acB->number} BBB 201 Air conditioning Normal 0 Gulf Cool")
        ->and(strpos($html, $this->old->number))->toBeLessThan(strpos($html, $acB->number))
        ->and($html)->not->toContain($resolved->number);

    $leasing = Livewire::actingAs(matrixUser(R::Leasing, $this->a))->test(OpenMaintenanceReport::class)->html();
    expect($leasing)->toContain($this->old->number)->not->toContain($acB->number);
});

test('Maintenance cost: linked, recorded expenses by building, unit or category; company and tenant at Building profitability\'s rule, owner apart', function () {
    ($this->complete)(workOrderFor($this->pm, $this->old, $this->plumber), '100', '10');
    ($this->complete)(workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unitA), $this->plumber), '50', '5', 'owner');
    $acB = maintenanceRequest($this->pm, $this->unitB, ['category' => 'ac']);
    ($this->complete)(workOrderFor($this->pm, $acB, $this->cool), '20', '2');
    $reversed = ($this->complete)(workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unitB, ['category' => 'ac']), $this->cool), '30', '3');
    app(ReverseExpense::class)->handle(matrixUser(R::Finance, $this->a), $reversed->expense, 'Entered twice');

    $report = fn (array $set) => tap(Livewire::actingAs($this->pm)->test(MaintenanceCostReport::class), fn ($p) => collect(['from' => '2026-10-01', 'to' => '2026-10-31', ...$set])->each(fn ($v, $k) => $p->set($k, $v)))->html();

    $byBuilding = $report(['group' => 'building']);
    expect(reportRowText($byBuilding, 'AAA'))->toBe('AAA 2 100.000 55.000')
        ->and(reportRowText($byBuilding, 'BBB'))->toBe('BBB 1 20.000 0.000')
        ->and(reportRowText($byBuilding, 'Total'))->toBe('Total 3 120.000 55.000');
    expect(reportRowText($report(['group' => 'unit']), 'BBB/201'))->toBe('BBB/201 1 20.000 0.000');
    $byCategory = $report(['group' => 'category']);
    expect(reportRowText($byCategory, 'Air conditioning'))->toBe('Air conditioning 1 20.000 0.000')
        ->and(reportRowText($byCategory, 'Plumbing'))->toBe('Plumbing 2 100.000 55.000');
    expect(reportRowText($report(['group' => 'building', 'from' => '2026-10-18', 'to' => '2026-10-31']), 'AAA'))->toBe(''); // completed on 17/10

    // Spec §8: the company-and-tenant column is Building profitability's expense figure for the same building and dates.
    expect(Fils::fromDecimal('100.000'))->toBe(BuildingProfitability::for($this->a, '2026-10-01', '2026-10-31')['expenses']);
    CompanySetting::current()->forceFill(['vat_registered' => false])->save();
    expect(reportRowText($report(['group' => 'building']), 'AAA'))->toBe('AAA 2 110.000 55.000')
        ->and(Fils::fromDecimal('110.000'))->toBe(BuildingProfitability::for($this->a, '2026-10-01', '2026-10-31')['expenses']);
});

test('both reports export to Excel, audited, need maintenance.view as well, and appear in the catalogue', function () {
    Livewire::actingAs($this->pm)->test(MaintenanceCostReport::class)->set('group', 'unit')->call('export')->assertFileDownloaded();
    Livewire::actingAs($this->pm)->test(OpenMaintenanceReport::class)->call('export')->assertFileDownloaded();

    expect(Activity::query()->where('event', 'report.exported')->where('properties->report', 'maintenance_cost')->sole()->properties['group'])->toBe('unit')
        ->and(Activity::query()->where('event', 'report.exported')->where('properties->report', 'open_maintenance')->exists())->toBeTrue();

    $finance = matrixUser(R::Finance, $this->a); // reports.operational without maintenance.view
    $this->actingAs($finance)->get(route('reports.open-maintenance'))->assertForbidden();
    $this->actingAs($finance)->get(route('reports.maintenance-cost'))->assertForbidden();
    $this->actingAs(matrixUser(R::Leasing, $this->a))->get(route('reports.maintenance-cost'))->assertOk();

    Livewire::actingAs($this->pm)->test(Index::class)->assertSee(route('reports.open-maintenance'))->assertSee(route('reports.maintenance-cost'));
    Livewire::actingAs($finance)->test(Index::class)->assertDontSee(route('reports.open-maintenance'));
});

test('a tampered grouping or date is a message, never a 500', function () {
    Livewire::actingAs($this->pm)->test(MaintenanceCostReport::class)->set('group', 'vendor')->assertOk()->assertHasErrors('group');
    Livewire::actingAs($this->pm)->test(MaintenanceCostReport::class)->set('group', ['unit'])->assertOk()->assertHasErrors('group');
    Livewire::actingAs($this->pm)->test(MaintenanceCostReport::class)->set('from', '31/10/2026')->assertOk()->assertHasErrors('from');
});
