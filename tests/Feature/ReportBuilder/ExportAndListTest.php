<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Reports\Builder;
use App\Livewire\Reports\Index;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\SavedReport;
use App\Models\Unit;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ExcelWriter;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelReader;

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

test('a report whose subject is no longer registered is hidden, not a crash, on the Reports page', function () {
    $other = matrixUser(RoleName::Finance, Building::factory()->create());
    SavedReport::create(['name' => 'Stale one', 'subject' => 'gone', 'definition' => ['version' => 1], 'visibility' => 'shared', 'created_by' => $other->id, 'updated_by' => $other->id]);

    Livewire::actingAs($this->finance)->test(Index::class)->assertOk()->assertDontSee('Stale one');
});

test('a viewer of a shared report can export it', function () {
    $owner = matrixUser(RoleName::Finance, Building::factory()->create());
    $report = SavedReport::create(['name' => 'Shared', 'subject' => 'invoices', 'definition' => ReportDefinition::fresh(Registry::get('invoices'), $owner)->toArray(), 'visibility' => 'shared', 'created_by' => $owner->id, 'updated_by' => $owner->id]);

    Livewire::actingAs($this->finance)->test(Builder::class, ['report' => $report])
        ->assertSee('Export to Excel')->call('export')->assertFileDownloaded();
});

test('opening and exporting a saved report set last_run_at but not updated_at', function () {
    $report = SavedReport::create(['name' => 'Mine', 'subject' => 'invoices', 'definition' => ReportDefinition::fresh(Registry::get('invoices'), $this->finance)->toArray(), 'visibility' => 'private', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id]);
    $updated = $report->fresh()->updated_at;

    $this->travel(2)->hours();
    $component = Livewire::actingAs($this->finance)->test(Builder::class, ['report' => $report]);
    expect($report->fresh()->last_run_at)->not->toBeNull();
    expect($report->fresh()->updated_at->equalTo($updated))->toBeTrue();

    $this->travel(1)->hours();
    $component->call('export');
    expect($report->fresh()->updated_at->equalTo($updated))->toBeTrue();
});

test('a report name made only of symbols still gives a usable filename', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)->set('name', '!!!')
        ->call('export')->assertFileDownloaded('invoices-'.now('Asia/Bahrain')->toDateString().'.xlsx');
});

test('the Excel file carries group subtotal rows', function () {
    app(EnsureNumberSequences::class)(2026);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));
    $tenant = Customer::factory()->create();
    $agreement = activeAgreement(['customer_id' => $tenant->id, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31'], [Unit::factory()->for($this->finance->buildings->first() ?? Building::factory()->create())->create()]);
    issuedInvoice($tenant, [['net' => '400.000', 'au' => $agreement->agreementUnits->first()]], '2026-10-01', $agreement);
    $result = app(Engine::class)->run($this->finance, ReportDefinition::fromArray(['subject' => 'invoices',
        'columns' => ['number', 'balance'], 'groups' => [['field' => 'status']], 'totals' => ['balance' => 'sum']], $this->finance), limit: null);
    $path = sys_get_temp_dir().'/t-'.uniqid().'.xlsx';
    ExcelWriter::write($result, $path);
    $rows = SimpleExcelReader::create($path)->getRows()->all();

    expect(collect($rows)->pluck('Invoice')->filter(fn ($v) => str_starts_with((string) $v, 'Subtotal'))->count())->toBeGreaterThan(0);
});
