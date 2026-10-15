<?php

use App\Enums\RoleName;
use App\Models\Building;
use App\Models\SavedReport;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $this->other = matrixUser(RoleName::Finance, $this->building);
    $this->admin = matrixUser(RoleName::Admin, $this->building);
});

function savedReport(User $owner, string $visibility = 'private'): SavedReport
{
    return SavedReport::create(['name' => 'Overdue by building', 'subject' => 'invoices', 'definition' => ['version' => 1, 'subject' => 'invoices', 'columns' => ['number']],
        'visibility' => $visibility, 'created_by' => $owner->id, 'updated_by' => $owner->id]);
}

test('a private report is seen and changed only by its creator; an Admin may change any', function () {
    $mine = savedReport($this->finance);

    expect($this->finance->can('view', $mine))->toBeTrue()->and($this->finance->can('update', $mine))->toBeTrue()
        ->and($this->other->can('view', $mine))->toBeFalse()
        ->and($this->admin->can('update', $mine))->toBeTrue()->and($this->admin->can('delete', $mine))->toBeTrue()
        ->and(SavedReport::visibleTo($this->other)->count())->toBe(0);
});

test('a shared report is seen by everyone with report access but changed only by its creator or an Admin', function () {
    $shared = savedReport($this->finance, 'shared');

    expect($this->other->can('view', $shared))->toBeTrue()->and($this->other->can('update', $shared))->toBeFalse()
        ->and(SavedReport::visibleTo($this->other)->pluck('id')->all())->toBe([$shared->id]);

    $leasing = matrixUser(RoleName::Leasing, $this->building); // operational reports only: an invoices report is not theirs to see
    expect($leasing->can('view', $shared))->toBeFalse();
});
