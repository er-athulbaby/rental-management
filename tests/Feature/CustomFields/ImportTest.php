<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Import\RunImport;
use App\CustomFields\CustomFields;
use App\Enums\ImportKind;
use App\Enums\RoleName;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\CustomFieldValue;
use App\Models\Owner;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelWriter;

/** An .xlsx with the kind's full header row plus any cf_ columns the first row names (custom field import tests). */
function cfImportFile(ImportKind $kind, array $rows): string
{
    $path = sys_get_temp_dir().'/'.uniqid('cf-'.$kind->value.'-').'.xlsx';
    $writer = SimpleExcelWriter::create($path);
    foreach ($rows as $row) {
        $writer->addRow([...array_fill_keys($kind->headers(), ''), ...$row]);
    }
    $writer->close();

    return $path;
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['go_live_at' => '2026-11-01 00:00:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    fixtureBanks();
    $this->vendor = User::factory()->withTwoFactor()->create()->assignRole(RoleName::VendorSupport);
    customField('building', 'text', 'Title deed');
    customField('unit', 'number', 'Balcony area', ['required' => true]);
    customField('unit', 'file', 'Floor plan');
    customField('owner', 'bool', 'VAT registered');
    customField('customer', 'choice', 'Source', choices: [['Walk-in', 'زيارة'], 'Agent']);
    customField('customer', 'date', 'Visa expiry');
    customField('agreement', 'text', 'Broker');
    $this->unit = fn (string $code, array $cf = []) => ['building_code' => 'MT', 'code' => $code, 'use' => 'residential', 'type' => 'flat',
        'furnishing' => 'unfurnished', 'list_rent' => '450.000', 'blocked' => 'no', ...$cf];
    $this->tenant = fn (string $name, string $id, array $cf = []) => ['type' => 'individual', 'name_en' => $name, 'id_type' => 'cpr', 'id_number' => $id,
        'mobile' => '+973360'.substr($id, -5), ...$cf];
    $this->building = fn (array $cf = []) => cfImportFile(ImportKind::Buildings, [['code' => 'MT', 'name' => 'Marina Tower', 'type' => 'residential', ...$cf]]);
});

test('cf_ columns import through the same rules: choices by English or Arabic label, dd/mm/yyyy dates, required not enforced', function () {
    $result = app(RunImport::class)->handle($this->vendor, [
        'buildings' => ($this->building)(['cf_title_deed' => 'TD-55']),
        'units' => cfImportFile(ImportKind::Units, [($this->unit)('101', ['cf_balcony_area' => '12.75']), ($this->unit)('102', ['cf_balcony_area' => ''])]),
        'owners' => cfImportFile(ImportKind::Owners, [['type' => 'person', 'name_en' => 'Ali Hassan', 'id_type' => 'cpr', 'id_number' => '080101234', 'cf_vat_registered' => 'Yes']]),
        'customers' => cfImportFile(ImportKind::Customers, [
            ($this->tenant)('Sara Ahmed', '090202345', ['cf_source' => 'زيارة', 'cf_visa_expiry' => '31/03/2027']),
            ($this->tenant)('Omar Ali', '090202346', ['cf_source' => 'agent', 'cf_visa_expiry' => '']),
        ]),
    ], commit: true);

    expect($result->errors)->toBe([])->and($result->committed)->toBeTrue();
    $shown = fn ($record) => array_column(CustomFields::display($record), 'text', 'label');
    expect($shown(Building::query()->where('code', 'MT')->sole()))->toBe(['Title deed' => 'TD-55'])
        ->and($shown(Unit::query()->where('code', '101')->sole()))->toBe(['Balcony area' => '12.75'])
        ->and($shown(Unit::query()->where('code', '102')->sole()))->toBe(['Balcony area' => '—']) // required, left blank: imported, shown as missing
        ->and($shown(Owner::query()->sole()))->toBe(['VAT registered' => 'Yes'])
        ->and($shown(Customer::query()->where('id_number', '090202345')->sole()))->toBe(['Source' => 'Walk-in', 'Visa expiry' => '31/03/2027'])
        ->and($shown(Customer::query()->where('id_number', '090202346')->sole()))->toBe(['Source' => 'Agent']);
});

test('a bad cf_ value fails its row by the same rules; an unknown or file cf_ column fails the file', function () {
    $result = app(RunImport::class)->handle($this->vendor, [
        'buildings' => ($this->building)(),
        'units' => cfImportFile(ImportKind::Units, [($this->unit)('101', ['cf_balcony_area' => '1.2345']), ($this->unit)('102', ['cf_balcony_area' => '3'])]),
        'owners' => cfImportFile(ImportKind::Owners, [['type' => 'person', 'name_en' => 'Ali Hassan', 'id_type' => 'cpr', 'id_number' => '080101234', 'cf_unknown' => 'x']]),
        'customers' => cfImportFile(ImportKind::Customers, [($this->tenant)('Sara Ahmed', '090202345', ['cf_source' => 'Newspaper'])]),
    ], commit: true);

    expect($result->committed)->toBeFalse()
        ->and($result->errors['units'])->toBe([2 => ['Enter Balcony area as a number with up to 3 decimals.']])
        ->and($result->errors['owners'])->toBe([1 => ['Unknown custom field column cf_unknown.']])
        ->and($result->errors['customers'])->toBe([2 => ['Source: "Newspaper" is not one of: Walk-in, Agent.']])
        ->and(CustomFieldValue::query()->count())->toBe(0);

    $files = app(RunImport::class)->handle($this->vendor, ['units' => cfImportFile(ImportKind::Units, [($this->unit)('101', ['cf_floor_plan' => 'plan.pdf'])])], commit: false);
    expect($files->errors['units'])->toBe([1 => ['File fields cannot be imported: cf_floor_plan.']]);
});

test('agreement rows take their cf_ values from the first row and must agree on them', function () {
    Storage::fake('local');
    $base = [
        'buildings' => ($this->building)(),
        'units' => cfImportFile(ImportKind::Units, [($this->unit)('101', ['cf_balcony_area' => '5']), ($this->unit)('103', ['cf_balcony_area' => '6'])]),
        'customers' => cfImportFile(ImportKind::Customers, [($this->tenant)('Sara Ahmed', '090202345')]),
    ];
    $row = fn (string $unit, string $broker) => ['import_ref' => 'L-001', 'customer_id_type' => 'cpr', 'customer_id_number' => '090202345',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'frequency' => 'monthly', 'building_code' => 'MT', 'unit_code' => $unit,
        'rent' => '450.000', 'tax_category' => 'exempt', 'cf_broker' => $broker];

    $bad = app(RunImport::class)->handle($this->vendor, [...$base, 'agreements' => cfImportFile(ImportKind::Agreements, [$row('101', 'Gulf Realty'), $row('103', 'Other')])], commit: false);
    expect($bad->errors['agreements'])->toBe([2 => ['Rows of agreement L-001 disagree on cf_broker.']]);

    $good = app(RunImport::class)->handle($this->vendor, [...$base, 'agreements' => cfImportFile(ImportKind::Agreements, [$row('101', 'Gulf Realty'), $row('103', 'Gulf Realty')])], commit: true);
    expect($good->errors)->toBe([])
        ->and(array_column(CustomFields::display(Agreement::query()->where('import_ref', 'L-001')->sole()), 'text', 'label'))->toBe(['Broker' => 'Gulf Realty']);
});

test('a stray "custom" column in the sheet is ignored, not validated as custom field input', function () {
    $sheet = cfImportFile(ImportKind::Units, [($this->unit)('101', ['custom' => 'oops', 'cf_balcony_area' => '2'])]);
    $buildings = ($this->building)();
    $result = app(RunImport::class)->handle($this->vendor, ['buildings' => $buildings, 'units' => $sheet], commit: true);

    expect($result->errors)->toBe([])
        ->and(array_column(CustomFields::display(Unit::query()->where('code', '101')->sole()), 'text', 'label'))->toBe(['Balcony area' => '2']);
});

test('keys with a digit beside a letter import: the reader splits them in the header, so both sides are snake-cased alike', function () {
    customField('unit', 'text', 'Plot 2B');
    customField('unit', 'number', 'Area m2');
    $result = app(RunImport::class)->handle($this->vendor, [
        'buildings' => ($this->building)(),
        'units' => cfImportFile(ImportKind::Units, [($this->unit)('101', ['cf_plot_2b' => 'P-7', 'cf_area_m2' => '8.5'])]),
    ], commit: true);

    expect($result->errors)->toBe([])
        ->and(array_column(CustomFields::display(Unit::query()->where('code', '101')->sole()), 'text', 'label'))
        ->toBe(['Balcony area' => '—', 'Plot 2B' => 'P-7', 'Area m2' => '8.5']);
});

test('an Excel FALSE cell is "no" for a yes/no field', function () {
    $result = app(RunImport::class)->handle($this->vendor, [
        'owners' => cfImportFile(ImportKind::Owners, [['type' => 'person', 'name_en' => 'Ali Hassan', 'id_type' => 'cpr', 'id_number' => '080101234', 'cf_vat_registered' => false]]),
    ], commit: true);

    expect($result->errors)->toBe([])
        ->and(array_column(CustomFields::display(Owner::query()->sole()), 'text', 'label'))->toBe(['VAT registered' => 'No']);
});

test('a bad date cell names the dd/mm/yyyy format', function () {
    $result = app(RunImport::class)->handle($this->vendor, [
        'customers' => cfImportFile(ImportKind::Customers, [($this->tenant)('Sara Ahmed', '090202345', ['cf_visa_expiry' => 'soon'])]),
    ], commit: false);

    expect($result->errors['customers'])->toBe([2 => ['Visa expiry: enter a date as dd/mm/yyyy.']]);
});

test('"Plot 2B" and "Plot 2 B" get distinct keys and import into their own fields', function () {
    $a = customField('unit', 'text', 'Plot 2B');
    $b = customField('unit', 'text', 'Plot 2 B');
    expect([$a->key, $b->key])->toBe(['plot_2_b', 'plot_2_b_2']);

    $result = app(RunImport::class)->handle($this->vendor, [
        'buildings' => ($this->building)(),
        'units' => cfImportFile(ImportKind::Units, [($this->unit)('101', ['cf_plot_2_b' => 'first', 'cf_plot_2_b_2' => 'second'])]),
    ], commit: true);

    expect($result->errors)->toBe([])
        ->and(array_column(CustomFields::display(Unit::query()->where('code', '101')->sole()), 'text', 'label'))
        ->toMatchArray(['Plot 2B' => 'first', 'Plot 2 B' => 'second']);
});
