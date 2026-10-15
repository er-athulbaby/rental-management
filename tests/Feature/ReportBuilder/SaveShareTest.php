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
    $page = Livewire::actingAs($this->finance)->test(Builder::class)->set('name', 'Mine')->call('save');
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
