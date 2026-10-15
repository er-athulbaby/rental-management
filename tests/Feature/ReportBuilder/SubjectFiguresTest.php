<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Billing\CustomerCredit;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\Invoice;
use App\Models\Unit;
use App\Reports\Builder\Engine;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['code' => 'AAA']);
    $this->management = matrixUser(RoleName::Management, $this->building);
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $this->tenant = Customer::factory()->create(['name_en' => 'Alpha Tenant']);
    $ag = activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31'], [Unit::factory()->for($this->building)->create()]);
    issuedInvoice($this->tenant, [['net' => '400.000', 'au' => $ag->agreementUnits->first()]], '2026-10-01', $ag);
    app(RecordPayment::class)->handle($this->finance, $this->tenant, ['received_on' => '2026-10-10', 'method' => 'cash', 'amount' => '450', 'allocations' => [['invoice_id' => Invoice::where('customer_id', $this->tenant->id)->value('id'), 'amount' => '400']]]);
});

test('tenant outstanding and credit match the app\'s own figures', function () {
    $r = app(Engine::class)->run($this->management, ReportDefinition::fromArray(['subject' => 'tenants', 'columns' => ['name', 'outstanding', 'credit', 'buildings']], $this->management));
    $row = collect($r->rows)->firstWhere('name', 'Alpha Tenant');

    expect($row['credit'])->toBe(Fils::toDecimal(CustomerCredit::fils($this->tenant->id)))->and($row['credit'])->toBe('50.000')
        ->and($row['outstanding'])->toBe('-50.000')->and($row['buildings'])->toBe('AAA');
});

test('money fields on leasing subjects need financial report access', function () {
    $leasing = matrixUser(RoleName::Leasing, $this->building);
    expect(fn () => ReportDefinition::fromArray(['subject' => 'tenants', 'columns' => ['name', 'outstanding']], $leasing))
        ->toThrow(ValidationException::class);
});

test('deposits held equal the deposit movements as at the date', function () {
    $r = app(Engine::class)->run($this->management, ReportDefinition::fromArray(['subject' => 'deposits_held', 'columns' => ['tenant', 'held', 'agreed']], $this->management));
    $row = collect($r->rows)->firstWhere('tenant', 'Alpha Tenant');

    expect($row['agreed'])->toBe('400.000')
        ->and($row['held'])->toBe(Fils::toDecimal(Fils::fromDecimal((string) (DepositMovement::query()->sum('amount') ?: '0'))));
});

test('expenses, payments out and owner statements run for every field', function () {
    foreach (['expenses', 'payments_out', 'owner_statements'] as $key) {
        expect(app(Engine::class)->run($this->management, ReportDefinition::fresh(Registry::get($key), $this->management))->columns)->not->toBeEmpty();
    }
});
