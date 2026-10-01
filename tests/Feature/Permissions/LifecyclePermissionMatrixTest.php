<?php

use App\Actions\Agreements\RecordMoveOut;
use App\Actions\Agreements\RenewAgreement;
use App\Actions\Agreements\SaveAmendment;
use App\Actions\Agreements\SubmitAmendment;
use App\Actions\Approvals\DecideApproval;
use App\Actions\Deposits\CreateDepositSettlement;
use App\Actions\Deposits\SaveSettlementDeductions;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName as R;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Spec §14 flow 11 for M3b. Every user is assigned the fixture building.

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => false]);
    app(EnsureNumberSequences::class)(now('Asia/Bahrain')->year);

    $this->building = Building::factory()->create();
    [$u1, $this->u2, $this->spare] = Unit::factory()->for($this->building)->count(3)->create()->all();
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => now()->subYear()->toDateString(), 'end_date' => now()->addYear()->toDateString()], [$u1, $this->u2]);
    $finance = matrixUser(R::Finance, $this->building);
    $this->payment = app(RecordPayment::class)->handle($finance, $this->customer, ['received_on' => now('Asia/Bahrain')->toDateString(), 'method' => 'cash', 'amount' => '100']);
    $this->refund = app(RecordDisbursement::class)->handle($finance, ['purpose' => 'credit_refund', 'payment_id' => $this->payment->id, 'amount' => '10', 'method' => 'cash', 'paid_on' => now('Asia/Bahrain')->toDateString()]);
    $this->settlement = DB::transaction(fn () => app(CreateDepositSettlement::class)->handle($this->agreement, [$this->agreement->agreementUnits()->value('id')], $finance));
    $leasing = matrixUser(R::Leasing, $this->building);
    $this->amendment = app(SubmitAmendment::class)->handle($leasing, app(SaveAmendment::class)->handle($leasing, $this->agreement, null,
        ['type' => 'terminate', 'effective_date' => now()->addMonth()->toDateString(), 'reason' => 'matrix']));
});

$view = [R::Admin, R::Management, R::Finance, R::VendorSupport];
$lease = [R::Admin, R::PropertyManager, R::Leasing, R::VendorSupport];

test('pages open for exactly the roles the spec allows', function (string $route, Closure $params, array $allowed) {
    foreach (R::cases() as $role) {
        $status = $this->actingAs(matrixUser($role, $this->building))->get(route($route, $params->call($this)))->status();

        expect($status === 200)->toBe(in_array($role, $allowed, true), "{$route} as {$role->value} gave {$status}");
    }
})->with([
    ['disbursements.index', fn () => [], $view],
    ['disbursements.create', fn () => [], [R::Finance]],
    ['disbursements.show', fn () => [$this->refund], $view],
    ['deposit-settlements.index', fn () => [], $view],
    ['deposit-settlements.show', fn () => [$this->settlement], $view],
    ['agreements.amend', fn () => ['agreement' => $this->agreement, 'type' => 'release_unit'], $lease],
]);

test('each protected Action allows exactly the spec roles', function (string $name, Closure $run, array $allowed) {
    foreach (R::cases() as $role) {
        $user = matrixUser($role, $this->building);
        $rollback = new RuntimeException('matrix rollback');
        try {
            // The sentinel rolls the run back so each role starts from the same state.
            DB::transaction(function () use ($run, $user, $rollback) {
                $run->call($this, $user);
                throw $rollback;
            });
            $outcome = 'no rollback';
        } catch (AuthorizationException) {
            $outcome = 'denied';
        } catch (ValidationException $e) {
            $outcome = 'refused: '.json_encode($e->errors()); // an allowed role must run, a denied one must be denied
        } catch (RuntimeException $e) {
            $outcome = $e === $rollback ? 'ran' : throw $e;
        }

        $ran = $outcome === 'ran';
        expect($ran)->toBe(in_array($role, $allowed, true), "{$name} as {$role->value}: {$outcome}")
            ->and($ran || $outcome === 'denied')->toBeTrue("{$name} as {$role->value}: {$outcome}");
    }
})->with([
    ['credit refund', fn (User $u) => app(RecordDisbursement::class)->handle($u, ['purpose' => 'credit_refund', 'payment_id' => $this->payment->id, 'amount' => '1', 'method' => 'cash', 'paid_on' => now('Asia/Bahrain')->toDateString()]), [R::Finance]],
    ['payment out', fn (User $u) => app(RecordDisbursement::class)->handle($u, ['purpose' => 'other', 'payee_type' => 'customer', 'payee_id' => $this->customer->id, 'amount' => '1', 'method' => 'cash', 'reason' => 'x']), [R::Finance]],
    ['payment-out reversal', fn (User $u) => app(RequestDisbursementReversal::class)->handle($u, $this->refund, 'x'), [R::Finance]],
    ['settlement deductions', fn (User $u) => app(SaveSettlementDeductions::class)->handle($u, $this->settlement, []), [R::Finance]],
    ['amendment', fn (User $u) => app(SaveAmendment::class)->handle($u, $this->agreement, null, ['type' => 'add_unit', 'unit_id' => $this->spare->id, 'effective_date' => now()->addMonth()->toDateString(), 'reason' => 'x', 'charges' => [['type' => 'rent', 'monthly_amount' => '1', 'tax_category' => 'exempt']]]), $lease],
    ['move-out', fn (User $u) => app(RecordMoveOut::class)->handle($u, $this->agreement, null, now('Asia/Bahrain')->toDateString(), null, null), $lease],
    ['renewal', fn (User $u) => app(RenewAgreement::class)->handle($u, $this->agreement, [$this->u2->id], now()->addYears(2)->toDateString()), $lease],
    ['decide amendment', fn (User $u) => app(DecideApproval::class)->handle($u, $this->amendment, false, 'matrix'), [R::Management]],
]);
