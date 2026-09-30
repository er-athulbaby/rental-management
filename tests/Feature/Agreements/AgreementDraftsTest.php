<?php

use App\Actions\Agreements\DeleteDraftAgreement;
use App\Actions\Agreements\SaveAgreement;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Enums\AgreementStatus;
use App\Enums\RoleName;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->template = app(EnsureDefaultContractTemplate::class)();
    $this->pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    $this->a = Building::factory()->create();
    $this->b = Building::factory()->create();
    $this->flat = Unit::factory()->for($this->a)->create(['list_rent' => '500.000']);
    $this->shop = Unit::factory()->for($this->b)->create(['use' => 'commercial', 'type' => 'shop', 'list_rent' => '900.000']);
    $this->customer = Customer::factory()->create();
    $this->terms = [
        'customer_id' => $this->customer->id, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'frequency' => 'monthly',
        'units' => [
            ['unit_id' => $this->flat->id, 'deposit_amount' => '450', 'charges' => [
                ['type' => 'rent', 'monthly_amount' => '450', 'tax_category' => 'exempt'],
                ['type' => 'service_charge', 'monthly_amount' => '20', 'tax_category' => 'exempt'],
            ]],
            ['unit_id' => $this->shop->id, 'deposit_amount' => '900', 'charges' => [
                ['type' => 'rent', 'monthly_amount' => '850', 'tax_category' => 'standard'],
            ]],
        ],
    ];
});

test('a property manager drafts one agreement over units in two buildings', function () {
    $agreement = app(SaveAgreement::class)->handle($this->pm, null, $this->terms);
    $agreement->load('agreementUnits.charges');

    expect($agreement->status)->toBe(AgreementStatus::Draft)
        ->and($agreement->number)->toBeNull()
        ->and($agreement->grace_days)->toBe(5)
        ->and($agreement->notice_period_days)->toBe(30)
        ->and($agreement->contract_template_id)->toBe($this->template->id)
        ->and($agreement->agreementUnits)->toHaveCount(2)
        ->and($agreement->agreementUnits->firstWhere('unit_id', $this->flat->id)->list_rent)->toBe('500.000')
        ->and($agreement->agreementUnits->firstWhere('unit_id', $this->flat->id)->end_date->toDateString())->toBe('2027-10-31')
        ->and($agreement->monthlyRentFils())->toBe(1_300_000)       // 450 + 850, rent only
        ->and($agreement->listRentFils())->toBe(1_400_000)          // 500 + 900
        ->and($agreement->discountFils())->toBe(100_000)
        ->and($agreement->depositFils())->toBe(1_350_000);
});

test('editing a draft replaces units and charges but keeps the list rent copied when a unit was added', function () {
    $agreement = app(SaveAgreement::class)->handle($this->pm, null, $this->terms);
    $this->flat->update(['list_rent' => '550.000']);

    app(SaveAgreement::class)->handle($this->pm, $agreement, [...$this->terms, 'units' => [
        [...$this->terms['units'][0], 'charges' => [['type' => 'rent', 'monthly_amount' => '470', 'tax_category' => 'exempt']]],
    ]]);

    $agreement->refresh()->load('agreementUnits.charges');
    expect($agreement->agreementUnits)->toHaveCount(1)
        ->and($agreement->agreementUnits[0]->list_rent)->toBe('500.000')
        ->and($agreement->agreementUnits[0]->charges)->toHaveCount(1)
        ->and($agreement->monthlyRentFils())->toBe(470_000);
});

test('each unit needs exactly one positive rent charge and dates inside the agreement', function (array $unit) {
    expect(fn () => app(SaveAgreement::class)->handle($this->pm, null, [...$this->terms, 'units' => [[...$this->terms['units'][0], ...$unit]]]))
        ->toThrow(ValidationException::class);
})->with([
    'no rent' => [['charges' => [['type' => 'service_charge', 'monthly_amount' => '20', 'tax_category' => 'exempt']]]],
    'two rents' => [['charges' => [['type' => 'rent', 'monthly_amount' => '1', 'tax_category' => 'exempt'], ['type' => 'rent', 'monthly_amount' => '2', 'tax_category' => 'exempt']]]],
    'zero rent' => [['charges' => [['type' => 'rent', 'monthly_amount' => '0', 'tax_category' => 'exempt']]]],
    'starts early' => [['start_date' => '2026-10-01']],
    'ends late' => [['end_date' => '2027-11-30']],
]);

test('a unit outside the actor\'s buildings cannot be added', function () {
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($this->a->id);

    expect(fn () => app(SaveAgreement::class)->handle($leasing, null, $this->terms))->toThrow(AuthorizationException::class);

    $own = app(SaveAgreement::class)->handle($leasing, null, [...$this->terms, 'units' => [$this->terms['units'][0]]]);
    expect($own->exists)->toBeTrue();
});

test('only drafts are edited or deleted, and deleting is a soft delete', function () {
    $draft = app(SaveAgreement::class)->handle($this->pm, null, $this->terms);
    app(DeleteDraftAgreement::class)->handle($this->pm, $draft);
    expect(Agreement::withTrashed()->find($draft->id)->trashed())->toBeTrue();

    $active = activeAgreement(['customer_id' => $this->customer->id], [$this->flat]);
    expect(fn () => app(SaveAgreement::class)->handle($this->pm, $active, $this->terms))->toThrow(ValidationException::class, 'Only draft agreements can be edited.');
    expect(fn () => app(DeleteDraftAgreement::class)->handle($this->pm, $active))->toThrow(ValidationException::class);
});

test('Finance and Management view agreements but cannot draft them', function () {
    foreach ([RoleName::Finance, RoleName::Management] as $role) {
        $user = User::factory()->withTwoFactor()->create()->assignRole($role);
        expect(fn () => app(SaveAgreement::class)->handle($user, null, $this->terms))->toThrow(AuthorizationException::class)
            ->and($user->can('view', activeAgreement([], [Unit::factory()->create()])))->toBeTrue();
    }
});
