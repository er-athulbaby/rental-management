<?php

use App\Enums\RoleName;
use App\Livewire\Reports\Builder;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\SavedReport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $this->other = matrixUser(RoleName::Finance, $this->building);
});

test('saving stores a validated definition under a name, then reopens it', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->set('name', 'Big balances')->call('toggleColumn', 'vat')->call('save')
        ->assertHasNoErrors()->assertRedirect();

    $report = SavedReport::sole();
    expect($report)->name->toBe('Big balances')->visibility->toBe('private')->subject->toBe('invoices')
        ->and($report->definition['columns'])->toContain('vat')->and($report->definition['version'])->toBe(1)
        ->and(Activity::where('event', 'report.saved')->count())->toBe(1);

    Livewire::actingAs($this->finance)->test(Builder::class, ['report' => $report])->assertSet('name', 'Big balances');
});

test('a name is required and a broken definition is not saved', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)->set('name', '')->call('save')->assertHasErrors('name');
    Livewire::actingAs($this->finance)->test(Builder::class)->set('name', 'X')->set('def.columns', [])->call('save')->assertHasErrors('columns');
    expect(SavedReport::count())->toBe(0);
});

test('sharing, copying and who may change a report', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)->set('name', 'Mine')->call('save');
    $report = SavedReport::sole();

    Livewire::actingAs($this->finance)->test(Builder::class, ['report' => $report])->call('share', true);
    expect($report->refresh()->visibility)->toBe('shared');

    Livewire::actingAs($this->other)->test(Builder::class, ['report' => $report])
        ->call('save')->assertForbidden();
    Livewire::actingAs($this->other)->test(Builder::class, ['report' => $report])
        ->call('saveAsCopy')->assertRedirect();
    expect(SavedReport::where('created_by', $this->other->id)->sole())->name->toBe('Mine (copy)')->visibility->toBe('private');

    Livewire::actingAs($this->finance)->test(Builder::class, ['report' => $report])->call('deleteReport')->assertRedirect(route('reports.index'));
    expect(SavedReport::find($report->id))->toBeNull();
});

test('a non-owner cannot share or delete: 403, row unchanged, no audit', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)->set('name', 'Mine')->call('save');
    $report = SavedReport::sole();
    $report->update(['visibility' => 'shared']);

    Livewire::actingAs($this->other)->test(Builder::class, ['report' => $report])->call('share', false)->assertForbidden();
    Livewire::actingAs($this->other)->test(Builder::class, ['report' => $report])->call('deleteReport')->assertForbidden();

    expect($report->fresh())->visibility->toBe('shared')->deleted_at->toBeNull()
        ->and(Activity::whereIn('event', ['report.shared', 'report.deleted'])->count())->toBe(0);
});

test('share, delete and copy write audit rows with the acting user', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)->set('name', 'Mine')->call('save');
    $report = SavedReport::sole();

    Livewire::actingAs($this->finance)->test(Builder::class, ['report' => $report])->call('share', true);
    $report->update(['visibility' => 'shared']);
    Livewire::actingAs($this->other)->test(Builder::class, ['report' => $report])->call('saveAsCopy');
    Livewire::actingAs($this->finance)->test(Builder::class, ['report' => $report])->call('deleteReport');

    expect(Activity::where('event', 'report.shared')->sole()->causer_id)->toBe($this->finance->id)
        ->and(Activity::where('event', 'report.deleted')->sole()->causer_id)->toBe($this->finance->id);
    $copy = Activity::where('event', 'report.saved')->where('causer_id', $this->other->id)->sole();
    expect($copy->properties['copied_from'])->toBe($report->id);
});

test('saving an opened report updates the same row', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)->set('name', 'Mine')->call('save');
    $report = SavedReport::sole();
    $before = $report->definition['columns'];

    Livewire::actingAs($this->finance)->test(Builder::class, ['report' => $report])
        ->set('name', 'Renamed')->call('toggleColumn', 'vat')->call('save')->assertHasNoErrors();

    $fresh = SavedReport::sole();
    expect($fresh)->id->toBe($report->id)->name->toBe('Renamed')->visibility->toBe('private')->updated_by->toBe($this->finance->id)
        ->and($fresh->definition['columns'])->not->toBe($before);
});

test('name length: 121 characters is rejected, a long copy keeps its suffix within 120', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)->set('name', str_repeat('a', 121))->call('save')->assertHasErrors('name');
    expect(SavedReport::count())->toBe(0);

    Livewire::actingAs($this->finance)->test(Builder::class)->set('name', str_repeat('a', 120))->call('saveAsCopy');
    $name = SavedReport::sole()->name;
    expect(mb_strlen($name))->toBeLessThanOrEqual(120)->and($name)->toEndWith('(copy)');
});
