<?php

use App\Enums\RoleName as R;
use App\Models\Building;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Real commits: the migration drops and creates tables, so this cannot run inside the Feature suite's transaction.
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->building = Building::factory()->create();
    $this->migration = require database_path('migrations/2026_12_20_000100_create_vendors_table.php');
    $this->maintenance = require database_path('migrations/2026_12_20_000200_create_maintenance_tables.php');
    $this->portal = require database_path('migrations/2026_12_22_000100_create_portal_tables.php');
});

// Whatever happened in the test, leave the schema migrated: the vendors table back (roles are emptied first, so
// up() grants nothing; the suite's own truncation clears the rest). Safe when the table already exists.
afterEach(function () {
    DB::setDefaultConnection('migrator');
    if (! Schema::hasTable('vendors')) {
        Role::query()->delete();
        $this->migration->up();
    }
    if (! Schema::hasTable('work_orders')) { // the later migration goes back second, in up order
        $this->maintenance->up();
    }
    if (! Schema::hasTable('portal_accounts')) { // the portal migration last, in up order
        $this->portal->up();
    }
});

test('on an installed system the migration grants the maintenance permissions additively, once, and down() removes them', function () {
    $migration = $this->migration;
    DB::setDefaultConnection('migrator'); // DDL needs the migrator user; each test gets a fresh app, so no reset
    $names = ['maintenance.view', 'maintenance.manage'];
    $held = fn (string $role) => Role::findByName($role)->permissions->pluck('name')->intersect($names)->sort()->values()->all();

    // An install from before this migration: roles exist, the two permissions do not.
    Permission::whereIn('name', $names)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::findByName('admin')->givePermissionTo('users.manage'); // an Admin role edit that must survive
    if (Schema::hasTable('portal_accounts')) {
        $this->portal->down(); // real rollback order: the portal migration alters maintenance_requests, so it goes back first
    }
    if (Schema::hasTable('work_orders')) {
        $this->maintenance->down(); // real rollback order: the later migration first (work_orders has a foreign key to vendors)
    }
    Schema::dropIfExists('vendors');

    $migration->up();
    $expected = [
        'admin' => ['maintenance.manage', 'maintenance.view'], 'management' => ['maintenance.view'],
        'property-manager' => ['maintenance.manage', 'maintenance.view'], 'leasing' => ['maintenance.view'],
        'vendor-support' => ['maintenance.manage', 'maintenance.view'], 'finance' => [],
    ];
    foreach ($expected as $role => $permissions) {
        expect($held($role))->toBe($permissions);
    }
    expect(matrixUser(R::Leasing, $this->building)->can('maintenance.view'))->toBeTrue() // the cache was cleared
        ->and(Role::findByName('admin')->hasPermissionTo('users.manage'))->toBeTrue();

    if (Schema::hasTable('portal_accounts')) {
        $this->portal->down(); // real rollback order: the portal migration alters maintenance_requests, so it goes back first
    }
    if (Schema::hasTable('work_orders')) {
        $this->maintenance->down(); // real rollback order: the later migration first (work_orders has a foreign key to vendors)
    }
    Schema::dropIfExists('vendors');
    $migration->up(); // second run changes nothing
    foreach ($expected as $role => $permissions) {
        expect($held($role))->toBe($permissions);
    }
    expect(Permission::whereIn('name', $names)->count())->toBe(2);

    $migration->down();
    expect(Permission::whereIn('name', $names)->count())->toBe(0)->and(Schema::hasTable('vendors'))->toBeFalse();

    Role::query()->delete(); // no roles: up() recreates the table only, leaving the schema as the other tests expect
    $migration->up();
    expect(Schema::hasTable('vendors'))->toBeTrue();
    $this->maintenance->up(); // back in up order, so the schema is whole for the tests after this one
    expect(Schema::hasTable('work_orders'))->toBeTrue();
    $this->portal->up();
    expect(Schema::hasTable('portal_accounts'))->toBeTrue();
});
