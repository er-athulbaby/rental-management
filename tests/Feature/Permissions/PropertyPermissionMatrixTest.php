<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Buildings\SaveBuilding;
use App\Actions\EnsureNumberSequences;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Expenses\ReverseExpense;
use App\Actions\Import\RunImport;
use App\Actions\OwnerContracts\RequestOwnerContractTermination;
use App\Actions\OwnerContracts\SaveOwnerContract;
use App\Actions\OwnerContracts\SubmitOwnerContract;
use App\Actions\Owners\SaveOwner;
use App\Actions\Units\SaveUnit;
use App\Enums\RoleName as R;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Expense;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

// Spec §14 flow 11 for M1: every role against every property/owner page and Action.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    app(EnsureNumberSequences::class)(now('Asia/Bahrain')->year);

    $this->building = Building::factory()->create();
    $this->unit = Unit::factory()->for($this->building)->create();
    $this->owner = Owner::factory()->create();
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(R::Finance);

    $this->draft = OwnerContract::factory()->create(['building_id' => $this->building->id, 'owner_id' => $this->owner->id]);
    $this->draft->units()->attach($this->unit->id);

    $pendingContract = OwnerContract::factory()->create(['building_id' => $this->building->id, 'start_date' => '2030-01-01', 'end_date' => '2030-12-31']);
    $pendingContract->units()->attach($this->unit->id);
    $this->pending = app(SubmitOwnerContract::class)->handle($this->finance, $pendingContract);

    $this->active = activeOwnerContract(['building_id' => $this->building->id, 'start_date' => '2020-01-01', 'end_date' => '2020-12-31'], []);
    $this->expense = Expense::factory()->create(['building_id' => $this->building->id]);
});

test('pages open for exactly the roles the spec allows', function (string $route, array $allowed) {
    foreach (R::cases() as $role) {
        $user = User::factory()->withTwoFactor()->create()->assignRole($role);
        $status = $this->actingAs($user)->get(route($route))->status();

        expect($status)->toBe(in_array($role, $allowed, true) ? 200 : 403, "{$route} as {$role->value}");
    }
})->with([
    ['buildings.index', [R::Admin, R::Management, R::Finance, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['buildings.create', [R::Admin, R::PropertyManager, R::VendorSupport]],
    ['units.index', [R::Admin, R::Management, R::Finance, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['units.create', [R::Admin, R::PropertyManager, R::VendorSupport]],
    ['owners.index', [R::Admin, R::Management, R::Finance, R::PropertyManager, R::VendorSupport]],
    ['owners.create', [R::Admin, R::Finance, R::VendorSupport]],
    ['owner-contracts.index', [R::Admin, R::Management, R::Finance, R::PropertyManager, R::VendorSupport]],
    ['owner-contracts.create', [R::Admin, R::Finance, R::VendorSupport]],
    ['expenses.index', [R::Admin, R::Management, R::Finance, R::PropertyManager, R::VendorSupport]],
    ['expenses.create', [R::Finance, R::PropertyManager]],
    ['approvals.index', [R::Management]],
    ['import.index', [R::VendorSupport]],
]);

test('each protected Action allows exactly the spec roles', function (string $name, Closure $run, array $allowed) {
    foreach (R::cases() as $role) {
        $user = User::factory()->withTwoFactor()->create()->assignRole($role);
        $denied = false;

        try {
            $run->call($this, $user);
        } catch (AuthorizationException) {
            $denied = true;
        } catch (ValidationException) {
            // Authorised; the data was refused (e.g. already decided by an earlier role).
        }

        expect($denied)->toBe(! in_array($role, $allowed, true), "{$name} as {$role->value}");
    }
})->with([
    ['save building', function (User $u) {
        app(SaveBuilding::class)->handle($u, null, ['name' => 'B', 'code' => uniqid(), 'type' => 'residential']);
    }, [R::Admin, R::PropertyManager, R::VendorSupport]],
    ['save unit', function (User $u) {
        app(SaveUnit::class)->handle($u, null, ['building_id' => $this->building->id, 'code' => uniqid(), 'use' => 'residential', 'type' => 'flat', 'furnishing' => 'unfurnished', 'list_rent' => '100']);
    }, [R::Admin, R::PropertyManager, R::VendorSupport]],
    ['save owner', function (User $u) {
        app(SaveOwner::class)->handle($u, null, ['type' => 'person', 'name_en' => 'O', 'id_type' => 'cpr', 'id_number' => uniqid()]);
    }, [R::Admin, R::Finance, R::VendorSupport]],
    ['set owner bank details', function (User $u) {
        app(SaveOwner::class)->handle($u, null, ['type' => 'person', 'name_en' => 'O', 'id_type' => 'cpr', 'id_number' => uniqid(), 'iban' => 'BH67BMAG00001299123456']);
    }, [R::Admin]],
    ['save owner contract draft', function (User $u) {
        app(SaveOwnerContract::class)->handle($u, $this->draft, ['owner_id' => $this->owner->id, 'building_id' => $this->building->id, 'type' => 'managed', 'start_date' => '2031-01-01', 'end_date' => '2031-12-31', 'fee_type' => 'fixed', 'fee_value' => '50', 'deposits_held_by' => 'owner', 'unit_ids' => [$this->unit->id]]);
    }, [R::Admin, R::Finance, R::VendorSupport]],
    ['submit owner contract', function (User $u) {
        app(SubmitOwnerContract::class)->handle($u, $this->draft);
    }, [R::Admin, R::Finance, R::VendorSupport]],
    ['request early termination', function (User $u) {
        app(RequestOwnerContractTermination::class)->handle($u, $this->active, '2020-06-30', 'Sold');
    }, [R::Admin, R::Finance, R::VendorSupport]],
    ['decide approval', function (User $u) {
        app(DecideApproval::class)->handle($u, $this->pending, false, 'No');
    }, [R::Management]],
    ['record expense', function (User $u) {
        app(RecordExpense::class)->handle($u, ['building_id' => $this->building->id, 'category' => 'other', 'description' => 'x', 'expense_date' => now()->toDateString(), 'net' => '1', 'charge_to' => 'company']);
    }, [R::Finance, R::PropertyManager]],
    ['reverse expense', function (User $u) {
        app(ReverseExpense::class)->handle($u, $this->expense, 'x');
    }, [R::Finance]],
    ['run import', function (User $u) {
        app(RunImport::class)->handle($u, [], commit: false);
    }, [R::VendorSupport]],
]);
