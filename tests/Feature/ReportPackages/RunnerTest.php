<?php

use App\Enums\RoleName;
use App\Livewire\Reports\RentRollReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\SavedReport;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ExcelWriter;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Packages\PackageResult;
use App\Reports\Packages\PackageRunner;
use App\Reports\Packages\PackageSheet;
use App\Reports\Packages\PackageWorkbook;
use App\Reports\Packages\ReadyMade;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));
    [$this->a, $this->b, $this->finance, $this->owner] = reportPackageFixture();
    $this->saved = SavedReport::create(['name' => 'Monthly collections', 'subject' => 'payments', 'visibility' => 'shared', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id,
        'definition' => ['version' => 1, 'subject' => 'payments', 'columns' => ['number', 'amount'], 'period' => ['field' => 'received_on', 'preset' => 'last_month'],
            'totals' => ['amount' => 'sum'], 'sort' => ['field' => 'number', 'dir' => 'asc']]]);
    $this->package = packageWith($this->finance, [
        ['kind' => 'ready_made', 'report' => 'rent_roll', 'settings' => ['building' => null, 'period' => 'today']],
        ['kind' => 'ready_made', 'report' => 'collections', 'settings' => ['building' => null, 'period' => 'last_month']],
        ['kind' => 'saved', 'saved_report_id' => $this->saved->id],
    ]);
    $this->run = fn ($user, $package = null) => app(PackageRunner::class)->run($package ?? $this->package, $user, CarbonImmutable::now('Asia/Bahrain'));
});

test('items run in package order as the given user, each with its title, real dates, rows and headline', function () {
    $result = ($this->run)($this->finance);

    expect(collect($result->sheets)->map(fn (PackageSheet $s) => [$s->title, $s->periodLabel, $s->rowCount, $s->headline, $s->skipped])->all())->toBe([
        ['Rent roll', 'As at 15/10/2026', 6, 'Monthly rent: 800.000', null],
        ['Collections by date and method', '01/09/2026 – 30/09/2026', 2, 'Amount: 100.000', null],
        ['Monthly collections', '01/09/2026 – 30/09/2026', 1, 'Amount: 100.000', null],
    ])
        ->and(array_slice($result->sheets[0]->columns, -2))->toBe(['Overdue', 'Not yet due'])
        ->and($result->sheets[0]->columns)->toBe(ReadyMade::get('rent_roll')::sheet($this->finance, ['to' => '2026-10-15'])['columns'])
        ->and($result->periodLabel())->toBe('As at 15/10/2026')
        ->and($result->allSkipped())->toBeFalse()
        ->and($result->lines()[0])->toBe('Rent roll, As at 15/10/2026: 6 rows · Monthly rent: 800.000')
        ->and($result->skippedLines())->toBe([]);
});

test('relative periods are worked out again on every run', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00', 'Asia/Bahrain'));

    $result = ($this->run)($this->finance);

    expect(collect($result->sheets)->pluck('periodLabel')->all())->toBe(['As at 03/11/2026', '01/10/2026 – 31/10/2026', '01/10/2026 – 31/10/2026'])
        ->and($result->sheets[1]->headline)->toBe('Amount: 300.000');
});

test('a package period resolves to the report\'s own dates', function (string $dates, string $preset, array $settings, string $label) {
    $run = PackageRunner::resolve(['title' => 'X', 'dates' => $dates, 'building' => true, 'options' => [], 'allowed' => true],
        ['building' => 4, 'period' => $preset], CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));

    expect($run)->toBe(['settings' => ['building' => 4, ...$settings], 'period' => $label]);
})->with([
    'range' => ['range', 'last_quarter', ['from' => '2026-07-01', 'to' => '2026-09-30'], '01/07/2026 – 30/09/2026'],
    'single: the period\'s last day' => ['single', 'this_month', ['to' => '2026-10-31'], 'As at 31/10/2026'],
    'single: today' => ['single', 'today', ['to' => '2026-10-15'], 'As at 15/10/2026'],
    'no dates' => ['none', 'last_month', [], 'As at 15/10/2026'],
    'months' => ['months', 'last_year', ['from' => '2025-01', 'to' => '2025-12'], '01/01/2025 – 31/12/2025'],
    'months: never past this month' => ['months', 'this_year', ['from' => '2026-01', 'to' => '2026-10'], '01/01/2026 – 31/10/2026'],
    'months: last 7 days' => ['months', 'last_7_days', ['from' => '2026-10', 'to' => '2026-10'], '01/10/2026 – 31/10/2026'],
]);

test('a period that is not a relative preset is refused', function (mixed $period) {
    expect(fn () => PackageRunner::resolve(['title' => 'X', 'dates' => 'range', 'building' => false, 'options' => [], 'allowed' => true],
        ['period' => $period], CarbonImmutable::now('Asia/Bahrain')))->toThrow(ValidationException::class);
})->with(['custom' => ['custom'], 'unknown' => ['fortnight'], 'array' => [['last_month']], 'missing' => [null]]);

test('clean keeps only what the report takes, and an empty building means all', function () {
    $form = ReadyMade::get('expiring_agreements')::packageForm($this->finance);

    expect(PackageRunner::clean($form, ['building' => '', 'period' => 'today', 'window' => '60', 'from' => '2020-01-01', 'evil' => 1]))->toBe(['building' => null, 'window' => '60'])
        ->and(PackageRunner::clean($form, ['building' => (string) $this->a->id]))->toBe(['building' => $this->a->id])
        ->and(PackageRunner::presets())->not->toHaveKey('custom')->toHaveKey('last_month');
});

test('an item that cannot run is skipped with a note, never a 500, and the rest still run', function () {
    $hidden = Building::factory()->create();
    $leasing = matrixUser(RoleName::Leasing, $this->a);
    $package = packageWith($this->finance, [
        ['kind' => 'saved', 'saved_report_id' => $this->saved->id],
        ['kind' => 'ready_made', 'report' => 'outstanding', 'settings' => ['building' => null]],
        ['kind' => 'ready_made', 'report' => 'expiring_agreements', 'settings' => ['building' => null, 'window' => '45']],
        ['kind' => 'ready_made', 'report' => 'rent_roll', 'settings' => ['building' => $hidden->id, 'period' => 'today']],
        ['kind' => 'ready_made', 'report' => 'retired_report', 'settings' => []],
        ['kind' => 'ready_made', 'report' => 'occupancy', 'settings' => ['building' => null, 'period' => 'today']],
    ]);
    $this->saved->delete();

    $result = ($this->run)($leasing, $package);

    expect(collect($result->sheets)->map(fn (PackageSheet $s) => [$s->title, $s->skipped])->all())->toBe([
        ['Monthly collections', 'Report deleted'],
        ['Outstanding by tenant', 'You do not have access to this report.'],
        ['Agreements expiring', 'Its settings are no longer valid: The selected window is invalid.'],
        ['Rent roll', 'Its settings are no longer valid: The selected building is invalid.'],
        ['retired_report', 'This report is no longer available.'],
        ['Unit availability and occupancy', null],
    ])
        ->and($result->allSkipped())->toBeFalse()
        ->and($result->skippedLines()[0])->toBe('Monthly collections: Report deleted');
});

test('a saved report the user may not use is skipped; one with hidden fields runs without them and says so', function () {
    $leasing = matrixUser(RoleName::Leasing, $this->a);
    $tenants = SavedReport::create(['name' => 'Tenant balances', 'subject' => 'tenants', 'visibility' => 'shared', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id,
        'definition' => ['version' => 1, 'subject' => 'tenants', 'columns' => ['name', 'balance'], 'totals' => ['balance' => 'sum'], 'sort' => ['field' => 'name', 'dir' => 'asc']]]);

    $result = ($this->run)($leasing, packageWith($this->finance, [['kind' => 'saved', 'saved_report_id' => $this->saved->id], ['kind' => 'saved', 'saved_report_id' => $tenants->id]]));

    expect($result->sheets[0]->skipped)->toBe('You do not have access to this report.')
        ->and($result->sheets[1]->skipped)->toBeNull()
        ->and($result->sheets[1]->columns)->toBe(['Tenant'])
        ->and($result->sheets[1]->note)->toContain('Invoice balances')
        ->and($result->sheets[1]->headline)->toBeNull();
});

test('the workbook has one sheet per item in order, each with a title, a period and a blank row above the report\'s own rows', function () {
    $path = sys_get_temp_dir().'/pkg-'.Str::uuid().'.xlsx';
    PackageWorkbook::write(($this->run)($this->finance), $path);
    $exported = xlsxRows(Livewire::actingAs($this->finance)->test(RentRollReport::class)->instance()->export()->getFile()->getPathname());
    $savedFile = sys_get_temp_dir().'/saved-'.Str::uuid().'.xlsx';
    ExcelWriter::write(app(Engine::class)->run($this->finance, ReportDefinition::fromArray($this->saved->definition, $this->finance), limit: null), $savedFile);
    $rent = xlsxRows($path, 'Rent roll');

    expect(SimpleExcelReader::create($path)->getSheetNames())->toBe(['Rent roll', 'Collections by date and method', 'Monthly collections'])
        ->and(array_slice($rent, 0, 2))->toBe([['Rent roll'], ['As at 15/10/2026']])
        ->and(array_filter($rent[2], fn ($v) => $v !== '' && $v !== null))->toBe([])
        ->and(array_slice($rent, 3))->toBe($exported)
        ->and(array_slice(xlsxRows($path, 'Monthly collections'), 3))->toBe(xlsxRows($savedFile));
});

test('sheet names lose characters Excel forbids, are cut to 31 characters and number their clashes; a skipped item shows its reason', function () {
    $titles = ['Rent roll', 'Rent roll', 'A/B: [x]*?\\', str_repeat('x', 40), str_repeat('x', 40), 'Rent Roll', "'quoted'"];
    $sheets = [...array_map(fn (string $t) => new PackageSheet($t, 'P', ['A'], [['1']], 1), $titles), PackageSheet::skip('Gone', 'Report deleted')];
    $path = sys_get_temp_dir().'/names-'.Str::uuid().'.xlsx';

    PackageWorkbook::write(new PackageResult('P', $sheets), $path);

    expect(SimpleExcelReader::create($path)->getSheetNames())->toBe(['Rent roll', 'Rent roll (2)', 'AB x', str_repeat('x', 31), str_repeat('x', 27).' (2)', 'Rent Roll (3)', 'quoted', 'Gone'])
        ->and(xlsxRows($path, 'Gone'))->toBe([['Gone'], ['Report deleted']]);
});
