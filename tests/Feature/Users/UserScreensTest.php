<?php

use App\Enums\RoleName;
use App\Livewire\Admin\Users\Form;
use App\Livewire\Admin\Users\Index;
use App\Models\Building;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Admin One'])->assignRole(RoleName::Admin);
});

test('only users.manage holders reach the screens', function () {
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);

    $this->actingAs($leasing)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($leasing)->get(route('admin.users.create'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.users.index'))->assertOk()->assertSee('Admin One');
});

test('the list searches by name or email and filters inactive users', function () {
    User::factory()->create(['name' => 'Sara Ali', 'email' => 'sara@demo.test']);
    User::factory()->inactive()->create(['name' => 'Old Clerk']);

    Livewire::actingAs($this->admin)->test(Index::class)
        ->set('search', 'sara@')
        ->assertSee('Sara Ali')->assertDontSee('Admin One')
        ->set('search', '')
        ->set('status', 'inactive')
        ->assertSee('Old Clerk')->assertDontSee('Sara Ali');
});

test('an admin creates a user with roles and buildings', function () {
    $building = Building::factory()->create();

    Livewire::actingAs($this->admin)->test(Form::class)
        ->set('name', 'Sara Ali')
        ->set('email', 'sara@demo.test')
        ->set('roles', ['leasing'])
        ->set('buildingIds', [$building->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.users.index'));

    $user = User::where('email', 'sara@demo.test')->firstOrFail();
    expect($user->hasRole(RoleName::Leasing))->toBeTrue()->and($user->buildings()->count())->toBe(1);
});

test('the Vendor Support role is never offered', function () {
    Livewire::actingAs($this->admin)->test(Form::class)->assertDontSee('Vendor support');
});

test('changing your own roles shows an error instead of saving', function () {
    Livewire::actingAs($this->admin)->test(Form::class, ['user' => $this->admin])
        ->set('roles', ['admin', 'finance'])
        ->call('save')
        ->assertHasErrors('roles');

    expect($this->admin->fresh()->hasRole(RoleName::Finance))->toBeFalse();
});

test('the Vendor Support account is read-only', function () {
    $vendor = User::factory()->create()->assignRole(RoleName::VendorSupport);

    Livewire::actingAs($this->admin)->test(Form::class, ['user' => $vendor])
        ->assertSee('managed on the server')
        ->assertDontSee('Save')
        ->assertSee('Deactivate');
});

test('actions follow the policy', function () {
    $user = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);

    Livewire::actingAs($this->admin)->test(Form::class, ['user' => $user])
        ->assertSee('Deactivate')->assertSee('Reset 2FA')->assertDontSee('Reactivate')
        ->call('deactivate')
        ->assertSee('Reactivate');

    Livewire::actingAs($this->admin)->test(Form::class, ['user' => $this->admin])
        ->assertDontSee('Deactivate')->assertDontSee('Reset 2FA');
});
