<?php

use App\Actions\OwnerContracts\SaveOwnerContract;
use App\Enums\OwnerContractStatus;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->building = Building::factory()->create();
    $this->units = Unit::factory()->for($this->building)->count(3)->create();
    $this->owner = Owner::factory()->create();
    $this->managed = [
        'owner_id' => $this->owner->id, 'building_id' => $this->building->id, 'type' => 'managed',
        'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
        'fee_type' => 'percent_collected', 'fee_value' => '7.5', 'expense_approval_limit' => '100',
        'deposits_held_by' => 'company', 'unit_ids' => $this->units->pluck('id')->all(),
    ];
});

test('Finance saves a managed draft with its units', function () {
    $contract = app(SaveOwnerContract::class)->handle($this->finance, null, $this->managed);

    expect($contract->status)->toBe(OwnerContractStatus::Draft)
        ->and($contract->number)->toBeNull()
        ->and($contract->fee_value)->toBe('7.500')
        ->and($contract->rent_amount)->toBeNull()
        ->and($contract->created_by)->toBe($this->finance->id)
        ->and($contract->units()->pluck('units.id')->sort()->values()->all())->toBe($this->units->pluck('id')->sort()->values()->all());
});

test('switching a draft to leased clears the managed terms and the unit list can change', function () {
    $contract = app(SaveOwnerContract::class)->handle($this->finance, null, $this->managed);

    app(SaveOwnerContract::class)->handle($this->finance, $contract, [
        ...$this->managed, 'type' => 'leased', 'rent_amount' => '12000', 'payment_frequency' => 'quarterly',
        'unit_ids' => [$this->units[0]->id],
    ]);

    $contract->refresh();
    expect($contract->fee_type)->toBeNull()
        ->and($contract->deposits_held_by)->toBeNull()
        ->and($contract->rent_amount)->toBe('12000.000')
        ->and($contract->units()->count())->toBe(1);
});

test('units must belong to the building, and at least one is needed', function (array $unitIds) {
    $other = Unit::factory()->create();
    $ids = $unitIds === ['other'] ? [$other->id] : $unitIds;

    expect(fn () => app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->managed, 'unit_ids' => $ids]))
        ->toThrow(ValidationException::class);
})->with([[['other']], [[]]]);

test('percentage fees cannot exceed 100 and leased rent must be positive', function () {
    expect(fn () => app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->managed, 'fee_value' => '101']))
        ->toThrow(ValidationException::class);

    expect(fn () => app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->managed, 'type' => 'leased', 'rent_amount' => '0', 'payment_frequency' => 'monthly']))
        ->toThrow(ValidationException::class);

    // A fixed fee is BHD per month, so it may exceed 100.
    expect(app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->managed, 'fee_type' => 'fixed', 'fee_value' => '250'])->fee_value)->toBe('250.000');
});

test('Management and Property managers view contracts but cannot create them; Leasing cannot view', function () {
    $contract = app(SaveOwnerContract::class)->handle($this->finance, null, $this->managed);

    foreach ([RoleName::Management, RoleName::PropertyManager] as $role) {
        $user = User::factory()->create()->assignRole($role);
        expect($user->can('view', $contract))->toBeTrue()
            ->and($user->can('create', OwnerContract::class))->toBeFalse();
        expect(fn () => app(SaveOwnerContract::class)->handle($user, null, $this->managed))->toThrow(AuthorizationException::class);
    }

    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($this->building->id);
    expect($leasing->can('view', $contract))->toBeFalse();
});

test('only drafts can be edited', function () {
    $active = activeOwnerContract(['owner_id' => $this->owner->id, 'building_id' => $this->building->id], $this->units);

    expect(fn () => app(SaveOwnerContract::class)->handle($this->finance, $active, $this->managed))
        ->toThrow(ValidationException::class, 'Only draft contracts can be edited.');
});

test('a successor must follow an active contract of the same owner and building, and start after it', function () {
    $active = activeOwnerContract([
        'owner_id' => $this->owner->id, 'building_id' => $this->building->id,
        'start_date' => '2025-11-01', 'end_date' => '2026-12-31',
    ], $this->units);

    $successor = app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->managed, 'previous_contract_id' => $active->id]);
    expect($successor->previous_contract_id)->toBe($active->id);

    expect(fn () => app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->managed, 'owner_id' => Owner::factory()->create()->id, 'previous_contract_id' => $active->id]))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->managed, 'start_date' => '2025-10-01', 'previous_contract_id' => $active->id]))
        ->toThrow(ValidationException::class);
});

test('effectiveOn finds the attributed contract covering a date', function () {
    $active = activeOwnerContract([
        'owner_id' => $this->owner->id, 'building_id' => $this->building->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
    ], [$this->units[0]]);
    app(SaveOwnerContract::class)->handle($this->finance, null, $this->managed); // a draft never counts

    expect(OwnerContract::effectiveOn('2026-06-15')->pluck('id')->all())->toBe([$active->id])
        ->and(OwnerContract::effectiveOn(now()->setDate(2026, 12, 31)->setTime(15, 0))->count())->toBe(1)
        ->and(OwnerContract::effectiveOn('2027-01-01')->count())->toBe(0);
});
