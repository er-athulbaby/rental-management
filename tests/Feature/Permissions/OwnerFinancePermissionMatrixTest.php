<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\EnsureNumberSequences;
use App\Actions\OwnerStatements\DraftOwnerStatements;
use App\Actions\OwnerStatements\SubmitOwnerStatement;
use App\Enums\RoleName as R;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\OwnerCharge;
use App\Models\OwnerPayable;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Spec §14 flow 11 for M4. Every user is assigned the fixture building.

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-04-02 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);

    $this->building = Building::factory()->create();
    [$u1, $u2, $u3] = Unit::factory()->for($this->building)->count(3)->create()->all();
    $leased = activeOwnerContract(['building_id' => $this->building->id, 'type' => 'leased', 'rent_amount' => '50.000', 'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null, 'start_date' => '2026-04-01', 'end_date' => '2027-03-31'], [$u1]);
    $this->payable = (new OwnerPayable)->forceFill(['owner_contract_id' => $leased->id, 'period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'due_date' => '2026-04-01', 'amount' => '50.000', 'status' => 'scheduled']);
    $this->payable->save();
    $managed = activeOwnerContract(['building_id' => $this->building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'fixed', 'fee_value' => '10.000'], [$u2]);
    OwnerCharge::create(['owner_contract_id' => $managed->id, 'type' => 'opening_balance', 'net' => '500.000', 'tax_amount' => '0.000', 'amount' => '500.000', 'posted_at' => '2026-02-15 10:00:00', 'created_by' => $managed->created_by]);
    $other = activeOwnerContract(['building_id' => $this->building->id, 'type' => 'managed', 'start_date' => '2026-02-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'fixed', 'fee_value' => '10.000'], [$u3]);
    app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-02-01'));
    app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-03-01'));
    $finance = matrixUser(R::Finance, $this->building);
    [$this->february, $this->march] = OwnerStatement::where('owner_contract_id', $managed->id)->orderBy('period_start')->get()->all();
    $this->draft = OwnerStatement::where('owner_contract_id', $other->id)->where('period_start', '2026-02-01')->sole(); // a first statement: nothing before it
    app(DecideApproval::class)->handle(matrixUser(R::Management, $this->building), app(SubmitOwnerStatement::class)->handle($finance, $this->february), true);
    $this->pending = app(SubmitOwnerStatement::class)->handle($finance, $this->march);
});

$view = [R::Admin, R::Management, R::Finance, R::VendorSupport];

test('pages open for exactly the roles the spec allows', function (string $route, Closure $params, array $allowed) {
    foreach (R::cases() as $role) {
        $status = $this->actingAs(matrixUser($role, $this->building))->get(route($route, $params->call($this)))->getStatusCode();

        expect($status === 200)->toBe(in_array($role, $allowed, true), "{$route} as {$role->value} gave {$status}");
    }
})->with([
    ['owner-payables.index', fn () => [], $view],
    ['owner-statements.index', fn () => [], $view],
    ['owner-statements.show', fn () => [$this->february], $view],
    ['owner-statements.pdf', fn () => [$this->february], $view],
    ['owner-statements.export', fn () => [$this->february], $view],
    ['reports.building-profitability', fn () => [], $view],
]);

test('each protected Action allows exactly the spec roles', function (string $name, Closure $run, array $allowed) {
    foreach (R::cases() as $role) {
        $user = matrixUser($role, $this->building);
        $rollback = new RuntimeException('matrix rollback');
        try {
            DB::transaction(function () use ($run, $user, $rollback) {
                $run->call($this, $user);
                throw $rollback;
            });
            $outcome = 'no rollback';
        } catch (AuthorizationException) {
            $outcome = 'denied';
        } catch (ValidationException $e) {
            $outcome = 'refused: '.json_encode($e->errors());
        } catch (RuntimeException $e) {
            $outcome = $e === $rollback ? 'ran' : throw $e;
        }

        $ran = $outcome === 'ran';
        expect($ran)->toBe(in_array($role, $allowed, true), "{$name} as {$role->value}: {$outcome}")
            ->and($ran || $outcome === 'denied')->toBeTrue("{$name} as {$role->value}: {$outcome}");
    }
})->with([
    ['head-lease payment', fn (User $u) => app(RecordDisbursement::class)->handle($u, ['purpose' => 'head_lease', 'owner_payable_id' => $this->payable->id, 'amount' => '50.000', 'method' => 'cash', 'paid_on' => '2026-04-02']), [R::Finance]],
    ['remittance', fn (User $u) => app(RecordDisbursement::class)->handle($u, ['purpose' => 'owner_remittance', 'owner_statement_id' => $this->february->id, 'amount' => '1.000', 'method' => 'cash', 'paid_on' => '2026-04-02']), [R::Finance]],
    ['submit statement', fn (User $u) => app(SubmitOwnerStatement::class)->handle($u, $this->draft), [R::Finance]],
    ['finalise statement', fn (User $u) => app(DecideApproval::class)->handle($u, $this->pending, true), [R::Management]],
]);
