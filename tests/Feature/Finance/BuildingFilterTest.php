<?php

use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\Cheques\RecordCheques;
use App\Actions\Deposits\CreateDepositSettlement;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Livewire\Agreements\Index as Agreements;
use App\Livewire\Cheques\Index as Cheques;
use App\Livewire\Customers\Index as Tenants;
use App\Livewire\DepositSettlements\Index as Settlements;
use App\Livewire\Disbursements\Index as Disbursements;
use App\Livewire\Invoices\Index as Invoices;
use App\Livewire\Payments\Index as Payments;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);

    // One tenant in each of two buildings, each with a rent invoice, a payment, a cheque and an open settlement.
    foreach (['a' => 'Alia Tower Tenant', 'b' => 'Bayview Tenant'] as $key => $name) {
        $building = Building::factory()->create();
        $customer = Customer::factory()->create(['name_en' => $name]);
        $agreement = activeAgreement(['customer_id' => $customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-03-31'], [Unit::factory()->create(['building_id' => $building->id])]);
        DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($agreement, $this->finance));
        $invoices = Invoice::where('agreement_id', $agreement->id)->orderBy('due_date')->get();
        app(RecordCheques::class)->handle($this->finance, $customer, $agreement, [[
            'cheque_no' => "CHQ-$key", 'bank_name' => 'NBB', 'cheque_date' => $invoices[1]->due_date->toDateString(), 'amount' => '400.000', 'invoice_id' => $invoices[1]->id,
        ]]);
        app(RecordPayment::class)->handle($this->finance, $customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '400.000']);
        DB::transaction(fn () => app(CreateDepositSettlement::class)->handle($agreement, $agreement->agreementUnits()->pluck('id')->all(), $this->finance));
        $this->{$key} = $building;
    }
});

test('each finance list narrows to the chosen building', function (string $component) {
    Livewire::withQueryParams(['tab' => 'all'])->actingAs($this->finance)->test($component) // invoices open on Unpaid; these are scheduled
        ->assertSee('Alia Tower Tenant')->assertSee('Bayview Tenant')
        ->set('building', $this->a->id)
        ->assertSee('Alia Tower Tenant')->assertDontSee('Bayview Tenant')
        ->assertSee($this->b->code); // still offered in the filter
})->with([Invoices::class, Payments::class, Cheques::class, Settlements::class, Agreements::class, Tenants::class]);

test('Payments out accepts the building filter', function () {
    Livewire::actingAs($this->finance)->test(Disbursements::class)->set('building', $this->a->id)->assertOk();
});

test('customers are found by part of the mobile or by a unit code they rent', function () {
    $customer = Customer::where('name_en', 'Alia Tower Tenant')->sole();
    $unit = Unit::where('building_id', $this->a->id)->sole();

    expect(Customer::findFor($this->finance, substr($customer->mobile, -4))->pluck('id'))->toContain($customer->id)
        ->and(Customer::findFor($this->finance, $unit->code)->pluck('id')->all())->toContain($customer->id)
        ->and(Customer::findFor($this->finance, 'A'))->toBeEmpty();
});
