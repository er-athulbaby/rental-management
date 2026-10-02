<?php

use App\Enums\RoleName as R;
use App\Models\Building;
use App\Models\CompanySetting;
use Database\Seeders\RolesAndPermissionsSeeder;

// Spec §14 flow 11 for M5b, §10: who may open which report.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->building = Building::factory()->create();
});

$operational = [R::Admin, R::Management, R::Finance, R::PropertyManager, R::Leasing, R::VendorSupport];
$financial = [R::Admin, R::Management, R::Finance, R::VendorSupport];

test('report pages open for exactly the roles the spec allows', function (string $route, array $allowed) {
    foreach (R::cases() as $role) {
        $status = $this->actingAs(matrixUser($role, $this->building))->get(route($route))->getStatusCode();

        expect($status === 200)->toBe(in_array($role, $allowed, true), "{$route} as {$role->value} gave {$status}");
    }
})->with([
    ['reports.occupancy', $operational],
    ['reports.expiring', $operational],
    ['reports.overstays', $operational],
    ['reports.approvals', $operational],
    ['reports.id-documents', $operational],
    ['reports.cheques', [R::Admin, R::Management, R::Finance, R::VendorSupport]], // operational + (cheques.manage | finance.view)
    ['reports.outstanding', $financial],
    ['reports.ageing', $financial],
    ['reports.collections', $financial],
    ['reports.deposits', $financial],
    ['reports.vat', $financial],
    ['reports.index', R::cases()],
    ['dashboard', R::cases()],
]);
