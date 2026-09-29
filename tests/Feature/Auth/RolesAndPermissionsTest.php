<?php

use App\Enums\PermissionName as P;
use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function roleHas(RoleName $role, P $permission): bool
{
    return Role::findByName($role->value)->hasPermissionTo($permission->value);
}

test('exactly the 25 spec permissions and 6 roles exist', function () {
    expect(Permission::pluck('name')->sort()->values()->all())->toBe(collect([
        'users.manage', 'roles.manage', 'settings.manage', 'templates.manage', 'audit.view',
        'buildings.view', 'buildings.manage', 'buildings.view-all', 'owners.view', 'owners.manage',
        'owners.bank.manage', 'customers.view', 'customers.manage', 'agreements.view', 'agreements.manage',
        'finance.view', 'invoices.manage', 'payments.manage', 'cheques.manage', 'disbursements.manage',
        'expenses.manage', 'reports.operational', 'reports.financial', 'approvals.decide', 'import.run',
    ])->sort()->values()->all())
        ->and(Role::pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'finance', 'leasing', 'management', 'property-manager', 'vendor-support']);
});

test('seeding twice changes nothing', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::count())->toBe(25)->and(Role::count())->toBe(6);
});

test('Admin cannot post money or approve', function () {
    foreach ([...P::financeManage(), P::ApprovalsDecide] as $permission) {
        expect(roleHas(RoleName::Admin, $permission))->toBeFalse();
    }
});

test('Vendor Support lacks approvals, bank details and finance posting, and is the only role with import', function () {
    foreach ([...P::financeManage(), P::ApprovalsDecide, P::OwnersBankManage] as $permission) {
        expect(roleHas(RoleName::VendorSupport, $permission))->toBeFalse();
    }

    foreach (RoleName::cases() as $role) {
        expect(roleHas($role, P::ImportRun))->toBe($role === RoleName::VendorSupport);
    }
});

test('buildings.view-all goes to every role except Leasing', function () {
    foreach ([RoleName::Admin, RoleName::Management, RoleName::Finance, RoleName::PropertyManager, RoleName::VendorSupport] as $role) {
        expect(roleHas($role, P::BuildingsViewAll))->toBeTrue();
    }

    expect(roleHas(RoleName::Leasing, P::BuildingsViewAll))->toBeFalse();
});

test('only Management decides approvals', function () {
    foreach (RoleName::cases() as $role) {
        expect(roleHas($role, P::ApprovalsDecide))->toBe($role === RoleName::Management);
    }
});

test('users with a sensitive permission require two-factor authentication', function () {
    $finance = User::factory()->create()->assignRole(RoleName::Finance);
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);

    expect($finance->requiresTwoFactor())->toBeTrue()
        ->and($leasing->requiresTwoFactor())->toBeFalse()
        ->and($finance->can(P::InvoicesManage))->toBeTrue()
        ->and($leasing->can(P::InvoicesManage))->toBeFalse();
});

test('isVendorSupport reflects the role', function () {
    expect(User::factory()->create()->assignRole(RoleName::VendorSupport)->isVendorSupport())->toBeTrue()
        ->and(User::factory()->create()->assignRole(RoleName::Admin)->isVendorSupport())->toBeFalse();
});
