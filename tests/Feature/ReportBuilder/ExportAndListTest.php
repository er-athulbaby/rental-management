<?php

use App\Enums\RoleName;
use App\Livewire\Reports\Builder;
use App\Livewire\Reports\Index;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\SavedReport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, Building::factory()->create());
});

test('export downloads every row as xlsx and is audited', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)->call('export')->assertFileDownloaded();
    expect(Activity::where('event', 'report.exported')->where('properties->report', 'builder:invoices')->exists())->toBeTrue();
});

test('the Reports page lists my reports and shared ones, with a New report button', function () {
    $mine = SavedReport::create(['name' => 'Mine', 'subject' => 'invoices', 'definition' => ['version' => 1], 'visibility' => 'private', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id]);
    $other = matrixUser(RoleName::Finance, Building::factory()->create());
    SavedReport::create(['name' => 'Theirs shared', 'subject' => 'payments', 'definition' => ['version' => 1], 'visibility' => 'shared', 'created_by' => $other->id, 'updated_by' => $other->id]);
    SavedReport::create(['name' => 'Theirs private', 'subject' => 'payments', 'definition' => ['version' => 1], 'visibility' => 'private', 'created_by' => $other->id, 'updated_by' => $other->id]);

    Livewire::actingAs($this->finance)->test(Index::class)
        ->assertSee('New report')->assertSee('Mine')->assertSee('Theirs shared')->assertDontSee('Theirs private')
        ->assertSeeHtml(e(route('reports.builder', $mine)));
});
