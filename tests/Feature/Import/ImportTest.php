<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Import\RunImport;
use App\Enums\ApprovalStatus;
use App\Enums\ImportKind;
use App\Enums\OwnerContractStatus;
use App\Enums\RoleName;
use App\Livewire\Import\Index;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

/** Writes an .xlsx with the kind's full header row; $rows only list the columns they fill. */
function importFile(ImportKind $kind, array $rows): string
{
    $path = sys_get_temp_dir().'/'.uniqid($kind->value.'-').'.xlsx';
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
    $this->vendor = User::factory()->withTwoFactor()->create()->assignRole(RoleName::VendorSupport);

    $this->files = [
        'buildings' => importFile(ImportKind::Buildings, [
            ['code' => 'MT', 'name' => 'Marina Tower', 'type' => 'residential', 'floors_count' => 12],
        ]),
        'units' => importFile(ImportKind::Units, [
            ['building_code' => 'MT', 'code' => '101', 'use' => 'residential', 'type' => 'flat', 'furnishing' => 'unfurnished', 'list_rent' => '450.000', 'blocked' => 'no'],
            ['building_code' => 'MT', 'code' => '102', 'use' => 'residential', 'type' => 'flat', 'furnishing' => 'semi', 'list_rent' => '480.500', 'blocked' => 'yes', 'blocked_reason' => 'Renovation'],
        ]),
        'owners' => importFile(ImportKind::Owners, [
            ['type' => 'person', 'name_en' => 'Ali Hassan', 'id_type' => 'cpr', 'id_number' => '080101234', 'phone' => '+97333000000', 'iban' => 'BH67BMAG00001299123456', 'bank_name' => 'NBB', 'account_name' => 'Ali Hassan'],
        ]),
        'owner_contracts' => importFile(ImportKind::OwnerContracts, [
            ['owner_id_type' => 'cpr', 'owner_id_number' => '080101234', 'building_code' => 'MT', 'units' => '101', 'type' => 'managed',
                'start_date' => '01/01/2026', 'end_date' => '2026-12-31', 'fee_type' => 'percent_collected', 'fee_value' => '7', 'deposits_held_by' => 'company'],
            ['owner_id_type' => 'cpr', 'owner_id_number' => '080101234', 'building_code' => 'MT', 'units' => '102', 'type' => 'leased',
                'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'rent_amount' => '300.000', 'payment_frequency' => 'monthly'],
        ]),
    ];
});

test('a dry run validates every file and saves nothing', function () {
    $result = app(RunImport::class)->handle($this->vendor, $this->files, commit: false);

    expect($result->errors)->toBe([])
        ->and($result->counts)->toBe(['buildings' => 1, 'units' => 2, 'owners' => 1, 'owner_contracts' => 2])
        ->and($result->committed)->toBeFalse()
        ->and(Building::count() + Unit::count() + Owner::count() + OwnerContract::count())->toBe(0);
});

test('a clean import saves everything and activates contracts with an Imported by approval', function () {
    $result = app(RunImport::class)->handle($this->vendor, $this->files, commit: true);

    expect($result->committed)->toBeTrue();

    $owner = Owner::sole();
    expect($owner->id_number)->toBe('080101234') // leading zero kept: the column is Text
        ->and($owner->iban)->toBe('BH67BMAG00001299123456')
        ->and(Unit::where('code', '102')->sole()->blocked)->toBeTrue();

    $contract = OwnerContract::where('type', 'managed')->sole();
    $approval = $contract->approvals()->sole();
    expect($contract->status)->toBe(OwnerContractStatus::Active)
        ->and($contract->number)->toBe('OC-2026-000001')
        ->and($contract->start_date->toDateString())->toBe('2026-01-01')
        ->and($contract->units()->count())->toBe(1)
        ->and($approval->status)->toBe(ApprovalStatus::Approved)
        ->and($approval->comment)->toBe('Imported by '.$this->vendor->name);
});

test('any bad row blocks the whole file set, and errors name the file and line', function () {
    $this->files['units'] = importFile(ImportKind::Units, [
        ['building_code' => 'MT', 'code' => '101', 'use' => 'residential', 'type' => 'flat', 'furnishing' => 'unfurnished', 'list_rent' => '450.000'],
        ['building_code' => 'NOPE', 'code' => '102', 'use' => 'castle', 'type' => 'flat', 'furnishing' => 'unfurnished', 'list_rent' => '1.2345'],
    ]);

    $result = app(RunImport::class)->handle($this->vendor, $this->files, commit: true);

    expect($result->committed)->toBeFalse()
        ->and(array_keys($result->errors['units']))->toBe([3])
        ->and($result->errors['units'][3][0])->toContain('NOPE')
        ->and(Building::count())->toBe(0);
});

test('numbers typed into Text columns are refused', function () {
    $this->files['owners'] = importFile(ImportKind::Owners, [
        ['type' => 'person', 'name_en' => 'Ali Hassan', 'id_type' => 'cpr', 'id_number' => 80101234],
    ]);

    $result = app(RunImport::class)->handle($this->vendor, ['owners' => $this->files['owners']], commit: false);

    expect($result->errors['owners'][2][0])->toContain('formatted as Text');
});

test('a file missing columns is reported on line 1', function () {
    $path = sys_get_temp_dir().'/'.uniqid('bad-').'.xlsx';
    SimpleExcelWriter::create($path)->addRow(['code' => 'MT', 'name' => 'Marina'])->close();

    $result = app(RunImport::class)->handle($this->vendor, ['buildings' => $path], commit: false);

    expect($result->errors['buildings'][1][0])->toContain('Missing columns');
});

test('only import.run holders import, and never after go-live', function () {
    $admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    expect(fn () => app(RunImport::class)->handle($admin, $this->files, commit: false))->toThrow(AuthorizationException::class);

    CompanySetting::current()->forceFill(['go_live_at' => now()->subDay()])->save();
    expect(fn () => app(RunImport::class)->handle($this->vendor, $this->files, commit: false))->toThrow(ValidationException::class, 'imports are closed');
});

test('templates download with the header row', function () {
    $response = $this->actingAs($this->vendor)->get(route('import.template', 'units'))->assertOk()->assertDownload('units-template.xlsx');

    expect(SimpleExcelReader::create($response->getFile()->getPathname())->getHeaders())->toBe(ImportKind::Units->headers());

    $this->actingAs(User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin))->get(route('import.template', 'units'))->assertForbidden();
});

test('the import screen runs a dry run on uploaded files', function () {
    $upload = UploadedFile::fake()->create('owners.xlsx', 5);

    Livewire::actingAs($this->vendor)->test(Index::class)
        ->set('files.owners', $upload)
        // Livewire's test upload stores an empty stand-in, so put the real workbook in its place.
        ->tap(function () {
            $tmp = FileUploadConfiguration::storage();
            $path = collect($tmp->allFiles())->reject(fn ($p) => str_ends_with($p, '.json'))->sole();
            $tmp->put($path, file_get_contents($this->files['owners']));
        })
        ->call('run', false)
        ->assertHasNoErrors()
        ->assertSet('result.counts.owners', 1)
        ->assertSet('result.committed', false);

    expect(Owner::count())->toBe(0);
});

test('a bogus file key is rejected before anything is stored', function () {
    Storage::fake('local');

    Livewire::actingAs($this->vendor)->test(Index::class)
        ->set('files.owners', UploadedFile::fake()->create('owners.xlsx', 5))
        ->set('files.bogus', UploadedFile::fake()->create('x.xlsx', 5))
        ->call('run', false)
        ->assertHasErrors('files');

    expect(Storage::disk('local')->files('imports'))->toBe([]);
});

test('imports need the go-live date: it is the cutover date', function () {
    CompanySetting::current()->forceFill(['go_live_at' => null])->save();

    expect(fn () => app(RunImport::class)->handle($this->vendor, $this->files, commit: false))
        ->toThrow(ValidationException::class, 'Set the go-live date first');
});

test('head-lease payables start with the first period on or after cutover', function () {
    app(RunImport::class)->handle($this->vendor, $this->files, commit: true);

    $leased = OwnerContract::where('type', 'leased')->sole();
    expect($leased->payables()->orderBy('period_start')->pluck('period_start')->map->toDateString()->all())
        ->toBe(['2026-11-01', '2026-12-01']);
});

test('the import screen shows the cutover date', function () {
    Livewire::actingAs($this->vendor)->test(Index::class)->assertSee('Cutover date: 01/11/2026');
});
