<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Import\RunImport;
use App\Enums\ApprovalStatus;
use App\Enums\ImportKind;
use App\Enums\OwnerContractStatus;
use App\Enums\RoleName;
use App\Livewire\Import\Index;
use App\Models\Agreement;
use App\Models\Bank;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\Document;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Owner;
use App\Models\OwnerCharge;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
    fixtureBanks();
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
        'customers' => importFile(ImportKind::Customers, [
            ['type' => 'individual', 'name_en' => 'Sara Ahmed', 'id_type' => 'cpr', 'id_number' => '090202345', 'mobile' => '+97336000000', 'email' => 'sara@example.com'],
            ['type' => 'company', 'name_en' => 'Gulf Trading WLL', 'id_type' => 'cr', 'id_number' => '12345-1', 'mobile' => '+97317000000', 'contact_person' => 'Omar'],
        ]),
    ];
});

test('a dry run validates every file and saves nothing', function () {
    $result = app(RunImport::class)->handle($this->vendor, $this->files, commit: false);

    expect($result->errors)->toBe([])
        ->and($result->counts)->toBe(['buildings' => 1, 'units' => 2, 'owners' => 1, 'owner_contracts' => 2, 'customers' => 2])
        ->and($result->committed)->toBeFalse()
        ->and(Building::count() + Unit::count() + Owner::count() + OwnerContract::count() + Customer::count())->toBe(0);
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
    $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00', 'Asia/Bahrain'));
    app(RunImport::class)->handle($this->vendor, $this->files, commit: true);

    $leased = OwnerContract::where('type', 'leased')->sole();
    expect($leased->payables()->orderBy('period_start')->pluck('period_start')->map->toDateString()->all())
        ->toBe(['2026-11-01', '2026-12-01']);
});

test('the import screen shows the cutover date', function () {
    Livewire::actingAs($this->vendor)->test(Index::class)->assertSee('Cutover date: 01/11/2026');
});

test('customers import with their IDs kept as text', function () {
    app(RunImport::class)->handle($this->vendor, $this->files, commit: true);

    expect(Customer::where('id_number', '090202345')->value('name_en'))->toBe('Sara Ahmed')->and(Customer::count())->toBe(2);
});

test('a duplicate customer ID or a numeric mobile is reported against its line', function () {
    $files = [...$this->files, 'customers' => importFile(ImportKind::Customers, [
        ['type' => 'individual', 'name_en' => 'A', 'id_type' => 'cpr', 'id_number' => '090202345', 'mobile' => '+97336000000'],
        ['type' => 'individual', 'name_en' => 'B', 'id_type' => 'cpr', 'id_number' => '090202345', 'mobile' => '+97336000001'],
        ['type' => 'individual', 'name_en' => 'C', 'id_type' => 'cpr', 'id_number' => '090202346', 'mobile' => 97336000002],
    ])];

    $result = app(RunImport::class)->handle($this->vendor, $files, commit: true);

    expect($result->committed)->toBeFalse()->and(array_keys($result->errors['customers']))->toBe([3, 4])
        ->and($result->errors['customers'][4][0])->toContain('formatted as Text');
});

/** Three units (102 blocked), the two customers, and one two-unit agreement L-001 on 101 and 103. */
function agreementFiles(array $base, ?array $agreementRows = null): array
{
    return [...$base,
        'units' => importFile(ImportKind::Units, [
            ['building_code' => 'MT', 'code' => '101', 'use' => 'residential', 'type' => 'flat', 'furnishing' => 'unfurnished', 'list_rent' => '450.000', 'blocked' => 'no'],
            ['building_code' => 'MT', 'code' => '102', 'use' => 'residential', 'type' => 'flat', 'furnishing' => 'semi', 'list_rent' => '480.500', 'blocked' => 'yes', 'blocked_reason' => 'Renovation'],
            ['building_code' => 'MT', 'code' => '103', 'use' => 'residential', 'type' => 'flat', 'furnishing' => 'unfurnished', 'list_rent' => '500.000', 'blocked' => 'no'],
        ]),
        'agreements' => importFile(ImportKind::Agreements, $agreementRows ?? [
            ['import_ref' => 'L-001', 'customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'frequency' => 'monthly',
                'building_code' => 'MT', 'unit_code' => '101', 'deposit_amount' => '450.000', 'rent' => '450.000', 'tax_category' => 'exempt'],
            ['import_ref' => 'L-001', 'customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'frequency' => 'monthly',
                'building_code' => 'MT', 'unit_code' => '103', 'deposit_amount' => '500.000', 'rent' => '500.000', 'service_charge' => '20.000', 'tax_category' => 'exempt'],
        ]),
    ];
}

test('rows sharing a reference import as one active agreement, scheduled from cutover, with no deposit invoice', function () {
    Storage::fake('local');
    $result = app(RunImport::class)->handle($this->vendor, agreementFiles($this->files), commit: true);

    expect($result->errors)->toBe([])->and($result->counts['agreements'])->toBe(2);
    $agreement = Agreement::where('import_ref', 'L-001')->sole();
    expect($agreement->status->value)->toBe('active')->and($agreement->number)->not->toBeNull()
        ->and($agreement->agreementUnits()->count())->toBe(2)
        ->and($agreement->approvals()->sole()->comment)->toBe('Imported by '.$this->vendor->name)
        ->and($agreement->invoices()->where('type', 'deposit')->exists())->toBeFalse()
        ->and($agreement->invoices()->where('type', 'rent')->orderBy('period_start')->pluck('period_start')->map->toDateString()->all())->toBe(['2026-11-01', '2026-12-01'])
        ->and(InvoiceLine::whereIn('invoice_id', $agreement->invoices()->pluck('id'))->whereNull('agreement_unit_charge_id')->exists())->toBeFalse();
});

test('an agreement over before cutover, or with an unknown customer, is refused on its first line', function () {
    $row = fn (string $ref, string $id, string $end, string $unit) => ['import_ref' => $ref, 'customer_id_type' => 'cpr', 'customer_id_number' => $id, 'start_date' => '2026-01-01', 'end_date' => $end,
        'frequency' => 'monthly', 'building_code' => 'MT', 'unit_code' => $unit, 'rent' => '450.000', 'tax_category' => 'exempt'];

    $result = app(RunImport::class)->handle($this->vendor, agreementFiles($this->files, [
        $row('L-002', '090202345', '2026-10-31', '101'),
        $row('L-003', '999999999', '2026-12-31', '103'),
    ]), commit: false);

    expect(array_keys($result->errors['agreements']))->toBe([2, 3])
        ->and($result->errors['agreements'][2][0])->toContain('still running at cutover')
        ->and($result->errors['agreements'][3][0])->toContain('No customer');
});

test('an agreement reference can only be imported once', function () {
    Storage::fake('local');
    app(RunImport::class)->handle($this->vendor, agreementFiles($this->files), commit: true);

    $again = app(RunImport::class)->handle($this->vendor, ['agreements' => importFile(ImportKind::Agreements, [
        ['import_ref' => 'L-001', 'customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'frequency' => 'monthly', 'building_code' => 'MT', 'unit_code' => '103', 'rent' => '500.000', 'tax_category' => 'exempt'],
    ])], commit: false);

    expect($again->errors['agreements'][2][0])->toContain('already imported');
});

test('rows of one reference that disagree on the agreement columns are refused on its first line', function () {
    $row = fn (string $unit, string $id) => ['import_ref' => 'L-004', 'customer_id_type' => 'cpr', 'customer_id_number' => $id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'frequency' => 'monthly', 'building_code' => 'MT', 'unit_code' => $unit, 'rent' => '450.000', 'tax_category' => 'exempt'];

    $result = app(RunImport::class)->handle($this->vendor, agreementFiles($this->files, [$row('101', '090202345'), $row('103', '999999999')]), commit: false);

    expect(array_keys($result->errors['agreements']))->toBe([2])
        ->and($result->errors['agreements'][2][0])->toBe('Rows of agreement L-004 disagree on customer_id_number.');

    $blank = app(RunImport::class)->handle($this->vendor, agreementFiles($this->files, [[...$row('101', '090202345'), 'end_date' => '']]), commit: false);
    expect($blank->errors['agreements'][2][0])->toContain('end date field is required');
});

test('an imported agreement has no generated contract: the PDF route is 404 and the page says why', function () {
    Storage::fake('local');
    app(RunImport::class)->handle($this->vendor, agreementFiles($this->files), commit: true);
    $agreement = Agreement::where('import_ref', 'L-001')->sole();

    $this->actingAs($this->vendor)->get(route('agreements.pdf', $agreement))->assertNotFound();
    expect(Document::query()->where('documentable_id', $agreement->id)->exists())->toBeFalse();

    $this->actingAs($this->vendor)->get(route('agreements.show', $agreement))->assertOk()
        ->assertSee('the signed contract is kept outside this system')
        ->assertDontSee(route('agreements.pdf', $agreement));
});

test('opening balances become issued opening invoices and an owner opening charge, with totals to reconcile', function () {
    Storage::fake('local');
    $files = [...agreementFiles($this->files),
        'customer_balances' => importFile(ImportKind::CustomerBalances, [
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'agreement_ref' => 'L-001', 'building_code' => 'MT', 'unit_code' => '101', 'amount' => '120.500'],
            ['customer_id_type' => 'cr', 'customer_id_number' => '12345-1', 'amount' => '80.000', 'description' => 'Old invoice 2025/77'],
        ]),
        'owner_balances' => importFile(ImportKind::OwnerBalances, [
            ['owner_id_type' => 'cpr', 'owner_id_number' => '080101234', 'building_code' => 'MT', 'amount' => '-25.000'],
        ]),
    ];

    $result = app(RunImport::class)->handle($this->vendor, $files, commit: true);

    expect($result->errors)->toBe([])
        ->and($result->totals)->toMatchArray(['customer_balances' => '200.500', 'owner_balances' => '-25.000']);

    $managed = OwnerContract::where('type', 'managed')->sole();
    $sara = Invoice::where('type', 'opening')->where('customer_id', Customer::where('id_number', '090202345')->value('id'))->sole();
    $line = $sara->lines->sole();
    expect([$sara->status->value, $sara->issue_date->toDateString(), $sara->due_date->toDateString(), $sara->total, $sara->number !== null])
        ->toBe(['issued', '2026-11-01', '2026-11-01', '120.500', true])
        ->and([$line->charge_type->value, $line->tax_amount, $line->owner_contract_id])->toBe(['opening_balance', '0.000', $managed->id])
        ->and(Invoice::where('type', 'opening')->whereNull('agreement_id')->value('total'))->toBe('80.000')
        ->and(OwnerCharge::where('owner_contract_id', $managed->id)->where('type', 'opening_balance')->value('amount'))->toBe('-25.000');
});

test('a customer credit, an unknown agreement unit or a second owner opening balance is refused', function () {
    $files = [...agreementFiles($this->files),
        'customer_balances' => importFile(ImportKind::CustomerBalances, [
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'amount' => '-10.000'],
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'agreement_ref' => 'L-001', 'building_code' => 'MT', 'unit_code' => '102', 'amount' => '5.000'],
        ]),
        'owner_balances' => importFile(ImportKind::OwnerBalances, [
            ['owner_id_type' => 'cpr', 'owner_id_number' => '080101234', 'building_code' => 'MT', 'amount' => '10.000'],
            ['owner_id_type' => 'cpr', 'owner_id_number' => '080101234', 'building_code' => 'MT', 'amount' => '10.000'],
        ]),
    ];

    $result = app(RunImport::class)->handle($this->vendor, $files, commit: false);

    expect($result->errors['customer_balances'][2][0])->toContain('enter it as a payment after go-live')
        ->and($result->errors['customer_balances'][3][0])->toContain('not on agreement L-001')
        ->and(array_keys($result->errors['owner_balances']))->toBe([3]);
});

test('the screen lists the totals of a dry run', function () {
    Livewire::actingAs($this->vendor)->test(Index::class)
        ->set('result', ['errors' => [], 'counts' => ['customer_balances' => 2], 'totals' => ['customer_balances' => '200.500'], 'committed' => false, 'commit' => false])
        ->assertSee('Customer balances total: 200.500 BHD');
});

test('a balance naming a unit but no agreement is refused rather than losing the owner attribution', function () {
    $files = [...agreementFiles($this->files),
        'customer_balances' => importFile(ImportKind::CustomerBalances, [
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'building_code' => 'MT', 'unit_code' => '101', 'amount' => '5.000'],
        ]),
    ];

    $result = app(RunImport::class)->handle($this->vendor, $files, commit: false);

    expect($result->errors['customer_balances'][2][0])->toBe('Name the agreement (agreement_ref) for this unit.');
});

test('deposits held become opening movements stamped on the cutover date; held cheques are recorded held', function () {
    Storage::fake('local');
    $files = [...agreementFiles($this->files),
        'deposits_held' => importFile(ImportKind::DepositsHeld, [
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'agreement_ref' => 'L-001', 'building_code' => 'MT', 'unit_code' => '101', 'amount' => '450.000'],
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'agreement_ref' => 'L-001', 'building_code' => 'MT', 'unit_code' => '103', 'amount' => '500.000'],
        ]),
        'cheques' => importFile(ImportKind::Cheques, [
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'agreement_ref' => 'L-001', 'cheque_no' => '000123', 'bank_name' => 'NBB', 'cheque_date' => '01/11/2026', 'amount' => '450.000'],
        ]),
    ];

    $result = app(RunImport::class)->handle($this->vendor, $files, commit: true);

    expect($result->errors)->toBe([])->and($result->totals)->toMatchArray(['deposits_held' => '950.000', 'cheques' => '450.000']);
    $agreement = Agreement::where('import_ref', 'L-001')->sole();
    $byUnit = DepositMovement::where('type', 'opening')->get()->keyBy(fn ($m) => $m->agreementUnit->unit->code);
    expect([$byUnit['101']->amount, $byUnit['101']->owner_contract_id])->toBe(['450.000', OwnerContract::where('type', 'managed')->value('id')])
        ->and($byUnit['103']->owner_contract_id)->toBeNull(); // 103 is owned: no contract covers it

    $cheque = Cheque::sole();
    expect([$cheque->status->value, $cheque->direction->value, $cheque->cheque_no, $cheque->cheque_date->toDateString(), $cheque->agreement_id])
        ->toBe(['held', 'received', '000123', '2026-11-01', $agreement->id]);
});

test('a second deposit for a unit, or a cheque number typed as a number, is refused', function () {
    $files = [...agreementFiles($this->files),
        'deposits_held' => importFile(ImportKind::DepositsHeld, [
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'agreement_ref' => 'L-001', 'building_code' => 'MT', 'unit_code' => '101', 'amount' => '450.000'],
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'agreement_ref' => 'L-001', 'building_code' => 'MT', 'unit_code' => '101', 'amount' => '10.000'],
        ]),
        'cheques' => importFile(ImportKind::Cheques, [
            ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'cheque_no' => 123, 'bank_name' => 'NBB', 'cheque_date' => '2026-11-01', 'amount' => '450.000'],
        ]),
    ];

    $result = app(RunImport::class)->handle($this->vendor, $files, commit: false);

    expect(array_keys($result->errors['deposits_held']))->toBe([3])
        ->and($result->errors['cheques'][2][0])->toContain('formatted as Text');
});

test('opening balances can be imported only once, even in a later run', function () {
    Storage::fake('local');
    $balances = fn () => importFile(ImportKind::CustomerBalances, [['customer_id_type' => 'cr', 'customer_id_number' => '12345-1', 'amount' => '80.000']]);
    expect(app(RunImport::class)->handle($this->vendor, [...agreementFiles($this->files), 'customer_balances' => $balances()], commit: true)->committed)->toBeTrue();

    $again = app(RunImport::class)->handle($this->vendor, ['customer_balances' => $balances()], commit: true);

    expect($again->errors['customer_balances'][2][0])->toBe('Opening balances were already imported; they can be imported only once.')
        ->and(Invoice::where('type', 'opening')->count())->toBe(1);
});

test('a cheque already imported, in an earlier run or earlier in this one, is refused', function () {
    Storage::fake('local');
    $cheque = fn (string $no) => ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'cheque_no' => $no, 'bank_name' => 'NBB', 'cheque_date' => '2026-11-01', 'amount' => '450.000'];
    app(RunImport::class)->handle($this->vendor, [...agreementFiles($this->files), 'cheques' => importFile(ImportKind::Cheques, [$cheque('000123')])], commit: true);

    $again = app(RunImport::class)->handle($this->vendor, ['cheques' => importFile(ImportKind::Cheques, [$cheque('000123'), $cheque('000124'), $cheque('000124')])], commit: false);

    expect(array_keys($again->errors['cheques']))->toBe([2, 4])
        ->and($again->errors['cheques'][2][0])->toBe('Cheque 000123 of NBB was already imported for this customer.');
});

test('a balance naming an agreement but no unit goes to its only unit, and is refused when it has more than one', function () {
    Storage::fake('local');
    $balance = ['customer_balances' => importFile(ImportKind::CustomerBalances, [
        ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'agreement_ref' => 'L-001', 'amount' => '5.000'],
    ])];

    $two = app(RunImport::class)->handle($this->vendor, [...agreementFiles($this->files), ...$balance], commit: false);
    expect($two->errors['customer_balances'][2][0])->toBe('Name the unit (building_code, unit_code): agreement L-001 has more than one.');

    $oneRow = [['import_ref' => 'L-001', 'customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'frequency' => 'monthly',
        'building_code' => 'MT', 'unit_code' => '101', 'rent' => '450.000', 'tax_category' => 'exempt']];
    $one = app(RunImport::class)->handle($this->vendor, [...agreementFiles($this->files, $oneRow), ...$balance], commit: true);
    $line = Invoice::where('type', 'opening')->sole()->lines->sole();
    expect($one->errors)->toBe([])
        ->and([$line->unit_id, $line->owner_contract_id])->toBe([Unit::where('code', '101')->value('id'), OwnerContract::where('type', 'managed')->value('id')]);
});

test('an over-long reference, or an agreement unit that ended before cutover, is refused on its line', function () {
    $row = fn (string $ref, string $unit, ?string $unitEnd = null) => ['import_ref' => $ref, 'customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'frequency' => 'monthly', 'building_code' => 'MT', 'unit_code' => $unit, 'unit_end_date' => $unitEnd, 'rent' => '450.000', 'tax_category' => 'exempt'];

    $result = app(RunImport::class)->handle($this->vendor, agreementFiles($this->files, [
        $row(str_repeat('L', 41), '101'),
        $row('L-005', '103', '2026-10-15'),
    ]), commit: false);

    expect(array_keys($result->errors['agreements']))->toBe([2, 3])
        ->and($result->errors['agreements'][2][0])->toContain('40 characters')
        ->and($result->errors['agreements'][3][0])->toBe('Unit 103 on L-005 ended before cutover: do not import it.');
});

test('an owner balance is refused when the owner has more than one managed contract on the building at cutover', function () {
    $files = [...agreementFiles($this->files),
        'owner_contracts' => importFile(ImportKind::OwnerContracts, array_map(fn (string $unit) => ['owner_id_type' => 'cpr', 'owner_id_number' => '080101234', 'building_code' => 'MT', 'units' => $unit,
            'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'fee_type' => 'percent_collected', 'fee_value' => '7', 'deposits_held_by' => 'company'], ['101', '103'])),
        'owner_balances' => importFile(ImportKind::OwnerBalances, [['owner_id_type' => 'cpr', 'owner_id_number' => '080101234', 'building_code' => 'MT', 'amount' => '10.000']]),
    ];
    unset($files['agreements']);

    $result = app(RunImport::class)->handle($this->vendor, $files, commit: false);

    expect($result->counts['owner_contracts'])->toBe(2)
        ->and($result->errors['owner_balances'][2][0])->toContain('more than one managed contract on MT');
});

test('imported buildings take parking by name and facilities from the list; unknown ones are refused', function () {
    collect(['Lift', 'Gym'])->each(fn ($n) => Facility::create(['name' => $n]));
    $files = ['buildings' => importFile(ImportKind::Buildings, [
        ['code' => 'MT', 'name' => 'Marina Tower', 'type' => 'residential', 'parking' => 'Covered parking', 'facilities' => 'Lift, gym'],
        ['code' => 'ST', 'name' => 'Sea Tower', 'type' => 'residential', 'parking' => 'rooftop', 'facilities' => 'Spa'],
    ])];

    $result = app(RunImport::class)->handle($this->vendor, $files, commit: false);
    expect(array_keys($result->errors['buildings']))->toBe([3])
        ->and(implode(' ', $result->errors['buildings'][3]))->toContain('rooftop');

    app(RunImport::class)->handle($this->vendor, ['buildings' => importFile(ImportKind::Buildings, [
        ['code' => 'MT', 'name' => 'Marina Tower', 'type' => 'residential', 'parking' => 'covered', 'facilities' => 'Lift, Gym'],
    ])], commit: true);
    $building = Building::sole();
    expect($building->parking->value)->toBe('covered')->and($building->facilities()->pluck('name')->all())->toBe(['Gym', 'Lift']);
});

test('imported nationalities and banks match the lists whatever the case; unknown ones are refused', function () {
    Bank::create(['name' => 'Ahli United Bank']);
    $owner = fn (string $id, array $more) => ['type' => 'person', 'name_en' => 'Owner '.$id, 'id_type' => 'cpr', 'id_number' => $id, ...$more];
    $customer = fn (string $id, string $nationality) => ['type' => 'individual', 'name_en' => 'Customer '.$id, 'id_type' => 'cpr', 'id_number' => $id, 'mobile' => '+97336000000', 'nationality' => $nationality];
    $cheque = fn (string $no, string $bank) => ['customer_id_type' => 'cpr', 'customer_id_number' => '090202345', 'cheque_no' => $no, 'bank_name' => $bank, 'cheque_date' => '2026-11-01', 'amount' => '450.000'];
    $files = fn (bool $bad) => [
        'owners' => importFile(ImportKind::Owners, [
            $owner('080101234', ['nationality' => 'bahraini', 'bank_name' => 'AHLI united bank', 'iban' => 'BH67BMAG00001299123456', 'account_name' => 'Owner']),
            ...($bad ? [$owner('080101235', ['nationality' => 'Martian']), $owner('080101236', ['bank_name' => 'Bank of Mars'])] : []),
        ]),
        'customers' => importFile(ImportKind::Customers, [$customer('090202345', 'INDIAN'), ...($bad ? [$customer('090202346', 'Klingon')] : [])]),
        'cheques' => importFile(ImportKind::Cheques, [$cheque('000123', 'nbb'), ...($bad ? [$cheque('000124', 'Bank of Mars')] : [])]),
    ];

    $result = app(RunImport::class)->handle($this->vendor, $files(true), commit: false);
    expect(array_keys($result->errors['owners']))->toBe([3, 4])
        ->and($result->errors['owners'][3][0])->toBe("Nationality 'Martian' is not in the list.")
        ->and($result->errors['owners'][4][0])->toBe("Unknown bank 'Bank of Mars'. Add it under Administration → Banks first.")
        ->and(array_keys($result->errors['customers']))->toBe([3])
        ->and($result->errors['customers'][3][0])->toBe("Nationality 'Klingon' is not in the list.")
        ->and($result->errors['cheques'][3][0])->toBe("Unknown bank 'Bank of Mars'. Add it under Administration → Banks first.");

    $result = app(RunImport::class)->handle($this->vendor, $files(false), commit: true);
    expect($result->errors)->toBe([])
        ->and(Owner::sole()->only(['nationality', 'bank_name']))->toBe(['nationality' => 'Bahraini', 'bank_name' => 'Ahli United Bank'])
        ->and(Customer::sole()->nationality)->toBe('Indian')
        ->and(Cheque::sole()->bank_name)->toBe('NBB');
});
