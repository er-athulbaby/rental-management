<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Expenses\RecordExpense;
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

test('Maintenance cost: a building-scoped user sees only their building; the building filter cannot widen it; a trashed building is out of every scope', function () {
    ($this->complete)(workOrderFor($this->pm, $this->old, $this->plumber), '100', '10');
    ($this->complete)(workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unitB, ['category' => 'ac']), $this->cool), '20', '2');
    $leasing = matrixUser(R::Leasing, $this->a);
    $page = fn ($user, array $set = []) => tap(Livewire::actingAs($user)->test(MaintenanceCostReport::class), fn ($p) => collect(['from' => '2026-10-01', 'to' => '2026-10-31', ...$set])->each(fn ($v, $k) => $p->set($k, $v)))->html();

    $own = $page($leasing);
    expect(reportRowText($own, 'AAA'))->toBe('AAA 1 100.000 0.000')
        ->and(reportRowText($own, 'BBB'))->toBe('')
        ->and(reportRowText($own, 'Total'))->toBe('Total 1 100.000 0.000');
    // Another building's id in the filter gives nothing, never that building's rows.
    expect(reportRowText($page($leasing, ['building' => $this->b->id]), 'BBB'))->toBe('')
        ->and(reportRowText($page($leasing, ['building' => $this->b->id]), 'Total'))->toBe('')
        ->and(reportRowText($page($leasing, ['building' => $this->a->id]), 'AAA'))->toBe('AAA 1 100.000 0.000');

    $this->b->delete();
    $admin = matrixUser(R::Admin, $this->a); // buildings.view-all
    $all = $page($admin);
    expect(reportRowText($all, 'AAA'))->toBe('AAA 1 100.000 0.000')
        ->and(reportRowText($all, 'BBB'))->toBe('')
        ->and(reportRowText($all, 'Total'))->toBe('Total 1 100.000 0.000');
});

test('Maintenance cost agrees with Building profitability once unlinked and reversed expenses are accounted for', function () {
    ($this->complete)(workOrderFor($this->pm, $this->old, $this->plumber), '100', '10');
    ($this->complete)(workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unitB, ['category' => 'ac']), $this->cool), '20', '2');
    $reversed = ($this->complete)(workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unitA), $this->plumber), '30', '3');
    app(ReverseExpense::class)->handle(matrixUser(R::Finance, $this->a), $reversed->expense, 'Entered twice');
    // A company expense that no work order links to: Building profitability counts it, the cost report must not.
    app(RecordExpense::class)->handle($this->pm, ['building_id' => $this->a->id, 'unit_id' => $this->unitA->id, 'category' => 'maintenance',
        'description' => 'Light bulbs', 'expense_date' => '2026-10-17', 'net' => '40', 'tax_amount' => '4', 'charge_to' => 'company']);
    $unlinked = ['net' => '40.000', 'total' => '44.000'];

    foreach ([true, false] as $registered) {
        CompanySetting::current()->forceFill(['vat_registered' => $registered])->save();
        $key = $registered ? 'net' : 'total';
        $html = tap(Livewire::actingAs($this->pm)->test(MaintenanceCostReport::class), fn ($p) => $p->set('from', '2026-10-01')->set('to', '2026-10-31'))->html();
        $linked = $registered ? ['AAA' => '100.000', 'BBB' => '20.000'] : ['AAA' => '110.000', 'BBB' => '22.000'];
        foreach ([$this->a, $this->b] as $building) {
            $expenses = BuildingProfitability::for($building, '2026-10-01', '2026-10-31')['expenses'];
            $unlinkedFils = $building->is($this->a) ? Fils::fromDecimal($unlinked[$key]) : 0;
            $cost = reportRowText($html, $building->code);
            expect($cost)->toBe("{$building->code} 1 {$linked[$building->code]} 0.000")
                ->and($expenses - $unlinkedFils)->toBe(Fils::fromDecimal($linked[$building->code])) // profitability = linked + unlinked, the reversed 33 in neither
                ->and($expenses)->toBeGreaterThan(0);
        }
    }
});

test('Maintenance cost by unit keeps the code of a trashed unit', function () {
    ($this->complete)(workOrderFor($this->pm, $this->old, $this->plumber), '100', '10');
    $this->unitA->delete();
    $html = Livewire::actingAs($this->pm)->test(MaintenanceCostReport::class)->set('from', '2026-10-01')->set('to', '2026-10-31')->set('group', 'unit')->html();
    expect(reportRowText($html, 'AAA/101'))->toBe('AAA/101 1 100.000 0.000');
});
