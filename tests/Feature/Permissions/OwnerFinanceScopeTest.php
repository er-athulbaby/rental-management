<?php

use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\EnsureNumberSequences;
use App\Livewire\OwnerPayables\Index as PayablesIndex;
use App\Livewire\Reports\BuildingProfitabilityReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\OwnerPayable;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

// Spec §8.2: a role without buildings.view-all sees only its assigned buildings, on every M4 page and Action.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-04-02 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);

    [$this->mine, $this->theirs] = Building::factory()->count(2)->create()->all();
    $role = Role::create(['name' => 'Building accountant', 'guard_name' => 'web']);
    $role->givePermissionTo(['finance.view', 'disbursements.manage', 'reports.financial']);
    $this->user = User::factory()->withTwoFactor()->create()->assignRole($role);
    $this->user->buildings()->attach($this->mine->id);

    $managed = activeOwnerContract(['building_id' => $this->theirs->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'fixed', 'fee_value' => '10.000'], [Unit::factory()->for($this->theirs)->create()]);
    $this->statement = (new OwnerStatement)->forceFill(['owner_contract_id' => $managed->id, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'cutoff_at' => '2026-03-31 23:59:59', 'closing_balance' => '100.000', 'status' => 'draft', 'created_by' => $managed->created_by]);
    $this->statement->save();
    DB::table('owner_statements')->where('id', $this->statement->id)->update(['status' => 'pending_approval']);
    DB::table('owner_statements')->where('id', $this->statement->id)->update(['status' => 'finalised', 'number' => 'OS-T-1', 'finalised_at' => now(), 'finalised_by' => $managed->created_by]);

    $leased = activeOwnerContract(['building_id' => $this->theirs->id, 'type' => 'leased', 'rent_amount' => '50.000', 'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null, 'start_date' => '2026-04-01', 'end_date' => '2027-03-31'], [Unit::factory()->for($this->theirs)->create()]);
    $this->payable = (new OwnerPayable)->forceFill(['owner_contract_id' => $leased->id, 'period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'due_date' => '2026-04-01', 'amount' => '50.000', 'status' => 'scheduled']);
    $this->payable->save();
});

test('another building\'s statement pages are refused', function (string $route) {
    $this->actingAs($this->user)->get(route($route, $this->statement))->assertForbidden();
})->with(['owner-statements.show', 'owner-statements.pdf', 'owner-statements.export']);

test('another building\'s payables, statements and figures are not listed', function () {
    $this->actingAs($this->user)->get(route('owner-statements.index'))->assertOk()->assertDontSee('OS-T-1');
    Livewire::actingAs($this->user)->test(PayablesIndex::class)->assertDontSee('50.000');
    Livewire::actingAs($this->user)->test(BuildingProfitabilityReport::class)->assertSee($this->mine->code)->assertDontSee($this->theirs->code);
});

test('paying another building\'s owner is refused', function (array $data) {
    expect(fn () => app(RecordDisbursement::class)->handle($this->user, [...$data, 'method' => 'cash', 'paid_on' => '2026-04-02']))
        ->toThrow(AuthorizationException::class);
})->with([
    'remittance' => [fn () => ['purpose' => 'owner_remittance', 'owner_statement_id' => $this->statement->id, 'amount' => '1.000']],
    'head lease' => [fn () => ['purpose' => 'head_lease', 'owner_payable_id' => $this->payable->id, 'amount' => '50.000']],
]);
