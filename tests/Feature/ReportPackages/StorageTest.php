<?php

use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\ReportPackage;
use App\Models\ReportPackageItem;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $this->package = packageWith($this->finance, [['kind' => 'ready_made', 'report' => 'rent_roll', 'settings' => ['period' => 'today']]]);
    $this->saved = SavedReport::create(['name' => 'R', 'subject' => 'invoices', 'visibility' => 'private', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id,
        'definition' => ['version' => 1, 'subject' => 'invoices', 'columns' => ['number']]]);
});

test('an item is a saved report or a ready-made report, never both or neither', function (array $item) {
    $item = array_map(fn ($v) => $v === ':saved' ? $this->saved->id : $v, $item);

    expect(fn () => ReportPackageItem::create(['report_package_id' => $this->package->id, 'position' => 2, 'settings' => [], ...$item]))
        ->toThrow(QueryException::class);
})->with([
    'saved, no report' => [['kind' => 'saved']],
    'saved with a ready-made key too' => [['kind' => 'saved', 'saved_report_id' => ':saved', 'report' => 'rent_roll']],
    'ready-made, no key' => [['kind' => 'ready_made']],
    'ready-made with a saved report too' => [['kind' => 'ready_made', 'report' => 'rent_roll', 'saved_report_id' => ':saved']],
    'unknown kind' => [['kind' => 'pdf', 'report' => 'rent_roll']],
]);

test('both kinds of item store, and visibility is private or shared', function () {
    $this->package->items()->create(['position' => 2, 'kind' => 'saved', 'saved_report_id' => $this->saved->id, 'settings' => []]);

    expect($this->package->items()->count())->toBe(2)
        ->and(fn () => ReportPackage::create(['name' => 'X', 'visibility' => 'public', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id]))
        ->toThrow(QueryException::class);
});

test('a schedule belongs to exactly one saved report or package', function () {
    $base = ['frequency' => 'daily', 'hour' => 7, 'next_run_at' => now()->addDay(), 'created_by' => $this->finance->id];

    expect(fn () => ReportSchedule::create($base))->toThrow(QueryException::class)
        ->and(fn () => ReportSchedule::create([...$base, 'saved_report_id' => $this->saved->id, 'report_package_id' => $this->package->id]))->toThrow(QueryException::class);

    $forPackage = $this->package->schedules()->create($base);
    $forReport = $this->saved->schedules()->create($base);
    expect($forPackage->saved_report_id)->toBeNull()->and($forPackage->package->is($this->package))->toBeTrue()
        ->and($forReport->report_package_id)->toBeNull()->and($forReport->report->is($this->saved))->toBeTrue();
});

test('items come back in position order, go with their package, and a soft-deleted saved report keeps its item', function () {
    $this->package->items()->create(['position' => 3, 'kind' => 'saved', 'saved_report_id' => $this->saved->id, 'settings' => []]);
    $this->package->items()->create(['position' => 2, 'kind' => 'ready_made', 'report' => 'outstanding', 'settings' => []]);

    expect($this->package->items()->pluck('report')->all())->toBe(['rent_roll', 'outstanding', null]);

    $this->saved->delete();
    expect(ReportPackageItem::where('saved_report_id', $this->saved->id)->exists())->toBeTrue();

    $this->package->forceDelete();
    expect(ReportPackageItem::count())->toBe(0);
});

test('the creator or an admin changes a package; anyone with report access sees a shared one', function () {
    $other = matrixUser(RoleName::Finance, $this->building);
    $leasing = matrixUser(RoleName::Leasing, $this->building);
    $admin = matrixUser(RoleName::Admin, $this->building);
    $nobody = User::factory()->withTwoFactor()->create();

    expect($this->finance->can('view', $this->package))->toBeTrue()
        ->and($other->can('view', $this->package))->toBeFalse()
        ->and($this->finance->can('update', $this->package))->toBeTrue()
        ->and($admin->can('update', $this->package))->toBeTrue()
        ->and($admin->can('delete', $this->package))->toBeTrue()
        ->and($other->can('update', $this->package))->toBeFalse()
        ->and(ReportPackage::visibleTo($other)->count())->toBe(0);

    $this->package->update(['visibility' => 'shared']);

    expect($other->can('view', $this->package))->toBeTrue()
        ->and($leasing->can('view', $this->package))->toBeTrue()
        ->and($nobody->can('view', $this->package))->toBeFalse()
        ->and($other->can('update', $this->package))->toBeFalse()
        ->and($other->can('delete', $this->package))->toBeFalse()
        ->and(ReportPackage::visibleTo($other)->pluck('id')->all())->toBe([$this->package->id]);
});
