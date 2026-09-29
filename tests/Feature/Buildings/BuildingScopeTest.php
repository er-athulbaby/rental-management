<?php

use App\Enums\RoleName;
use App\Models\Building;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    [$this->a, $this->b, $this->c] = Building::factory()->count(3)->create()->all();
});

test('roles with buildings.view-all see every building', function (RoleName $role) {
    $user = User::factory()->create()->assignRole($role);

    expect(Building::visibleTo($user)->count())->toBe(3);
})->with([RoleName::Admin, RoleName::Management, RoleName::Finance, RoleName::PropertyManager, RoleName::VendorSupport]);

test('Leasing sees only assigned buildings', function () {
    $user = User::factory()->create()->assignRole(RoleName::Leasing);
    expect(Building::visibleTo($user)->count())->toBe(0);

    $user->buildings()->attach([$this->a->id, $this->c->id]);

    expect(Building::visibleTo($user)->pluck('id')->sort()->values()->all())->toBe([$this->a->id, $this->c->id]);
});

test('soft-deleted buildings are never visible', function () {
    $this->b->delete();

    expect(Building::visibleTo(User::factory()->create()->assignRole(RoleName::Admin))->count())->toBe(2);
});

test('a leading orWhere cannot escape the scope', function () {
    $user = User::factory()->create()->assignRole(RoleName::Leasing);
    $user->buildings()->attach($this->a->id);

    $ids = Building::query()->where('id', $this->b->id)->orWhere('id', $this->c->id)->visibleTo($user)->pluck('id');

    expect($ids)->toBeEmpty();
});

test('the policy follows permission and scope', function () {
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($this->a->id);
    $manager = User::factory()->create()->assignRole(RoleName::PropertyManager);

    expect($leasing->can('view', $this->a))->toBeTrue()
        ->and($leasing->can('view', $this->b))->toBeFalse()
        ->and($leasing->can('update', $this->a))->toBeFalse()
        ->and($manager->can('update', $this->b))->toBeTrue();
});
