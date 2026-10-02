<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\EnsureNumberSequences;
use App\Actions\OwnerContracts\ActivateOwnerContract;
use App\Actions\OwnerContracts\RequestOwnerContractTermination;
use App\Actions\OwnerContracts\SaveOwnerContract;
use App\Actions\OwnerContracts\SubmitOwnerContract;
use App\Enums\RoleName;
use App\Livewire\OwnerContracts\Show;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Owner;
use App\Models\OwnerPayable;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-01-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->building = Building::factory()->create();
    Unit::factory()->for($this->building)->count(2)->create();
    $this->lease = function (array $over = []) {
        $draft = app(SaveOwnerContract::class)->handle($this->finance, null, [
            'owner_id' => Owner::factory()->create()->id, 'building_id' => $this->building->id, 'type' => 'leased',
            'start_date' => '2026-01-01', 'end_date' => '2026-08-31', 'rent_amount' => '3000.000', 'payment_frequency' => 'quarterly',
            'unit_ids' => $this->building->units()->pluck('id')->all(), ...$over,
        ]);
        app(DecideApproval::class)->handle($this->management, app(SubmitOwnerContract::class)->handle($this->finance, $draft), true);

        return $draft->fresh();
    };
});

test('§14 flow 7: activating a leased contract schedules its payments with proration', function () {
    $contract = ($this->lease)();

    $rows = OwnerPayable::where('owner_contract_id', $contract->id)->orderBy('period_start')->get();
    expect($rows->map(fn ($p) => [$p->period_start->toDateString(), $p->period_end->toDateString(), $p->amount, $p->status->value])->all())->toBe([
        ['2026-01-01', '2026-03-31', '3000.000', 'scheduled'],
        ['2026-04-01', '2026-06-30', '3000.000', 'scheduled'],
        ['2026-07-01', '2026-08-31', '2000.000', 'scheduled'],
    ])->and($rows->first()->due_date->toDateString())->toBe('2026-01-01');
});

test('an early termination cancels later payables and replaces the straddling one', function () {
    $contract = ($this->lease)();
    $approval = app(RequestOwnerContractTermination::class)->handle($this->finance, $contract, '2026-05-15', 'Owner sold the building');
    app(DecideApproval::class)->handle($this->management, $approval, true);

    $live = OwnerPayable::where('owner_contract_id', $contract->id)->where('status', 'scheduled')->orderBy('period_start')->get();
    $q2 = OwnerPayable::where('owner_contract_id', $contract->id)->where('period_start', '2026-04-01')->where('status', 'cancelled')->sole();
    expect($live->map(fn ($p) => [$p->period_start->toDateString(), $p->period_end->toDateString(), $p->amount])->all())->toBe([
        ['2026-01-01', '2026-03-31', '3000.000'],
        ['2026-04-01', '2026-05-15', '1493.151'],
    ])->and($q2->replaced_by_payable_id)->toBe($live->last()->id)
        ->and(OwnerPayable::where('owner_contract_id', $contract->id)->where('period_start', '2026-07-01')->sole()->status->value)->toBe('cancelled');
});

test('a successor ends its predecessor the day before and re-cuts its payables', function () {
    $first = ($this->lease)(['end_date' => '2026-12-31']);
    $successor = ($this->lease)(['owner_id' => $first->owner_id, 'previous_contract_id' => $first->id, 'start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'rent_amount' => '3300.000']);

    expect($first->fresh()->end_date->toDateString())->toBe('2026-05-31')
        ->and(OwnerPayable::where('owner_contract_id', $first->id)->where('status', 'scheduled')->orderBy('period_start')->pluck('period_end')->map->toDateString()->all())->toBe(['2026-03-31', '2026-05-31'])
        ->and(OwnerPayable::where('owner_contract_id', $successor->id)->count())->toBe(4);
});

test('managed contracts get no payables; payables are never deleted and their terms never change', function () {
    $contract = ($this->lease)();
    $id = OwnerPayable::where('owner_contract_id', $contract->id)->value('id');

    expect(fn () => DB::table('owner_payables')->where('id', $id)->delete())->toThrow(QueryException::class, 'owner_payables cannot be deleted');
    expect(fn () => DB::table('owner_payables')->where('id', $id)->update(['amount' => '1.000']))->toThrow(QueryException::class, 'owner_payables: terms are frozen');
    expect(fn () => DB::table('owner_payables')->where('id', $id)->update(['status' => 'paid']))->toThrow(QueryException::class); // paid needs its payment out
});

test('the contract page lists the payables', function () {
    $contract = ($this->lease)();

    Livewire::actingAs($this->finance)->test(Show::class, ['contract' => $contract])->assertSee('Head-lease payments')->assertSee('2000.000');
});

test('an imported contract gets payables only from cutover, on its own anchor', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-10 10:00', 'Asia/Bahrain'));
    $draft = app(SaveOwnerContract::class)->handle($this->finance, null, [
        'owner_id' => Owner::factory()->create()->id, 'building_id' => $this->building->id, 'type' => 'leased',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'rent_amount' => '3000.000', 'payment_frequency' => 'quarterly',
        'unit_ids' => $this->building->units()->pluck('id')->all(),
    ]);
    app(SubmitOwnerContract::class)->handle($this->finance, $draft);
    DB::transaction(fn () => app(ActivateOwnerContract::class)->handle($draft, CarbonImmutable::today('Asia/Bahrain')));

    expect(OwnerPayable::where('owner_contract_id', $draft->id)->orderBy('period_start')->pluck('period_start')->map->toDateString()->all())->toBe(['2026-07-01', '2026-10-01']);
});
