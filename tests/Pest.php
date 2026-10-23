<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\AddWorkOrder;
use App\Actions\Maintenance\LogMaintenanceRequest;
use App\Actions\Payments\RecordPayment;
use App\Billing\OwnerStatementCalculator;
use App\Enums\CustomFieldEntity;
use App\Enums\DocumentCategory;
use App\Enums\RoleName;
use App\Mail\PortalSignInCode;
use App\Models\Agreement;
use App\Models\Bank;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Disbursement;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\MaintenanceRequest;
use App\Models\Owner;
use App\Models\OwnerCharge;
use App\Models\OwnerContract;
use App\Models\OwnerPayable;
use App\Models\OwnerStatement;
use App\Models\PortalAccount;
use App\Models\ReminderSend;
use App\Models\ReportPackage;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Builder\ReportResult;
use App\Support\Fils;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Real commits across two connections. DatabaseTruncation only truncates BEFORE a test,
// so truncate after too, or committed rows leak into RefreshDatabase tests.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->afterEach(fn () => $this->truncateTablesForAllConnections())
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/** An active owner contract with units, walked through the status steps the triggers allow. */
function activeOwnerContract(array $attributes, iterable $units): OwnerContract
{
    $contract = OwnerContract::factory()->create($attributes);
    $contract->units()->attach(collect($units)->map(fn ($unit) => is_int($unit) ? $unit : $unit->id)->all());
    $contract->forceFill(['status' => 'pending_approval'])->save();
    $contract->forceFill(['status' => 'active', 'number' => 'OC-TEST-'.$contract->id])->save();

    return $contract->fresh();
}

/** An active agreement with one rent charge per unit, walked through the status steps the triggers allow. No invoices. */
function activeAgreement(array $attributes, iterable $units, string $rent = '400.000'): Agreement
{
    $agreement = Agreement::factory()->create($attributes);

    foreach ($units as $unit) {
        $au = $agreement->agreementUnits()->create([
            'unit_id' => $unit->id, 'list_rent' => $unit->list_rent, 'deposit_amount' => $rent,
            'start_date' => $agreement->start_date, 'end_date' => $agreement->end_date,
        ]);
        $au->charges()->create(['type' => 'rent', 'monthly_amount' => $rent, 'tax_category' => 'exempt']);
    }

    $agreement->forceFill(['status' => 'pending_approval'])->save();
    $agreement->forceFill(['status' => 'active', 'number' => 'AGR-T-'.$agreement->id, 'verify_token' => Str::random(32)])->save();

    return $agreement->fresh();
}

/**
 * An issued invoice built directly (no schedule). $lines: list of ['net' => '100.000', 'tax' => 'exempt'|'standard'|…,
 * 'type' => 'rent'|'deposit'|…, 'au' => ?AgreementUnit]. Tax is written by IssueInvoice.
 */
function issuedInvoice(Customer $customer, array $lines, string $due = '2026-10-01', ?Agreement $agreement = null): Invoice
{
    $invoice = (new Invoice)->forceFill([
        'type' => $agreement ? 'rent' : 'manual', 'customer_id' => $customer->id, 'agreement_id' => $agreement?->id,
        'issue_date' => $due, 'due_date' => $due, 'status' => 'draft', 'subtotal' => '0.000', 'tax_total' => '0.000', 'total' => '0.000',
    ]);
    $invoice->save();

    $sum = 0;
    foreach ($lines as $line) {
        $au = $line['au'] ?? null;
        $invoice->lines()->create([
            'agreement_unit_id' => $au?->id, 'unit_id' => $au?->unit_id, 'charge_type' => $line['type'] ?? 'rent',
            'description' => $line['description'] ?? 'Line', 'net' => $line['net'], 'tax_category' => $line['tax'] ?? 'exempt',
            'tax_rate' => '0.00', 'tax_amount' => '0.000', 'total' => $line['net'],
        ]);
        $sum += Fils::fromDecimal($line['net']);
    }
    $invoice->forceFill(['subtotal' => Fils::toDecimal($sum), 'total' => Fils::toDecimal($sum)])->save();

    app(IssueInvoice::class)->handle($invoice);

    return $invoice->fresh(['lines']);
}

/** A two-factor user of the role, assigned the building (shared by the permission matrices). */
function matrixUser(RoleName $role, Building $building): User
{
    $user = User::factory()->withTwoFactor()->create()->assignRole($role);
    $user->buildings()->attach($building->id);

    return $user;
}

function Fils_from(string $bhd): int
{
    return Fils::fromDecimal($bhd);
}

function scheduledEvent(string $command): Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, $command))
        ?? throw new RuntimeException("{$command} is not scheduled");
}

/** A report table row's cell text, so digits in codes, names or dates elsewhere on the page can't satisfy an assertion. */
function reportRowText(string $html, string $needle): string
{
    $row = collect(explode('</tr>', $html))->first(fn (string $tr) => str_contains($tr, '<td') && str_contains($tr, $needle));

    return (string) preg_replace('/\s+/', ' ', trim(html_entity_decode(strip_tags((string) $row), ENT_QUOTES)));
}

/** The short bank names the fixtures use, put on the Admin's list of banks. */
function fixtureBanks(): void
{
    foreach (['NBB', 'BBK'] as $name) {
        Bank::query()->firstOrCreate(['name' => $name]);
    }
}

/** Runs a report definition array through the engine as the user (report builder tests). */
function runReport(User $user, array $data): ReportResult
{
    return app(Engine::class)->run($user, ReportDefinition::fromArray($data, $user));
}

/** A package by $by with the items in order (report-package tests). Items: ['kind', 'report'?, 'saved_report_id'?, 'settings'?]. */
function packageWith(User $by, array $items, string $name = 'Monthly pack', string $visibility = 'private'): ReportPackage
{
    $package = ReportPackage::create(['name' => $name, 'visibility' => $visibility, 'created_by' => $by->id, 'updated_by' => $by->id]);
    foreach (array_values($items) as $i => $item) {
        $package->items()->create(['position' => $i + 1, 'kind' => $item['kind'], 'saved_report_id' => $item['saved_report_id'] ?? null,
            'report' => $item['report'] ?? null, 'settings' => $item['settings'] ?? []]);
    }

    return $package;
}

/**
 * Report-package fixture (needs roles seeded, a CompanySetting and a travelled clock): buildings AAA and BBB, Finance
 * on both, one active agreement (rent 400) and one issued invoice per building, payments of 100 (10/09/2026) and 300
 * (05/10/2026), and a leased owner contract over a vacant unit in AAA.
 *
 * @return array{0: Building, 1: Building, 2: User, 3: Owner}
 */
function reportPackageFixture(): array
{
    app(EnsureNumberSequences::class)(2026);
    $a = Building::factory()->create(['code' => 'AAA']);
    $b = Building::factory()->create(['code' => 'BBB']);
    $finance = matrixUser(RoleName::Finance, $a);
    $finance->buildings()->attach($b->id);
    $alpha = Customer::factory()->create(['name_en' => 'Alpha Tenant']);
    $beta = Customer::factory()->create(['name_en' => 'Beta Tenant']);
    $agA = activeAgreement(['customer_id' => $alpha->id, 'start_date' => '2025-01-01', 'end_date' => '2026-11-10'], [Unit::factory()->for($a)->create()]);
    $agB = activeAgreement(['customer_id' => $beta->id, 'start_date' => '2025-01-01', 'end_date' => '2027-08-31'], [Unit::factory()->for($b)->create()]);
    issuedInvoice($alpha, [['net' => '900.000', 'au' => $agA->agreementUnits->first()]], '2026-08-01', $agA);
    issuedInvoice($beta, [['net' => '100.000', 'au' => $agB->agreementUnits->first()]], '2026-09-01', $agB);
    $pay = fn (Customer $c, string $on, string $amount) => app(RecordPayment::class)->handle($finance, $c, ['received_on' => $on, 'method' => 'cash', 'amount' => $amount]);
    $pay($alpha, '2026-10-05', '300.000');
    $pay($alpha, '2026-09-10', '100.000');
    $owner = Owner::factory()->create(['name_en' => 'Leila Owner']);
    activeOwnerContract(['owner_id' => $owner->id, 'building_id' => $a->id, 'type' => 'leased', 'rent_amount' => '25.000', 'payment_frequency' => 'monthly',
        'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null, 'start_date' => '2026-03-01', 'end_date' => '2027-02-28'], [Unit::factory()->for($a)->create()]);

    return [$a, $b, $finance, $owner];
}

/** An Excel file's rows as lists (row 0 is the header), blank rows kept; one sheet by name, or the first. */
function xlsxRows(string $path, ?string $sheet = null): array
{
    $reader = SimpleExcelReader::create($path, 'xlsx')->noHeaderRow()->preserveEmptyRows();
    if ($sheet !== null) {
        $reader->fromSheetName($sheet);
    }

    return $reader->getRows()->map(fn (array $r) => array_values($r))->all();
}

/** Writes a headless sheet (header + rows) as its own file, the way a report's export does. */
function sheetFile(array $sheet): string
{
    $path = sys_get_temp_dir().'/sheet-'.Str::uuid().'.xlsx';
    $writer = SimpleExcelWriter::create($path);
    $writer->addHeader($sheet['columns']);
    foreach ($sheet['rows'] as $row) {
        $writer->addRow($row);
    }
    $writer->close();

    return $path;
}

/** A tenant (Customer attributes) with an active agreement from 01/01/2026 to $end, 5 grace days, on a new unit (reminder tests). */
function reminderTenant(array $attributes, string $end, ?Building $building = null): array
{
    $tenant = Customer::factory()->create($attributes);
    $unit = $building === null ? Unit::factory()->create() : Unit::factory()->for($building)->create();
    $agreement = activeAgreement(['customer_id' => $tenant->id, 'start_date' => '2026-01-01', 'end_date' => $end, 'grace_days' => 5], [$unit]);

    return [$tenant, $agreement];
}

/** A reminder log row for $item (reminder tests): automatic, rule day 1, sent today, its own batch, unless $attributes say otherwise. */
function logReminder(Customer $tenant, string $type, Invoice|Cheque|Agreement $item, array $attributes = []): ReminderSend
{
    return ReminderSend::create([
        'customer_id' => $tenant->id, 'type' => $type, 'item_id' => $item->id,
        'item_type' => match (true) {
            $item instanceof Invoice => 'invoice', $item instanceof Cheque => 'cheque', default => 'agreement'
        },
        'rule_day' => 1, 'sent_on' => now('Asia/Bahrain')->toDateString(), 'channel' => 'email', 'status' => 'sent',
        'batch' => (string) Str::uuid(), ...$attributes,
    ]);
}

/** A vendor (maintenance tests): an active company named "Fix-It Co" doing general work, unless $attributes say otherwise. */
function maintenanceVendor(array $attributes = []): Vendor
{
    return Vendor::create(['name' => 'Fix-It Co', 'type' => 'company', 'trades' => ['general'], 'mobile' => '+973 3300 0000', 'active' => true, ...$attributes]);
}

/** A request logged by $by on $unit (maintenance tests; the tenant is prefilled here as the form will, but the action stores the customer exactly as given): plumbing, normal, entry permitted. Needs this year's number sequences. */
function maintenanceRequest(User $by, Unit $unit, array $data = []): MaintenanceRequest
{
    return app(LogMaintenanceRequest::class)->handle($by, [
        'building_id' => $unit->building_id, 'unit_id' => $unit->id, 'customer_id' => MaintenanceRequest::currentTenantId($unit->id),
        'reported_by_name' => 'Front desk', 'channel' => 'phone', 'category' => 'plumbing', 'priority' => 'normal',
        'description' => 'Kitchen sink leaking', 'entry_permitted' => true, ...$data,
    ]);
}

/** A work order on $request for $vendor, added by $by (maintenance tests). */
function workOrderFor(User $by, MaintenanceRequest $request, Vendor $vendor, array $data = []): WorkOrder
{
    return app(AddWorkOrder::class)->handle($by, $request, ['vendor_id' => $vendor->id, ...$data]);
}

/** A portal account for $holder, a tenant or an owner (portal tests): enabled, English, with the holder's email in lower case, unless $attributes say otherwise. */
function portalAccount(Customer|Owner $holder, array $attributes = []): PortalAccount
{
    $account = (new PortalAccount)->forceFill([
        PortalAccount::column($holder) => $holder->id, 'email' => Str::lower((string) $holder->email), 'locale' => 'en', 'invited_at' => now(), ...$attributes,
    ]);
    $account->save();

    return $account;
}

/** The code in the last sign-in email queued to $email (portal tests; needs Mail::fake()). */
function portalCodeFor(string $email): string
{
    $mail = Mail::queued(PortalSignInCode::class, fn (PortalSignInCode $m) => $m->hasTo($email))->last();
    if ($mail === null) {
        throw new RuntimeException("No sign-in code was queued to {$email}");
    }

    return $mail->code;
}

/** A stored contract PDF on $agreement, as StoreApprovedContract leaves it (portal tests; needs Storage::fake('local')). */
function portalStoredContract(Agreement $agreement): Document
{
    $path = 'documents/2026/10/'.Str::uuid().'.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 contract');
    $document = new Document([
        'category' => DocumentCategory::GeneratedPdf, 'disk' => 'local', 'path' => $path, 'original_name' => $agreement->number.'.pdf',
        'mime' => 'application/pdf', 'size' => 17, 'uploaded_by' => $agreement->created_by,
    ]);
    $document->documentable()->associate($agreement);
    $document->save();

    return $document;
}

/**
 * An owner with one of everything the owner portal shows (owner portal tests). Needs the roles seeded, a company setting,
 * this year's number sequences and today in October 2026 or later. $by is staff who may work in any building (Admin);
 * the owner's two buildings are added to their buildings.
 *  - ":name Tower", managed (fixed fee 0), active 01/01–31/12/2026: unit :code let to "Renter :code" at 400.000 a month
 *    with 400.000 owed, and unit "V:code" vacant.
 *  - ":name Lease", leased at 300.000 a month: unit "L:code" let to "Lessee :code"; October's payable paid, November's due.
 *  - a finalised September statement "OS-:code": opening 0.000, income 500.000, charges 50.000, closing 450.000.
 *  - payouts "DSB-:code-1" (remittance, 450.000, 05/10/2026, reference "TRF-:code") and "DSB-:code-2" (head lease, 300.000, 02/10/2026).
 *  - expenses "Leak repair :code" (50.000 on unit :code, linked to the request's work order) and "Paint touch-up :code" (20.000, reversed).
 *  - a maintenance request on unit :code with one work order.
 *
 * @return array{owner: Owner, account: PortalAccount, tower: Building, lease: Building, managed: OwnerContract, leased: OwnerContract, statement: OwnerStatement, payout: Disbursement, expense: Expense, request: MaintenanceRequest, markers: list<string>}
 */
function portalOwnerFixture(string $name, string $code, User $by): array
{
    $owner = Owner::factory()->create([
        'name_en' => $name, 'name_ar' => 'مالك '.$code, 'email' => Str::lower((string) strtok($name, ' ')).'@owner.test',
        'bank_name' => 'NBB', 'iban' => 'BH29NBOB0000000000'.str_pad($code, 4, '0', STR_PAD_LEFT),
    ]);
    $tower = Building::factory()->create(['name' => "{$name} Tower"]);
    $lease = Building::factory()->create(['name' => "{$name} Lease"]);
    $by->buildings()->syncWithoutDetaching([$tower->id, $lease->id]);

    $let = Unit::factory()->for($tower)->create(['code' => $code]);
    $vacant = Unit::factory()->for($tower)->create(['code' => 'V'.$code]);
    $managed = activeOwnerContract(['owner_id' => $owner->id, 'building_id' => $tower->id, 'type' => 'managed', 'start_date' => '2026-01-01',
        'end_date' => '2026-12-31', 'fee_type' => 'fixed', 'fee_value' => '0.000', 'deposits_held_by' => 'company', 'created_by' => $by->id], [$let, $vacant]);
    $renter = Customer::factory()->create(['name_en' => "Renter {$code}", 'email' => "renter{$code}@tenant.test"]);
    $agreement = activeAgreement(['customer_id' => $renter->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$let]);
    issuedInvoice($renter, [['net' => '400.000', 'au' => $agreement->agreementUnits->first()]], '2026-10-01', $agreement);

    $leasedUnit = Unit::factory()->for($lease)->create(['code' => 'L'.$code]);
    $leased = activeOwnerContract(['owner_id' => $owner->id, 'building_id' => $lease->id, 'type' => 'leased', 'rent_amount' => '300.000',
        'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'expense_approval_limit' => null, 'deposits_held_by' => null,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'created_by' => $by->id], [$leasedUnit]);
    activeAgreement(['customer_id' => Customer::factory()->create(['name_en' => "Lessee {$code}"])->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$leasedUnit]);

    $october = (new OwnerPayable)->forceFill(['owner_contract_id' => $leased->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        'due_date' => '2026-10-01', 'amount' => '300.000', 'status' => 'scheduled']);
    $october->save();
    (new OwnerPayable)->forceFill(['owner_contract_id' => $leased->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30',
        'due_date' => '2026-11-01', 'amount' => '300.000', 'status' => 'scheduled'])->save();
    $paid = fn (array $row) => DB::table('disbursements')->insertGetId([
        'payee_type' => 'owner', 'payee_id' => $owner->id, 'method' => 'bank_transfer', 'status' => 'paid', 'created_by' => $by->id,
        'recorded_by' => $by->id, 'created_at' => now(), 'updated_at' => now(), ...$row,
    ]);
    $headLease = $paid(['number' => "DSB-{$code}-2", 'purpose' => 'head_lease', 'amount' => '300.000', 'reference' => "TRF-{$code}-L",
        'paid_on' => '2026-10-02', 'posted_at' => '2026-10-02 10:00:00', 'source_type' => 'owner_payable', 'source_id' => $october->id]);
    DB::table('owner_payables')->where('id', $october->id)->update(['status' => 'paid', 'disbursement_id' => $headLease]);

    OwnerCharge::create(['owner_contract_id' => $managed->id, 'type' => 'opening_balance', 'net' => '500.000', 'tax_amount' => '0.000',
        'amount' => '500.000', 'posted_at' => '2026-09-05 10:00:00', 'created_by' => $by->id]);
    $expense = Expense::factory()->create(['building_id' => $tower->id, 'unit_id' => $let->id, 'category' => 'maintenance',
        'description' => "Leak repair {$code}", 'expense_date' => '2026-09-10', 'net' => '50.000', 'tax_amount' => '0.000', 'total' => '50.000',
        'charge_to' => 'owner', 'owner_contract_id' => $managed->id, 'owner_approval_note' => "Approved by phone {$code}",
        'posted_at' => '2026-09-10 10:00:00', 'recorded_by' => $by->id]);
    Expense::factory()->create(['building_id' => $tower->id, 'category' => 'cleaning', 'description' => "Paint touch-up {$code}",
        'expense_date' => '2026-09-12', 'net' => '20.000', 'tax_amount' => '0.000', 'total' => '20.000', 'charge_to' => 'owner',
        'owner_contract_id' => $managed->id, 'posted_at' => '2026-09-12 10:00:00', 'status' => 'reversed', 'reversed_at' => '2026-09-13 10:00:00',
        'reversed_by' => $by->id, 'reversal_reason' => "Wrong building {$code}", 'recorded_by' => $by->id]);

    $statement = (new OwnerStatement)->forceFill(['owner_contract_id' => $managed->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'cutoff_at' => '2026-09-30 23:59:59', 'status' => 'draft', 'created_by' => $by->id]);
    $statement->setRelation('contract', $managed);
    OwnerStatementCalculator::apply($statement);
    $statement->save();
    DB::table('owner_statements')->where('id', $statement->id)->update(['status' => 'pending_approval']);
    DB::table('owner_statements')->where('id', $statement->id)->update(['status' => 'finalised', 'number' => "OS-{$code}", 'finalised_at' => now(), 'finalised_by' => $by->id]);
    $payout = $paid(['number' => "DSB-{$code}-1", 'purpose' => 'owner_remittance', 'amount' => '450.000', 'reference' => "TRF-{$code}",
        'paid_on' => '2026-10-05', 'posted_at' => '2026-10-05 10:00:00', 'source_type' => 'owner_statement', 'source_id' => $statement->id]);

    $request = maintenanceRequest($by, $let);
    $workOrder = workOrderFor($by, $request, maintenanceVendor(['name' => "Fix-It {$code}"]));
    DB::table('work_orders')->where('id', $workOrder->id)->update(['status' => 'completed', 'completed_on' => '2026-09-10', 'expense_id' => $expense->id]);

    return [
        'owner' => $owner, 'account' => portalAccount($owner), 'tower' => $tower, 'lease' => $lease, 'managed' => $managed, 'leased' => $leased,
        'statement' => $statement->fresh(), 'payout' => Disbursement::query()->findOrFail($payout), 'expense' => $expense, 'request' => $request,
        'markers' => [$name, "OS-{$code}", "DSB-{$code}-1", "TRF-{$code}", "Leak repair {$code}", "Paint touch-up {$code}", $request->number,
            "Renter {$code}", (string) $managed->number, (string) $leased->number],
    ];
}

/**
 * A custom field (custom field tests), written directly: active, optional, not in the portal, last in its kind, unless
 * $attributes say otherwise. Each choice is an English label or an [English, Arabic] pair, in order.
 */
function customField(string $entity, string $type, string $label, array $attributes = [], array $choices = []): CustomField
{
    $field = (new CustomField)->forceFill([
        'entity' => $entity, 'key' => CustomField::keyFor(CustomFieldEntity::from($entity), $label), 'label_en' => $label, 'type' => $type,
        'position' => (int) CustomField::query()->where('entity', $entity)->max('position') + 1, ...$attributes,
    ]);
    $field->save();
    foreach (array_values($choices) as $i => $choice) {
        [$en, $ar] = is_array($choice) ? $choice : [$choice, null];
        $field->choices()->create(['label_en' => $en, 'label_ar' => $ar, 'position' => $i + 1]);
    }

    return $field->load('choices');
}

/** A stored value of $field on $record (custom field tests), e.g. ['value_text' => 'Bapco'] or ['choice_id' => 3]. */
function customValue(CustomField $field, Model $record, array $columns): CustomFieldValue
{
    $value = (new CustomFieldValue)->forceFill(['custom_field_id' => $field->id, 'entity' => $field->entity, 'entity_id' => $record->getKey(), ...$columns]);
    $value->save();

    return $value;
}
