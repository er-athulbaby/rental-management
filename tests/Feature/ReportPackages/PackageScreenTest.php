<?php

use App\Enums\RoleName;
use App\Livewire\Reports\Index;
use App\Livewire\Reports\Package;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\ReportPackage;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));
    [$this->a, $this->b, $this->finance, $this->owner] = reportPackageFixture();
    $this->leasing = matrixUser(RoleName::Leasing, $this->a);
    $this->saved = SavedReport::create(['name' => 'Monthly collections', 'subject' => 'payments', 'visibility' => 'private', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id,
        'definition' => ['version' => 1, 'subject' => 'payments', 'columns' => ['number', 'amount'], 'period' => ['field' => 'received_on', 'preset' => 'last_month'],
            'totals' => ['amount' => 'sum'], 'sort' => ['field' => 'number', 'dir' => 'asc']]]);
    $this->overstays = [['kind' => 'ready_made', 'report' => 'overstays', 'settings' => []]];
});

test('a package is built from saved and ready-made reports, reordered, with settings, and saved in order', function () {
    Livewire::actingAs($this->finance)->test(Package::class)
        ->set('name', 'Monthly pack')
        ->set('add', 'ready:rent_roll')->call('addItem')
        ->set('add', 'saved:'.$this->saved->id)->call('addItem')
        ->set('add', 'ready:expiring_agreements')->call('addItem')
        ->set('items.2.settings.window', '60')
        ->set('items.0.settings.building', (string) $this->a->id)
        ->call('moveItem', 2, -1)
        ->call('save')->assertHasNoErrors()
        ->assertRedirect(route('reports.package', ReportPackage::sole()));

    $package = ReportPackage::sole();
    expect($package->name)->toBe('Monthly pack')->and($package->visibility)->toBe('private')->and($package->created_by)->toBe($this->finance->id)
        ->and($package->items->map(fn ($i) => [$i->position, $i->kind, $i->report, $i->saved_report_id])->all())->toBe([
            [1, 'ready_made', 'rent_roll', null], [2, 'ready_made', 'expiring_agreements', null], [3, 'saved', null, $this->saved->id]])
        ->and($package->items[0]->settings)->toEqual(['building' => $this->a->id, 'period' => 'today'])
        ->and($package->items[1]->settings)->toEqual(['building' => null, 'window' => '60'])
        ->and(Activity::where('event', 'report.saved')->count())->toBe(1);

    Livewire::actingAs($this->finance)->test(Package::class, ['package' => $package])
        ->assertSee('Rent roll')->assertSee('Agreements expiring')->assertSee('Monthly collections')
        ->call('removeItem', 0)->call('save')->assertHasNoErrors();
    expect($package->items()->pluck('position')->all())->toBe([1, 2]);
});

test('tampered name, items and settings give messages, never a 500', function (string $property, mixed $value, string $error) {
    Livewire::actingAs($this->finance)->test(Package::class)->set('name', 'P')->set('items', $this->overstays)
        ->set($property, $value)->assertOk()
        ->call('save')->assertHasErrors($error);

    expect(ReportPackage::count())->toBe(0);
})->with([
    'no name' => ['name', '', 'name'],
    'name as an array' => ['name', ['x'], 'name'],
    'name too long' => ['name', str_repeat('x', 121), 'name'],
    'items as text' => ['items', 'x', 'items'],
    'no items' => ['items', [], 'items'],
    'sixteen items' => ['items', array_fill(0, 16, ['kind' => 'ready_made', 'report' => 'overstays', 'settings' => []]), 'items'],
    'unknown kind' => ['items', [['kind' => 'pdf']], 'items.0'],
    'not an item' => ['items', ['junk'], 'items.0'],
    'unknown report' => ['items', [['kind' => 'ready_made', 'report' => 'nope', 'settings' => []]], 'items.0'],
    'report as an array' => ['items', [['kind' => 'ready_made', 'report' => ['rent_roll']]], 'items.0'],
    'unknown option' => ['items', [['kind' => 'ready_made', 'report' => 'expiring_agreements', 'settings' => ['window' => '45']]], 'items.0.settings.window'],
    'option as an array' => ['items', [['kind' => 'ready_made', 'report' => 'expiring_agreements', 'settings' => ['window' => ['30']]]], 'items.0.settings.window'],
    'fixed dates' => ['items', [['kind' => 'ready_made', 'report' => 'rent_roll', 'settings' => ['period' => 'custom']]], 'items.0.settings.period'],
    'settings as text' => ['items', [['kind' => 'ready_made', 'report' => 'rent_roll', 'settings' => 'x']], 'items.0.settings.period'],
    'unknown saved report' => ['items', [['kind' => 'saved', 'saved_report_id' => 999999]], 'items.0'],
    'saved report id as an array' => ['items', [['kind' => 'saved', 'saved_report_id' => ['1']]], 'items.0'],
]);

test('the page survives tampered items without a 500', function () {
    Livewire::actingAs($this->finance)->test(Package::class)
        ->set('items', [['kind' => ['x']], 'junk', ['kind' => 'ready_made', 'report' => ['x'], 'settings' => 'y'], ['kind' => 'saved', 'saved_report_id' => 'z']])
        ->assertOk()->assertSee('Unknown report')
        ->set('items', 'x')->assertOk()
        ->call('moveItem', 5, 1)->call('removeItem', 9)->assertOk();
});

test('an editor can only use buildings, saved reports and reports they may see', function () {
    $hidden = Building::factory()->create();
    $page = fn () => Livewire::actingAs($this->leasing)->test(Package::class)->set('name', 'P');

    $page()->set('items', [['kind' => 'ready_made', 'report' => 'rent_roll', 'settings' => ['building' => (string) $hidden->id, 'period' => 'today']]])
        ->call('save')->assertHasErrors('items.0.settings.building');
    $page()->set('items', [['kind' => 'saved', 'saved_report_id' => $this->saved->id]])->call('save')->assertHasErrors('items.0'); // Finance's private report
    $page()->set('items', [['kind' => 'ready_made', 'report' => 'outstanding', 'settings' => []]])->call('save')->assertHasErrors('items.0'); // needs reports.financial
    $page()->set('add', 'ready:outstanding')->call('addItem')->assertHasErrors('add')->assertDontSee('Outstanding by tenant');
    $page()->set('add', 'saved:'.$this->saved->id)->call('addItem')->assertHasErrors('add');

    expect(ReportPackage::count())->toBe(0);
});

test('viewers of a shared package download it but cannot change it; the creator or an admin can', function () {
    $package = packageWith($this->finance, $this->overstays);
    $other = matrixUser(RoleName::Finance, $this->a);
    $this->actingAs($other)->get(route('reports.package', $package))->assertForbidden();

    $package->update(['visibility' => 'shared']);
    $open = fn ($as) => Livewire::actingAs($as)->test(Package::class, ['package' => $package]);
    $open($other)->assertOk()->assertDontSeeHtml('wire:click="deletePackage"')->assertDontSeeHtml('wire:click="save"');
    $open($other)->call('save')->assertForbidden();
    $open($other)->call('share', false)->assertForbidden();
    $open($other)->call('deletePackage')->assertForbidden();
    $open($other)->set('add', 'ready:rent_roll')->call('addItem')->assertForbidden();
    $open($other)->call('download')->assertFileDownloaded();

    $open(matrixUser(RoleName::Admin, $this->a))->set('name', 'Board pack')->call('save')->assertHasNoErrors();
    expect($package->fresh()->name)->toBe('Board pack')->and($package->fresh()->created_by)->toBe($this->finance->id);
});

test('the creator shares and deletes a package; deleting removes its schedules', function () {
    $package = packageWith($this->finance, $this->overstays);
    $schedule = $package->schedules()->create(['frequency' => 'daily', 'hour' => 7, 'next_run_at' => now()->addDay(), 'created_by' => $this->finance->id]);

    Livewire::actingAs($this->finance)->test(Package::class, ['package' => $package])->call('share', true);
    expect($package->fresh()->visibility)->toBe('shared')->and(Activity::where('event', 'report.shared')->count())->toBe(1);

    Livewire::actingAs($this->finance)->test(Package::class, ['package' => $package])->call('deletePackage')->assertRedirect(route('reports.index'));
    expect(ReportPackage::count())->toBe(0)->and(ReportPackage::withTrashed()->count())->toBe(1)
        ->and(ReportSchedule::find($schedule->id))->toBeNull()
        ->and(Activity::where('event', 'report.deleted')->count())->toBe(1);
});

test('Download now builds the workbook as the person clicking and is audited', function () {
    $package = packageWith($this->finance, [
        ['kind' => 'ready_made', 'report' => 'rent_roll', 'settings' => ['building' => null, 'period' => 'today']],
        ['kind' => 'ready_made', 'report' => 'outstanding', 'settings' => ['building' => null]],
    ], 'Monthly pack', 'shared');
    $page = Livewire::actingAs($this->leasing)->test(Package::class, ['package' => $package]);

    $page->call('download')->assertFileDownloaded('monthly-pack-2026-10-15.xlsx');
    $path = $page->instance()->download()->getFile()->getPathname();
    $props = Activity::where('event', 'report.exported')->first()->properties;

    expect(SimpleExcelReader::create($path)->getSheetNames())->toBe(['Rent roll', 'Outstanding by tenant'])
        ->and(xlsxRows($path, 'Outstanding by tenant'))->toBe([['Outstanding by tenant'], ['You do not have access to this report.']])
        ->and(collect(xlsxRows($path, 'Rent roll'))->pluck(0)->all())->toContain('AAA')->not->toContain('BBB')
        ->and($props['report'])->toBe('package')->and($props['package'])->toBe($package->id);
});

test('the Reports page lists my packages and shared ones, and offers a new package', function () {
    $admin = matrixUser(RoleName::Admin, $this->a);
    packageWith($this->finance, $this->overstays, 'My pack');
    packageWith($admin, $this->overstays, 'Board pack', 'shared');
    packageWith($admin, $this->overstays, 'Secret pack');

    Livewire::actingAs($this->finance)->test(Index::class)
        ->assertSee('Packages')->assertSee('New package')->assertSee('My pack')->assertSee('Board pack')->assertDontSee('Secret pack')
        ->assertSeeHtml(route('reports.package'));
});

test('the list holds at most 15 items, and moving stops at both ends', function () {
    $page = Livewire::actingAs($this->finance)->test(Package::class)->set('items', array_fill(0, 14, $this->overstays[0]));
    $page->set('add', 'ready:rent_roll')->call('addItem')->assertHasNoErrors();
    expect($page->get('items'))->toHaveCount(15);
    $page->set('add', 'ready:rent_roll')->call('addItem')->assertHasErrors('add');
    expect($page->get('items'))->toHaveCount(15);

    $page->call('moveItem', 14, 1)->call('moveItem', 0, -1);
    expect($page->get('items.14.report'))->toBe('rent_roll')->and($page->get('items.0.report'))->toBe('overstays');
    $page->call('moveItem', 14, -1);
    expect($page->get('items.13.report'))->toBe('rent_roll');
});

test('the move buttons carry labels', function () {
    Livewire::actingAs($this->finance)->test(Package::class)->set('items', $this->overstays)
        ->assertSeeHtml('aria-label="Move up"')->assertSeeHtml('aria-label="Move down"');
});
