<?php

use App\Audit\Audit;
use App\Enums\RoleName;
use App\Livewire\Admin\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Admin One'])->assignRole(RoleName::Admin);
});

test('only audit.view holders can open it', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleName::Finance))->get(route('admin.audit'))->assertRedirect(route('security.edit'));
    $this->actingAs(User::factory()->create()->assignRole(RoleName::Leasing))->get(route('admin.audit'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.audit'))->assertOk();
    $this->actingAs(User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management))->get(route('admin.audit'))->assertOk();
});

test('it filters by event, user, subject type and date, newest first', function () {
    $other = User::factory()->create(['name' => 'Other Person']);
    $this->travelTo(now()->subDays(3));
    Audit::log('user.deactivated', $other, causer: $this->admin);
    $this->travelBack();
    Audit::log('document.downloaded', causer: $other);

    Livewire::actingAs($this->admin)->test(AuditLog::class)
        ->assertSeeInOrder(['document.downloaded', 'user.deactivated'])
        ->set('event', 'deactivated')
        ->assertSee('user.deactivated')->assertDontSee('document.downloaded')
        ->set('event', '')
        ->set('causerId', $other->id)
        ->assertSee('document.downloaded')->assertDontSee('user.deactivated')
        ->set('causerId', null)
        ->set('subjectType', (new User)->getMorphClass())
        ->assertSee('user.deactivated')->assertDontSee('document.downloaded')
        ->set('subjectType', '')
        ->set('from', now()->subDay()->toDateString())
        ->assertSee('document.downloaded')->assertDontSee('user.deactivated');
});

test('it offers no way to change entries', function () {
    Audit::log('user.deactivated', causer: $this->admin);

    Livewire::actingAs($this->admin)->test(AuditLog::class)
        ->assertDontSee('Delete')->assertDontSee('Edit');
});
