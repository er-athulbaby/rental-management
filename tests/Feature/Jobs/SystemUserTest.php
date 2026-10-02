<?php

use App\Actions\Agreements\ExpireAgreements;
use App\Actions\Approvals\DecideApproval;
use App\Actions\EnsureNumberSequences;
use App\Actions\OwnerStatements\DraftOwnerStatements;
use App\Actions\OwnerStatements\SubmitOwnerStatement;
use App\Enums\RoleName;
use App\Livewire\Admin\Users\Index;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositSettlement;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-04-01 02:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
});

test('the System user exists once, cannot sign in and is hidden from user administration', function () {
    $system = User::system();
    $system->forceFill(['password' => Hash::make('secret-password')])->save();

    expect(User::system()->id)->toBe($system->id)
        ->and($system->is_system)->toBeTrue()->and($system->active)->toBeFalse();

    $this->post('/login', ['email' => $system->email, 'password' => 'secret-password'])->assertSessionHasErrors();
    $this->assertGuest();

    $admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    Livewire::actingAs($admin)->test(Index::class)->set('status', 'all')->assertDontSee($system->email);
    expect($admin->can('update', $system))->toBeFalse();
    $this->actingAs($admin)->get(route('admin.users.edit', $system))->assertNotFound();
});

test('nightly settlements and statement drafts are created by the System user, so any manager can approve them', function () {
    $manager = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $building = Building::factory()->create();
    [$u1, $u2] = Unit::factory()->for($building)->count(2)->create()->all();
    $contract = activeOwnerContract(['building_id' => $building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'fixed', 'fee_value' => '10.000', 'created_by' => $manager->id], [$u1, $u2]);
    $agreement = activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2025-04-01', 'end_date' => '2026-03-31'], [$u1]);
    $agreement->agreementUnits()->update(['move_out_date' => '2026-03-15']);

    app(ExpireAgreements::class)();
    app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-03-01'));

    $statement = OwnerStatement::where('owner_contract_id', $contract->id)->sole();
    expect(DepositSettlement::where('agreement_id', $agreement->id)->value('created_by'))->toBe(User::system()->id)
        ->and($statement->created_by)->toBe(User::system()->id);

    // The contract's own creator could not approve a draft attributed to them (M4 ruling 8); now they can.
    app(DecideApproval::class)->handle($manager, app(SubmitOwnerStatement::class)->handle($finance, $statement), true);
    expect($statement->fresh()->status->value)->toBe('finalised');
});

test('one agreement failing does not stop the 02:00 run, and the command reports failure', function () {
    Exceptions::fake();
    $make = fn () => activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2025-04-01', 'end_date' => '2026-03-31'], [Unit::factory()->create()]);
    $bad = $make();
    $good = $make();
    Agreement::updating(fn (Agreement $a) => $a->id === $bad->id ? throw new RuntimeException('boom') : null);

    $expire = app(ExpireAgreements::class);
    expect($expire())->toBe(1)->and($expire->failed)->toBe(1)
        ->and($good->fresh()->status->value)->toBe('expired')->and($bad->fresh()->status->value)->toBe('active');
    Exceptions::assertReported(RuntimeException::class);

    $this->artisan('rms:agreements:expire')->assertExitCode(1);
});
