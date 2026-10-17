<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Models\Agreement;
use App\Models\Bank;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\ReminderSend;
use App\Models\ReportPackage;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Builder\ReportResult;
use App\Support\Fils;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
