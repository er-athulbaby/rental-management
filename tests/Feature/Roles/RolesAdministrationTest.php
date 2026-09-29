<?php

use App\Actions\Roles\SyncRolePermissions;
use App\Enums\RoleName;
use App\Livewire\Admin\Roles\Edit;
use App\Models\User;
use App\Notifications\SensitiveAccessGranted;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $this->approver = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
});

test('only roles.manage holders reach the screens', function () {
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);

    $this->actingAs($leasing)->get(route('admin.roles.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.roles.index'))->assertOk()->assertSee('Property manager');
});

test('editing a role saves, audits old and new, and takes effect immediately', function () {
    $leasing = Role::findByName('leasing');
    $member = User::factory()->create()->assignRole(RoleName::Leasing);
    $old = $leasing->permissions->pluck('name')->sort()->values()->all();

    Livewire::actingAs($this->admin)->test(Edit::class, ['role' => $leasing])
        ->set('permissions', [...$old, 'owners.view'])
        ->call('save')
        ->assertHasNoErrors();

    expect($member->fresh()->can('owners.view'))->toBeTrue();
    $row = Activity::query()->where('event', 'role.permissions.changed')->firstOrFail();
    expect($row->attribute_changes['old']['permissions'])->toBe($old)
        ->and($row->attribute_changes['attributes']['permissions'])->toContain('owners.view');
});

test('a role you hold is read-only', function () {
    expect(fn () => app(SyncRolePermissions::class)->handle($this->admin, Role::findByName('admin'), ['users.manage']))
        ->toThrow(AuthorizationException::class);
});

test('the Vendor Support role is read-only', function () {
    expect(fn () => app(SyncRolePermissions::class)->handle($this->admin, Role::findByName('vendor-support'), []))
        ->toThrow(AuthorizationException::class);
});

test('adding a sensitive permission notifies approvers about each holder', function () {
    $member = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing = Role::findByName('leasing');

    app(SyncRolePermissions::class)->handle($this->admin, $leasing, [...$leasing->permissions->pluck('name'), 'payments.manage']);

    Notification::assertSentTo($this->approver, SensitiveAccessGranted::class, fn ($n) => $n->subject->is($member) && $n->permissions === ['payments.manage']);
});
