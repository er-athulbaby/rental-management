<?php

use App\Enums\RoleName;
use App\Livewire\Expenses\Form;
use App\Livewire\Expenses\Show;
use App\Models\Building;
use App\Models\Expense;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->building = Building::factory()->create();
    // The Property Manager role holds expenses.manage, so HTTP requests need 2FA.
    $this->pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
});

test('a property manager records an expense from the form', function () {
    Livewire::actingAs($this->pm)->test(Form::class)
        ->set('form.building_id', $this->building->id)
        ->set('form.description', 'Lift service')
        ->set('form.net', '80')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Expense::sole()->total)->toBe('80.000');
});

test('the reverse form is only offered to Finance', function () {
    $expense = Expense::factory()->create(['building_id' => $this->building->id]);

    Livewire::actingAs($this->pm)->test(Show::class, ['expense' => $expense])->assertDontSee('Reverse expense');

    Livewire::actingAs($this->finance)->test(Show::class, ['expense' => $expense])
        ->assertSee('Reverse expense')
        ->set('reason', 'Entered twice')
        ->call('reverse')
        ->assertHasNoErrors();

    expect($expense->fresh()->status->value)->toBe('reversed');
});

test('who can open the expense pages', function () {
    $admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);

    $this->actingAs($admin)->get(route('expenses.index'))->assertOk();
    $this->actingAs($admin)->get(route('expenses.create'))->assertForbidden();
    $this->actingAs($leasing)->get(route('expenses.index'))->assertForbidden();
    $this->actingAs($this->pm)->get(route('expenses.create'))->assertOk();
});

test('replacing the whole expense form at once does not crash (Livewire sends no key)', function () {
    $page = Livewire::actingAs($this->pm)->test(Form::class);
    $page->set('form', [...$page->get('form'), 'building_id' => $this->building->id])->assertOk();
});
